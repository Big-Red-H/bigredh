"""Monthly: log into servers as a guest and record every file and folder they show.

  python3 tracker/index_files.py 1.2.3.4:5500 [more...]   index these servers
  python3 tracker/index_files.py --due                    listed servers that are due (see below)
  python3 tracker/index_files.py --all                    every listed server
  python3 tracker/index_files.py --plan [--all] [servers] print that list as JSON (for CI), with
                                                          the servers on one machine grouped together

A server is due when it has never been indexed, when its last full index is more than 45 days old,
or when a pass over it is still unfinished. With a run each month, that's about every 60 days.
Servers in config/archives.txt never change, so once they have a complete listing they're left
alone unless named explicitly.

Gentle on purpose: at most one folder request a second, longer if the server is slow to answer,
and a long wait before reconnecting after an error. A big server can take longer than one run
allows; then the run saves where it stopped (data/files/<host>_<port>/next/) and the next run
carries on from there, while the previous listing stays up until the new one is complete.

Each server's listing is written to data/files/<host>_<port>/, as JSON Lines split into parts
small enough for GitHub (one entry per line, sorted, so a month with few changes makes a small
commit). A folder is ["/Path/Folder/", items]; a file is ["/Path/File", bytes, "TYPE", "CREA"].
data/files/<host>_<port>/info.json says when and how it went.

A server that fails keeps its previous listing. A server with a folder named "noindex" anywhere,
or listed in config/noindex.txt, has its listing removed.

With --out DIR, results are written there (one folder per server) for merge_results.py, instead
of into data/files directly; that's how CI runs it.
"""

import argparse
import json
import shutil
import socket
import sys
import time
from collections import deque
from datetime import datetime, timedelta, timezone
from pathlib import Path

import hotline
from update_servers import DATA, load_json, read_list, split_address, write_json

FILES = DATA / "files"
PART_BYTES = 20 * 1024 * 1024
MAX_ENTRIES = 3_000_000
MAX_DEPTH = 40
REINDEX_AFTER_DAYS = 45

# Pacing, so the indexer never weighs on a server: at most one folder request a second, and if
# the server takes a while to answer, wait SLOW_FACTOR times as long as it took.
MIN_INTERVAL = 1.0
SLOW_FACTOR = 3.0
RECONNECT_WAIT = 30
RECONNECTS = 6


class OptedOut(Exception):
    pass


def folder_name(key):
    return key.replace(":", "_")


def encode_queue(queue):
    return [[part.hex() for part in path] for path in queue]


def decode_queue(saved):
    return deque(tuple(bytes.fromhex(part) for part in path) for path in saved)


def walk(host, port, time_limit, queue):
    """Breadth-first walk of everything a guest can see, from the folders in queue. Returns
    (entries, queue left, folders done, folders skipped, why it stopped early or None)."""
    entries = []
    reconnects = 0
    tries_here = 0
    skipped = 0
    folders_done = 0
    stopped = None
    deadline = time.monotonic() + time_limit
    server = None
    try:
        while queue:
            if time.monotonic() > deadline:
                stopped = "time"
                break
            if len(entries) >= MAX_ENTRIES:
                stopped = "entry limit"
                break
            path = queue[0]
            asked = time.monotonic()
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
                tries_here += 1
                refused = isinstance(e, hotline.HotlineError) and "refused" in str(e)
                if reconnects > RECONNECTS or refused:
                    if not entries and not folders_done:
                        raise
                    # Keep what this run found; the next run carries on from here.
                    stopped = f"stopped early: {e}"
                    break
                if tries_here > 2 and path:
                    # One folder that never answers shouldn't cost the rest of the server.
                    queue.popleft()
                    skipped += 1
                    tries_here = 0
                time.sleep(RECONNECT_WAIT * min(tries_here + 1, 4))
                continue
            took = time.monotonic() - asked
            queue.popleft()
            tries_here = 0
            folders_done += 1
            if folders_done % 500 == 0:
                print(f"  {host}:{port}: {folders_done} folders, {len(entries)} entries, {len(queue)} to go", flush=True)
            if listing is not None:
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
            time.sleep(max(MIN_INTERVAL - took, SLOW_FACTOR * took))
    finally:
        if server:
            server.close()
    return entries, queue, folders_done, skipped, stopped


def write_parts(folder, entries, prefix="part"):
    folder.mkdir(parents=True, exist_ok=True)
    entries.sort(key=lambda e: e[0])
    n, size, out = 1, 0, None
    for entry in entries:
        line = json.dumps(entry, ensure_ascii=False, separators=(",", ":")) + "\n"
        if out is None or size + len(line.encode("utf-8")) > PART_BYTES:
            if out:
                out.close()
            out = open(folder / f"{prefix}-{n:03}.jsonl", "w", encoding="utf-8")
            n, size = n + 1, 0
        out.write(line)
        size += len(line.encode("utf-8"))
    if out:
        out.close()


def read_entries(folder, pattern):
    entries = []
    for part in sorted(folder.glob(pattern)) if folder.exists() else []:
        with open(part, encoding="utf-8") as f:
            entries += [json.loads(line) for line in f]
    return entries


def index_server(key, name, time_limit, out_root):
    """Indexes one server, or carries on with an unfinished pass, writing to out_root/<folder>."""
    host, port = split_address(key, hotline.SERVER_PORT)
    current = FILES / folder_name(key)
    out = out_root / folder_name(key)
    old_info = load_json(current / "info.json", {})
    pass_dir = current / "next"
    saved = load_json(pass_dir / "queue.json", {})
    resuming = bool(saved.get("queue"))
    queue = decode_queue(saved["queue"]) if resuming else deque([()])
    started = time.monotonic()
    now = hotline.now_utc()
    try:
        entries, queue, done, skipped, stopped = walk(host, port, time_limit, queue)
    except OptedOut as e:
        if out.exists():
            shutil.rmtree(out)
        write_json(out / "info.json", {"server": key, "name": name, "indexed_at": now,
                                       "status": "opted out", "note": f"has {e}"})
        print(f"{key}: opted out ({e})")
        return
    except (OSError, hotline.HotlineError, socket.timeout) as e:
        info = dict(old_info)
        info.update({"server": key, "name": info.get("name") or name,
                     "last_attempt": now, "last_error": str(e) or type(e).__name__})
        info.setdefault("status", "failed")
        write_json(out / "info.json", info)
        print(f"{key}: failed ({info['last_error']}); kept the previous listing")
        return

    seconds = round(time.monotonic() - started) + saved.get("seconds", 0)
    folders_done = saved.get("folders_done", 0) + done
    skipped += saved.get("skipped", 0)
    earlier = read_entries(pass_dir, "found-*.jsonl") if resuming else []

    if queue and stopped != "entry limit":
        # Not finished: save this run's findings and where to carry on. The previous listing
        # (if any) stays up until the pass is complete.
        if out.exists():
            shutil.rmtree(out)
        out_pass = out / "next"
        write_parts(out_pass, earlier + entries, prefix="found")
        write_json(out_pass / "queue.json", {
            "started": saved.get("started", now), "queue": encode_queue(queue),
            "folders_done": folders_done, "skipped": skipped, "seconds": seconds,
        })
        info = dict(old_info) if old_info.get("status") in ("ok", "partial") else {"status": "in progress"}
        info.update({"server": key, "name": info.get("name") or name, "pass": {
            "started": saved.get("started", now), "updated": now, "folders_done": folders_done,
            "folders_to_go": len(queue), "found_so_far": len(earlier) + len(entries),
            "note": stopped if stopped and stopped != "time" else "",
        }})
        write_json(out / "info.json", info)
        print(f"{key}: {folders_done} folders so far, {len(queue)} to go; carries on next run")
        return

    entries = earlier + entries
    files = sum(1 for e in entries if len(e) == 4)
    info = {
        "server": key, "name": name, "indexed_at": now,
        "status": "partial" if stopped == "entry limit" else "ok",
        "files": files, "folders": len(entries) - files,
        "bytes": sum(e[1] for e in entries if len(e) == 4), "seconds": seconds,
    }
    notes = []
    if stopped == "entry limit":
        notes.append("stopped at the entry limit; the listing is partial")
    if skipped:
        notes.append(f"skipped {skipped} folder{'s' if skipped != 1 else ''} that didn't answer")
    if notes:
        info["note"] = "; ".join(notes)
    if old_info.get("previous_addresses"):
        info["previous_addresses"] = old_info["previous_addresses"]
    if out.exists():
        shutil.rmtree(out)
    write_parts(out, entries)
    write_json(out / "info.json", info)
    print(f"{key}: {files} files, {info['folders']} folders in {seconds}s {info.get('note', '')}")


def is_archive(key, info):
    """In config/archives.txt, under this address or one its listing moved from."""
    archives = set(read_list("archives.txt"))
    return key in archives or bool(archives & set(info.get("previous_addresses", [])))


def is_due(key, now=None):
    """Never indexed, a pass still unfinished, or the last full index over 45 days old (never, for
    an archive that already has a complete listing)."""
    info = load_json(FILES / folder_name(key) / "info.json", {})
    if is_archive(key, info) and info.get("status") == "ok" and not info.get("pass"):
        return False
    if not info or info.get("pass") or info.get("status") in ("failed", "in progress"):
        return info.get("status") != "opted out"
    if info.get("status") == "opted out":
        return False
    try:
        last = datetime.strptime(info["indexed_at"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    except (KeyError, ValueError):
        return True
    return (now or datetime.now(timezone.utc)) - last > timedelta(days=REINDEX_AFTER_DAYS)


def planned_servers(everything=False):
    """Servers listed right now that are due (or all of them), minus the ones that asked not to
    be indexed. Even "all of them" leaves out archives that already have a complete listing:
    re-indexing one means naming it."""
    live = load_json(DATA / "live.json", {}).get("servers", {})
    skip = set(read_list("noindex.txt"))

    def wanted(k):
        if not everything:
            return is_due(k)
        info = load_json(FILES / folder_name(k) / "info.json", {})
        return not (is_archive(k, info) and info.get("status") == "ok" and not info.get("pass"))

    return [k for k, v in sorted(live.items()) if v.get("listed") and k not in skip and wanted(k)]


def group_by_host(keys):
    """One entry per machine ("host:port host:port2"), so a machine's servers are indexed one at a
    time: several logins at once from the same place can look like a flood and get the indexer
    banned."""
    groups = {}
    for key in keys:
        groups.setdefault(split_address(key, hotline.SERVER_PORT)[0], []).append(key)
    return [" ".join(sorted(g)) for _, g in sorted(groups.items())]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("servers", nargs="*")
    parser.add_argument("--due", action="store_true")
    parser.add_argument("--all", action="store_true")
    parser.add_argument("--plan", action="store_true")
    parser.add_argument("--out", help="write results here for merge_results.py, not into data/files")
    parser.add_argument("--time-limit", type=int, default=5 * 3600, help="seconds per server")
    parser.add_argument("--job-time", type=int, default=0,
                        help="seconds for all the servers given, shared out between them as they go")
    args = parser.parse_args()

    if args.plan:
        print(json.dumps(group_by_host(args.servers or planned_servers(args.all))))
        return 0

    skip = set(read_list("noindex.txt"))
    if not args.out:
        for key in skip:
            target = FILES / folder_name(key)
            if target.exists():
                shutil.rmtree(target)

    names = load_json(DATA / "servers.json", {})
    keys = planned_servers(args.all) if args.all or args.due else args.servers
    out_root = Path(args.out) if args.out else FILES
    started = time.monotonic()
    for n, key in enumerate(keys):
        if key in skip:
            print(f"{key}: in config/noindex.txt; skipped")
            continue
        limit = args.time_limit
        if args.job_time:
            # What's left of the job's time, split between the servers still to go.
            left = args.job_time - (time.monotonic() - started)
            limit = max(60, min(limit, int(left / (len(keys) - n))))
        if not args.out:
            # Writing in place: put the result into data/files exactly as merge_results.py does.
            tmp = DATA / ".index-tmp"
            index_server(key, names.get(key, {}).get("name", key), limit, tmp)
            from merge_results import merge_one
            merge_one(tmp / folder_name(key))
            shutil.rmtree(tmp, ignore_errors=True)
            continue
        index_server(key, names.get(key, {}).get("name", key), limit, out_root)
    return 0


if __name__ == "__main__":
    sys.exit(main())
