"""Every few hours: who's on each server, for the tracker's Population page.

  python3 tracker/population.py [previous population.json]

Logs into each listed server the trackers say has users on it, as a guest, reads the user list,
and leaves. Servers the trackers list with no users aren't visited. The result goes to
data/population.json, which is uploaded to the site but never saved to git: the site shows
the last 30 days, and there's no permanent public record of who was online when.

The previous file (downloaded from the site by the workflow) carries each person's history
forward from one run to the next.
"""

import csv
import io
import sys
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from pathlib import Path

import hotline
from update_servers import DATA, load_json, read_list, split_address, write_json

KEEP_DAYS = 30
ICON_LIST = "http://hlwiki.com/ik0ns/ik0ns.csv"
# Names so common they aren't anybody in particular.
GENERIC = {"", "guest", "unnamed", "unnamed user", "user", "hotline user"}


def known_icons():
    """The icons hlwiki has pictures for, from its ik0ns.csv: id -> [width, height, name color]
    ("white" or "black", for a name drawn over the icon 33 pixels in; see hlwiki's Icon Index)."""
    try:
        with urllib.request.urlopen(ICON_LIST, timeout=30) as r:
            text = r.read().decode("utf-8", "replace")
    except OSError as e:
        print(f"Couldn't read the icon list ({e}); showing no icons this time.", file=sys.stderr)
        return {}
    icons = {}
    for row in csv.DictReader(io.StringIO(text)):
        raw = (row.get("id") or "").strip()
        if not raw.lstrip("-").isdigit():
            continue
        try:
            size = [int(row["width"]), int(row["height"])]
        except (KeyError, TypeError, ValueError):
            size = [232, 18]
        icons[int(raw)] = size + [row.get("name_color") or "black"]
    return icons


def look(key):
    host, port = split_address(key, hotline.SERVER_PORT)
    try:
        with hotline.HotlineServer(host, port, nickname="TrackerCheck", timeout=20) as server:
            server.login()
            return key, server.list_users(), None
    except (OSError, hotline.HotlineError) as e:
        return key, None, str(e) or type(e).__name__


def main():
    now = datetime.now(timezone.utc)
    stamp = now.strftime("%Y-%m-%dT%H:%M:%SZ")
    cutoff = (now - timedelta(days=KEEP_DAYS)).strftime("%Y-%m-%dT%H:%M:%SZ")
    previous = load_json(Path(sys.argv[1]) if len(sys.argv) > 1 else DATA / "population.json", {})
    servers = load_json(DATA / "servers.json", {})
    live = load_json(DATA / "live.json", {}).get("servers", {})
    # Left out: servers that asked to be, hidden ones, and ones whose "users" aren't people.
    skip = set(read_list("noindex.txt")) | set(read_list("hidden.txt")) | set(read_list("population-skip.txt"))
    hidden_names = {n.lower() for n in read_list("population-hidden.txt")}
    icons = known_icons()

    # Only servers the trackers say have someone on: an empty server has nobody to see, and
    # leaving it alone means one less login for it.
    keys = [k for k, v in sorted(live.items())
            if v.get("listed") and v.get("online") and v.get("users", 0) > 0 and k not in skip]
    with ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(look, keys))

    people = previous.get("people", {})
    online = {}
    checked = {}
    for key, users, error in results:
        checked[key] = {"ok": users is not None, "error": error}
        if users is None:
            continue
        here = []
        for u in users:
            name = u["name"]
            if name.lower() in GENERIC or name.lower() in hidden_names:
                continue
            here.append({"name": name, "icon": u["icon"]})
            p = people.setdefault(name, {"first_seen": stamp, "servers": {}})
            p["icon"] = u["icon"]
            p["last_seen"] = stamp
            p["servers"][key] = stamp
            # Every icon this name has been seen with, and when last, for their own page.
            p.setdefault("icons", {})[str(u["icon"])] = stamp
        online[key] = sorted(here, key=lambda u: u["name"].lower())

    # Forget anyone not seen for KEEP_DAYS, and anyone who has asked to be left off since.
    for name in list(people):
        p = people[name]
        p["servers"] = {k: t for k, t in p.get("servers", {}).items() if t >= cutoff and k not in skip}
        p["icons"] = {i: t for i, t in p.get("icons", {}).items() if t >= cutoff}
        p["icons"].setdefault(str(p["icon"]), p.get("last_seen", stamp))
        if p.get("last_seen", "") < cutoff or not p["servers"] or name.lower() in hidden_names:
            del people[name]

    names = {k: v.get("name", k) for k, v in servers.items()}
    write_json(DATA / "population.json", {
        "checked_at": stamp,
        "keep_days": KEEP_DAYS,
        "server_names": {k: names.get(k, k) for k in sorted({s for p in people.values() for s in p["servers"]} | set(online))},
        "online": online,
        "checked": checked,
        "people": dict(sorted(people.items(), key=lambda kv: kv[0].lower())),
        # Size and name color of each icon in use, for drawing names over them.
        "icons": {str(i): icons[i] for i in sorted({int(i) for p in people.values() for i in p.get("icons", {})}
                                                    | {p["icon"] for p in people.values()}
                                                    | {u["icon"] for us in online.values() for u in us}) if i in icons},
    })
    ok = sum(1 for c in checked.values() if c["ok"])
    print(f"{ok} of {len(keys)} servers answered; {sum(len(v) for v in online.values())} people online, "
          f"{len(people)} seen in the last {KEEP_DAYS} days")
    return 0


if __name__ == "__main__":
    sys.exit(main())
