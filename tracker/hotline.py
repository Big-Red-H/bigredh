"""Just enough of the Hotline protocol to list trackers and walk a server's files as a guest.

Standard library only, so GitHub Actions can run it with no setup. Ported from Invigoration's
HotlineTrackerClient and HotlineTransactionClient, which were checked against live servers.
"""

import socket
import struct
import time

TRACKER_PORT = 5498
SERVER_PORT = 5500

# Transactions and fields used here.
TRAN_REPLY = 0
TRAN_ERROR = 100
TRAN_LOGIN = 107
TRAN_SHOW_AGREEMENT = 109
TRAN_AGREED = 121
TRAN_GET_FILE_NAME_LIST = 200
TRAN_GET_USER_NAME_LIST = 300

FIELD_ERROR_TEXT = 100
FIELD_USER_NAME = 102
FIELD_USER_ICON_ID = 104
FIELD_USER_LOGIN = 105
FIELD_OPTIONS = 113
FIELD_VERSION = 160
FIELD_SERVER_NAME = 162
FIELD_FILE_NAME_WITH_INFO = 200
FIELD_FILE_PATH = 202
FIELD_USER_NAME_WITH_INFO = 300

# A 1.9 client: old enough that every server knows it, new enough to be offered the agreement.
CLIENT_VERSION = 190


class HotlineError(Exception):
    pass


def decode_text(data):
    """Modern servers send UTF-8; classic ones send Mac OS Roman. Try UTF-8 first: Mac Roman
    text with accents is almost never valid UTF-8, so a clean decode means it really was."""
    try:
        return data.decode("utf-8")
    except UnicodeDecodeError:
        return data.decode("mac_roman")


def _recv_exact(sock, count):
    buf = bytearray()
    while len(buf) < count:
        chunk = sock.recv(count - len(buf))
        if not chunk:
            raise HotlineError("connection closed")
        buf += chunk
    return bytes(buf)


# --- Trackers -------------------------------------------------------------------------------

def query_tracker(host, port=TRACKER_PORT, timeout=20):
    """Returns the tracker's list as dicts: ip, port, users, name, description.

    Uses version 1 of the tracker protocol. hltracker.com hangs up on a version 3 request, and
    every tracker speaks version 1.
    """
    with socket.create_connection((host, port), timeout=timeout) as sock:
        sock.sendall(b"HTRK" + struct.pack(">H", 1))
        reply = _recv_exact(sock, 6)
        if reply[:4] != b"HTRK":
            raise HotlineError("not a tracker")

        servers = []
        # The first batch header's count is the total across every batch; each batch header's
        # second count is how many entries follow it. The tracker keeps the connection open when
        # it's done, so stop at the total instead of waiting for the connection to close.
        total = None
        batches = 0
        while (total is None or len(servers) < total) and batches < 200:
            batches += 1
            header = _recv_exact(sock, 8)
            if total is None:
                total = struct.unpack(">H", header[4:6])[0]
            in_batch = struct.unpack(">H", header[6:8])[0]
            for _ in range(in_batch):
                fixed = _recv_exact(sock, 10)
                name = _recv_exact(sock, _recv_exact(sock, 1)[0])
                desc = _recv_exact(sock, _recv_exact(sock, 1)[0])
                servers.append({
                    "ip": socket.inet_ntoa(fixed[:4]),
                    "port": struct.unpack(">H", fixed[4:6])[0],
                    "users": struct.unpack(">H", fixed[6:8])[0],
                    "name": decode_text(name).strip(),
                    "description": decode_text(desc).strip(),
                })
            if in_batch == 0 and total:
                break
        return servers


# --- Servers --------------------------------------------------------------------------------

def _field(ftype, data):
    if isinstance(data, int):
        data = struct.pack(">H", data)
    elif isinstance(data, str):
        data = data.encode("utf-8")
    return struct.pack(">HH", ftype, len(data)) + data


def _obfuscate(text):
    return bytes(~b & 0xFF for b in text.encode("utf-8"))


def encode_path(components):
    """Components are the raw name bytes the server sent, so they go back exactly as received."""
    out = struct.pack(">H", len(components))
    for name in components:
        name = name[:255]
        out += b"\0\0" + bytes([len(name)]) + name
    return out


def parse_file_entry(data):
    """One FileNameWithInfo field: type(4) creator(4) size(4) reserved(4) script(2) len(2) name."""
    if len(data) < 20:
        return None
    name_len = struct.unpack(">H", data[18:20])[0]
    if 20 + name_len > len(data):
        return None
    type_code = data[0:4]
    return {
        "raw_name": data[20:20 + name_len],
        "type": type_code.decode("mac_roman"),
        "creator": data[4:8].decode("mac_roman"),
        # For a folder this is how many items are inside it, not bytes.
        "size": struct.unpack(">I", data[8:12])[0],
        "folder": type_code == b"fldr",
    }


class HotlineServer:
    """A guest session on one server."""

    def __init__(self, host, port=SERVER_PORT, nickname="BigRedH Indexer", icon=128, timeout=30):
        self.host = host
        self.port = port
        self.nickname = nickname
        self.icon = icon
        self.timeout = timeout
        self.sock = None
        self.next_id = 1
        self.server_name = None
        self.agreement_pending = False
        self.agreed = False

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()

    def close(self):
        if self.sock:
            try:
                self.sock.close()
            except OSError:
                pass
            self.sock = None

    def _handshake(self, subversion):
        self.close()
        self.sock = socket.create_connection((self.host, self.port), timeout=self.timeout)
        self.sock.sendall(b"TRTP" + b"HOTL" + struct.pack(">HH", 1, subversion))
        reply = _recv_exact(self.sock, 8)
        return reply[:4] == b"TRTP" and struct.unpack(">I", reply[4:8])[0] == 0

    def handshake(self):
        """Some older servers refuse subversion 2; they want a new connection asking for 1."""
        try:
            if self._handshake(2):
                return
        except (OSError, HotlineError):
            pass
        if not self._handshake(1):
            raise HotlineError("handshake refused")

    def _send(self, ttype, fields):
        tid = self.next_id
        self.next_id += 1
        body = struct.pack(">H", len(fields)) + b"".join(fields)
        header = struct.pack(">BBHIIII", 0, 0, ttype, tid, 0, len(body), len(body))
        self.sock.sendall(header + body)
        return tid

    def _read_transaction(self):
        header = _recv_exact(self.sock, 20)
        _flags, is_reply, ttype, tid, error, total, size = struct.unpack(">BBHIIII", header)
        body = _recv_exact(self.sock, size)
        # A large reply can arrive in several parts, each with its own header.
        while len(body) < total:
            more = _recv_exact(self.sock, 20)
            body += _recv_exact(self.sock, struct.unpack(">I", more[16:20])[0])
        fields = []
        if len(body) >= 2:
            count = struct.unpack(">H", body[:2])[0]
            offset = 2
            for _ in range(count):
                if offset + 4 > len(body):
                    break
                ftype, flen = struct.unpack(">HH", body[offset:offset + 4])
                fields.append((ftype, body[offset + 4:offset + 4 + flen]))
                offset += 4 + flen
        return is_reply, ttype, tid, error, fields

    def _request(self, ttype, fields):
        """Sends a request and waits for its reply, noting anything the server pushes meanwhile.

        A server can ignore a request while it keeps sending chat and user changes, which keeps
        the connection from ever timing out, so the whole wait has its own limit.
        """
        tid = self._send(ttype, fields)
        deadline = time.monotonic() + self.timeout
        while True:
            if time.monotonic() > deadline:
                raise HotlineError("no reply")
            is_reply, rtype, rid, error, rfields = self._read_transaction()
            if is_reply and rid == tid:
                return error, rfields
            if rtype == TRAN_SHOW_AGREEMENT:
                self.agreement_pending = True

    def login(self):
        self.handshake()
        error, fields = self._request(TRAN_LOGIN, [
            _field(FIELD_USER_LOGIN, _obfuscate("")),
            _field(FIELD_USER_ICON_ID, self.icon),
            _field(FIELD_USER_NAME, self.nickname),
            _field(FIELD_VERSION, CLIENT_VERSION),
        ])
        if error:
            text = dict(fields).get(FIELD_ERROR_TEXT, b"")
            raise HotlineError("guest login refused" + (": " + decode_text(text) if text else ""))
        name = dict(fields).get(FIELD_SERVER_NAME)
        if name:
            self.server_name = decode_text(name)
        # The agreement often arrives just after the login reply.
        self.sock.settimeout(2)
        try:
            while not self.agreement_pending:
                _, rtype, _, _, _ = self._read_transaction()
                if rtype == TRAN_SHOW_AGREEMENT:
                    self.agreement_pending = True
        except (socket.timeout, TimeoutError):
            pass
        finally:
            self.sock.settimeout(self.timeout)
        if self.agreement_pending:
            self.agree()

    def agree(self):
        # Agreed has no reply, so it's sent without waiting.
        self._send(TRAN_AGREED, [
            _field(FIELD_USER_NAME, self.nickname),
            _field(FIELD_USER_ICON_ID, self.icon),
            _field(FIELD_OPTIONS, 0),
        ])
        self.agreed = True
        self.agreement_pending = False

    def list_users(self):
        """Who's online, not counting this session. None if the server won't say."""
        error, rfields = self._request(TRAN_GET_USER_NAME_LIST, [])
        if error:
            return None
        users = []
        for ftype, data in rfields:
            if ftype == FIELD_USER_NAME_WITH_INFO:
                user = parse_user(data)
                if user and user["name"] != self.nickname:
                    users.append(user)
        return users

    def list_folder(self, components):
        """Lists one folder (components are raw name bytes; empty for the root). None if refused."""
        fields = [_field(FIELD_FILE_PATH, encode_path(components))] if components else []
        error, rfields = self._request(TRAN_GET_FILE_NAME_LIST, fields)
        if error and not self.agreed:
            # Some servers won't list files until the agreement is accepted, even unasked.
            self.agree()
            error, rfields = self._request(TRAN_GET_FILE_NAME_LIST, fields)
        if error:
            return None
        entries = []
        for ftype, data in rfields:
            if ftype == FIELD_FILE_NAME_WITH_INFO:
                entry = parse_file_entry(data)
                if entry:
                    entries.append(entry)
        return entries


def parse_user(data):
    """One UserNameWithInfo field: user id(2) icon(2) flags(2) name length(2) name."""
    if len(data) < 8:
        return None
    _uid, icon, flags, name_len = struct.unpack(">HHHH", data[:8])
    return {"name": decode_text(data[8:8 + name_len]).strip(), "icon": icon, "flags": flags}


def probe(host, port, timeout=10):
    """True when something answers the Hotline handshake. Doesn't log in."""
    try:
        with socket.create_connection((host, port), timeout=timeout) as sock:
            sock.sendall(b"TRTP" + b"HOTL" + struct.pack(">HH", 1, 2))
            reply = _recv_exact(sock, 8)
            return reply[:4] == b"TRTP"
    except (OSError, HotlineError):
        return False


def now_utc():
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
