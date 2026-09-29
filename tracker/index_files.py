"""Monthly: log into servers as a guest and record every file and folder they show.

  python3 tracker/index_files.py 1.2.3.4:5500 [more...]   index these servers
  python3 tracker/index_files.py --all                    every server listed right now
  python3 tracker/index_files.py --plan                   print the --all list as JSON (for CI)

Each server's listing is written to data/files/<host>_<port>/, as JSON Lines split into parts
small enough for GitHub (one entry per line, sorted, so a month with few changes makes a small
commit). A folder is ["/Path/Folder/", items]; a file is ["/Path/File", bytes, "TYPE", "CREA"].
data/files/<host>_<port>/info.json says when and how it went.

A server that fails keeps last month's listing; only a successful walk replaces it. A server
with a folder named "noindex" anywhere, or listed in config/noindex.txt, has its listing
removed.
"""

import argparse
import json
import shutil
import socket
import sys
import time
from collections import deque
from pathlib import Path

import hotline
from update_servers import DATA, load_json, read_list, split_address, write_json

FILES = DATA / "files"
PART_BYTES = 20 * 1024 * 1024
MAX_ENTRIES = 3_000_000
MAX_DEPTH = 40
# Pause between folder requests, so indexing never looks like more than one busy person.
PAUSE = 0.05
RECONNECTS = 5


class OptedOut(Exception):
    pass


def folder_name(key):
    return key.replace(":", "_")


def walk(host, port, time_limit):
    """Breadth-first walk of everything a guest can see. Returns (entries, notes)."""
    entries = []
    notes = []
    queue = deque([()])  # tuples of raw name bytes
    reconnects = 0
    deadline = time.monotonic() + time_limit
    server = None
    try:
        while queue:
            if time.monotonic() > deadline:
                notes.append("stopped at the time limit; the listing is partial")
                break
            if len(entries) >= MAX_ENTRIES:
                notes.append("stopped at the entry limit; the listing is partial")
                break
            path = queue[0]
            try:
                if server is None:
                    server = hotline.HotlineServer(host, port)
                    server.login()
                listing = server.list_folder(list(path))
            except (OSError, hotline.HotlineError) as e:
                if server:
                    server.close()
                server = None
                reconnects += 1
                if reconnects > RECONNECTS or not entries and isinstance(e, hotline.HotlineError):
                    raise
                time.sleep(5 * reconnects)
                continue
            queue.popleft()
            if listing is None:
                continue
            prefix = "/" + "".join(hotline.decode_text(p) + "/" for p in path)
            for item in listing:
                name = hotline.decode_text(item["raw_name"])
                if item["folder"] and name.strip().lower() == "noindex":
                    raise OptedOut(prefix + name + "/")
                if item["folder"]:
                    entries.append([prefix + name + "/", item["size"]])
                    if len(path) + 1 < MAX_DEPTH:
                        queue.append(path + (item["raw_name"],))
                else:
                    entries.append([prefix + name, item["size"], item["type"], item["creator"]])
            time.sleep(PAUSE)
    finally:
        if server:
            server.close()
    return entries, notes


def save(key, entries, info):
    target = FILES / folder_name(key)
    if target.exists():
        shutil.rmtree(target)
    target.mkdir(parents=True)
    entries.sort(key=lambda e: e[0])
    part, size, out = 1, 0, None
    for entry in entries:
        line = json.dumps(entry, ensure_ascii=False, separators=(",", ":")) + "\n"
        if out is None or size + len(line.encode("utf-8")) > PART_BYTES:
            if out:
                out.close()
            out = open(target / f"part-{part:03}.jsonl", "w", encoding="utf-8")
            part, size = part + 1, 0
        out.write(line)
        size += len(line.encode("utf-8"))
    if out:
        out.close()
    write_json(target / "info.json", info)


def index_server(key, name, time_limit):
    host, port = split_address(key, hotline.SERVER_PORT)
    target = FILES / folder_name(key)
    started = time.monotonic()
    info = {"server": key, "name": name, "indexed_at": hotline.now_utc()}
    try:
        entries, notes = walk(host, port, time_limit)
    except OptedOut as e:
        if target.exists():
            shutil.rmtree(target)
        write_json(target / "info.json", {**info, "status": "opted out", "note": f"has {e}"})
        print(f"{key}: opted out ({e})")
        return
    except (OSError, hotline.HotlineError, socket.timeout) as e:
        previous = load_json(target / "info.json", {})
        previous.update({"last_attempt": info["indexed_at"], "last_error": str(e) or type(e).__name__})
        previous.setdefault("server", key)
        previous.setdefault("name", name)
        previous.setdefault("status", "failed")
        write_json(target / "info.json", previous)
        print(f"{key}: failed ({previous['last_error']}); kept the previous listing")
        return
    files = sum(1 for e in entries if len(e) == 4)
    info.update({
        "status": "partial" if notes else "ok",
        "files": files,
        "folders": len(entries) - files,
        "bytes": sum(e[1] for e in entries if len(e) == 4),
        "seconds": round(time.monotonic() - started),
    })
    if notes:
        info["note"] = "; ".join(notes)
    save(key, entries, info)
    print(f"{key}: {files} files, {info['folders']} folders in {info['seconds']}s {info.get('note', '')}")


def planned_servers():
    """Servers listed right now that answered, minus the ones that asked not to be indexed."""
    live = load_json(DATA / "live.json", {}).get("servers", {})
    skip = set(read_list("noindex.txt"))
    return [k for k, v in sorted(live.items()) if v.get("listed") and k not in skip]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("servers", nargs="*")
    parser.add_argument("--all", action="store_true")
    parser.add_argument("--plan", action="store_true")
    parser.add_argument("--time-limit", type=int, default=5 * 3600, help="seconds per server")
    args = parser.parse_args()

    if args.plan:
        print(json.dumps(planned_servers()))
        return 0

    for key in set(read_list("noindex.txt")):
        target = FILES / folder_name(key)
        if target.exists():
            shutil.rmtree(target)

    names = load_json(DATA / "servers.json", {})
    keys = planned_servers() if args.all else args.servers
    for key in keys:
        if key in set(read_list("noindex.txt")):
            print(f"{key}: in config/noindex.txt; skipped")
            continue
        index_server(key, names.get(key, {}).get("name", key), args.time_limit)
    return 0


if __name__ == "__main__":
    sys.exit(main())
