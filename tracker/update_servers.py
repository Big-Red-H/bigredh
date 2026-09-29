"""Hourly: merge the upstream trackers' lists, check each server answers, and write the JSON.

  data/servers.json  every server seen in the last 30 days: name, description, where it was
                     listed, first and last day seen. Changes at most once a day per server, so
                     the hourly commits stay small.
  data/live.json     this hour's check: user counts and which servers answered. Rewritten
                     every run.
"""

import ipaddress
import json
import re
import sys
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from pathlib import Path

import hotline

ROOT = Path(__file__).resolve().parent.parent
CONFIG = ROOT / "config"
DATA = ROOT / "data"

# A server missing from every tracker for this long drops off the list (git history keeps it).
FORGET_AFTER_DAYS = 30


def read_list(name):
    """Config files: one entry per line, # starts a comment."""
    path = CONFIG / name
    if not path.exists():
        return []
    lines = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.split("#", 1)[0].strip()
        if line:
            lines.append(line)
    return lines


def split_address(text, default_port):
    host, _, port = text.partition(":")
    return host.strip(), int(port) if port else default_port


def is_decoration(entry):
    """Trackers list welcome banners and divider lines as if they were servers."""
    try:
        ip = ipaddress.ip_address(entry["ip"])
        if ip.is_private or ip.is_loopback or ip.is_unspecified or ip.is_reserved:
            return True
    except ValueError:
        return True
    return not re.search(r"[0-9A-Za-z]", entry["name"])


def load_json(path, default):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return default


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    text = json.dumps(value, indent=1, ensure_ascii=False, sort_keys=True) + "\n"
    path.write_text(text, encoding="utf-8")


def indexed_servers():
    """Servers that have a file listing: address -> the name it had when indexed."""
    found = {}
    for path in (DATA / "files").glob("*/info.json"):
        info = load_json(path, {})
        if info.get("status") in ("ok", "partial", "opted out") and info.get("server"):
            found[info["server"]] = info.get("name") or ""
    return found


def find_moves(servers, listed_now, indexed):
    """Servers that came back at a new address under exactly the same name, so their file
    listing can follow them instead of being indexed again. Only when it's unambiguous: the old
    address isn't listed any more, no other listed server has that name, exactly one old
    address has a listing under that name, and the new address has no listing of its own."""
    moves = []
    for new in sorted(listed_now):
        if new in indexed or new not in servers:
            continue
        name = servers[new]["name"]
        if sum(1 for k in listed_now if k in servers and servers[k]["name"] == name) != 1:
            continue
        old = [k for k in indexed if k != new and k not in listed_now
               and name in (indexed[k], servers.get(k, {}).get("name"))]
        if len(old) == 1:
            moves.append({"from": old[0], "to": new, "name": name})
    return moves


def main():
    now = datetime.now(timezone.utc)
    today = now.strftime("%Y-%m-%d")
    trackers = [split_address(t, hotline.TRACKER_PORT) for t in read_list("trackers.txt")]
    hidden = set(read_list("hidden.txt"))

    # Every listing of every address, from every tracker that answered.
    listings = defaultdict(list)
    tracker_status = {}

    def ask(tracker):
        host, port = tracker
        try:
            return tracker, hotline.query_tracker(host, port), None
        except Exception as e:  # noqa: BLE001 - any failure just means this tracker is down
            return tracker, [], str(e) or type(e).__name__

    with ThreadPoolExecutor(max_workers=8) as pool:
        for (host, port), entries, error in pool.map(ask, trackers):
            label = host if port == hotline.TRACKER_PORT else f"{host}:{port}"
            tracker_status[label] = {"ok": error is None, "servers": len(entries), "error": error}
            print(f"{label}: {error or str(len(entries)) + ' listed'}")
            for entry in entries:
                if not is_decoration(entry):
                    entry["tracker"] = label
                    listings[f"{entry['ip']}:{entry['port']}"].append(entry)

    for line in read_list("extra-servers.txt"):
        # "host:port Name | Description" for a server that isn't on any tracker.
        address, _, rest = line.partition(" ")
        name, _, desc = rest.partition("|")
        host, port = split_address(address, hotline.SERVER_PORT)
        listings[f"{host}:{port}"].append({
            "ip": host, "port": port, "users": 0, "name": name.strip() or host,
            "description": desc.strip(), "tracker": "bigredh",
        })

    servers = load_json(DATA / "servers.json", {})
    for key, entries in listings.items():
        if key in hidden:
            continue
        # A tracker's own welcome line can share an address with a real server. The real name is
        # the one most trackers agree on; after that, the listing with users in it.
        name_votes = defaultdict(set)
        for e in entries:
            name_votes[e["name"]].add(e["tracker"])
        best = max(entries, key=lambda e: (len(name_votes[e["name"]]), e["users"]))
        record = servers.get(key, {"first_seen": today})
        record.update({
            "host": best["ip"],
            "port": best["port"],
            "name": best["name"],
            "description": best["description"],
            "listed_by": sorted({e["tracker"] for e in entries}),
            "last_seen": today,
        })
        servers[key] = record

    cutoff = (now - timedelta(days=FORGET_AFTER_DAYS)).strftime("%Y-%m-%d")
    servers = {k: v for k, v in servers.items() if v["last_seen"] >= cutoff and k not in hidden}

    listed_now = set(listings) - hidden

    # A server that moved keeps its history here, and its file listing is moved to the new
    # address by apply_moves.py (see data/moves.json).
    moves = find_moves(servers, listed_now, indexed_servers())
    for m in moves:
        old = servers.pop(m["from"], {})
        record = servers[m["to"]]
        record["first_seen"] = min(record["first_seen"], old.get("first_seen", record["first_seen"]))
        record["previous_addresses"] = sorted(set(old.get("previous_addresses", []) + [m["from"]]))
        print(f"{m['name']}: moved from {m['from']} to {m['to']}")
    if moves:
        write_json(DATA / "moves.json", moves)
    else:
        (DATA / "moves.json").unlink(missing_ok=True)

    def check(key):
        s = servers[key]
        return key, hotline.probe(s["host"], s["port"])

    with ThreadPoolExecutor(max_workers=16) as pool:
        online = dict(pool.map(check, sorted(listed_now & set(servers))))

    live = {
        "checked_at": now.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "trackers": tracker_status,
        "servers": {
            key: {
                "listed": True,
                "online": online.get(key, False),
                "users": max(e["users"] for e in listings[key]),
            }
            for key in sorted(listed_now & set(servers))
        },
    }

    if not any(t["ok"] for t in tracker_status.values()):
        # Keep the last good list rather than publishing an empty one.
        print("No tracker answered; leaving the data as it was.", file=sys.stderr)
        return 1

    write_json(DATA / "servers.json", servers)
    write_json(DATA / "live.json", live)
    up = sum(1 for v in live["servers"].values() if v["online"])
    print(f"{len(live['servers'])} listed, {up} answered, {len(servers)} known")
    return 0


if __name__ == "__main__":
    sys.exit(main())
