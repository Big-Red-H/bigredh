"""Every few hours: who's on each server, for the tracker's Population page.

  python3 tracker/population.py [previous population.json]

Logs into each listed server as a guest, reads the user list, and leaves. The result goes to
data/population.json, which is uploaded to the site but never saved to git: the site shows
the last 30 days, and there's no permanent public record of who was online when.

The previous file (downloaded from the site by the workflow) carries each person's history
forward from one run to the next.
"""

import sys
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone

import hotline
from update_servers import DATA, load_json, read_list, split_address, write_json

KEEP_DAYS = 30
ICON_LIST = "http://hlwiki.com/ik0ns/ik0ns.csv"
# Names so common they aren't anybody in particular.
GENERIC = {"", "guest", "unnamed", "unnamed user", "user", "hotline user"}


def known_icons():
    """The icons hlwiki has pictures for, so the page doesn't show broken images."""
    try:
        with urllib.request.urlopen(ICON_LIST, timeout=30) as r:
            lines = r.read().decode("utf-8", "replace").splitlines()
    except OSError as e:
        print(f"Couldn't read the icon list ({e}); showing no icons this time.", file=sys.stderr)
        return set()
    ids = set()
    for line in lines[1:]:
        first = line.split(",", 1)[0].strip()
        if first.lstrip("-").isdigit():
            ids.add(int(first))
    return ids


def look(key):
    host, port = split_address(key, hotline.SERVER_PORT)
    try:
        with hotline.HotlineServer(host, port, nickname="BigRedH Tracker", timeout=20) as server:
            server.login()
            return key, server.list_users(), None
    except (OSError, hotline.HotlineError) as e:
        return key, None, str(e) or type(e).__name__


def main():
    now = datetime.now(timezone.utc)
    stamp = now.strftime("%Y-%m-%dT%H:%M:%SZ")
    cutoff = (now - timedelta(days=KEEP_DAYS)).strftime("%Y-%m-%dT%H:%M:%SZ")
    previous = load_json(sys.argv[1], {}) if len(sys.argv) > 1 else load_json(DATA / "population.json", {})
    servers = load_json(DATA / "servers.json", {})
    live = load_json(DATA / "live.json", {}).get("servers", {})
    skip = set(read_list("noindex.txt")) | set(read_list("hidden.txt"))
    hidden_names = {n.lower() for n in read_list("population-hidden.txt")}
    icons = known_icons()

    keys = [k for k, v in sorted(live.items()) if v.get("listed") and v.get("online") and k not in skip]
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
        online[key] = sorted(here, key=lambda u: u["name"].lower())

    # Forget anyone not seen for KEEP_DAYS, and anyone who has asked to be left off since.
    for name in list(people):
        p = people[name]
        p["servers"] = {k: t for k, t in p.get("servers", {}).items() if t >= cutoff}
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
        "icons": sorted({p["icon"] for p in people.values() if p["icon"] in icons}),
    })
    ok = sum(1 for c in checked.values() if c["ok"])
    print(f"{ok} of {len(keys)} servers answered; {sum(len(v) for v in online.values())} people online, "
          f"{len(people)} seen in the last {KEEP_DAYS} days")
    return 0


if __name__ == "__main__":
    sys.exit(main())
