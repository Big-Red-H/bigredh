"""Moves the file listings named in data/moves.json (written by update_servers.py) to the
servers' new addresses, with git mv, so a server that changed address isn't indexed again.

  python3 tracker/apply_moves.py

In the hourly workflow, which only checks out part of the repo, both folders are added to the
checkout first. The changes are staged, ready to commit.
"""

import subprocess
import sys

from index_files import FILES, folder_name
from update_servers import DATA, load_json, write_json


def main():
    moves = load_json(DATA / "moves.json", [])
    for m in moves:
        src, dst = FILES / folder_name(m["from"]), FILES / folder_name(m["to"])
        if not src.exists() or dst.exists():
            print(f"{m['name']}: nothing to move ({src.name} -> {dst.name})")
            continue
        subprocess.run(["git", "mv", str(src), str(dst)], check=True)
        info = load_json(dst / "info.json", {})
        info["server"] = m["to"]
        info["previous_addresses"] = sorted(set(info.get("previous_addresses", []) + [m["from"]]))
        write_json(dst / "info.json", info)
        subprocess.run(["git", "add", str(dst / "info.json")], check=True)
        print(f"{m['name']}: file listing moved from {m['from']} to {m['to']}")
    (DATA / "moves.json").unlink(missing_ok=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
