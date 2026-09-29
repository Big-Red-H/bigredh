"""After the monthly scan: moves each server's results from the CI runs into data/files/.

  python3 tracker/merge_results.py results/

A server that was walked (or opted out) is replaced whole. A server that failed only has its
info.json updated with the error, so last month's listing stays.
"""

import shutil
import sys
from pathlib import Path

from index_files import FILES, folder_name
from update_servers import load_json, read_list, write_json


def main():
    results = Path(sys.argv[1])
    for folder in sorted(p for p in results.iterdir() if p.is_dir()):
        info = load_json(folder / "info.json", {})
        target = FILES / folder.name
        if info.get("status") in ("ok", "partial", "opted out"):
            if target.exists():
                shutil.rmtree(target)
            shutil.copytree(folder, target)
            print(f"{folder.name}: {info.get('status')}")
        else:
            previous = load_json(target / "info.json", {})
            previous.update({k: v for k, v in info.items() if k in ("server", "name", "last_attempt", "last_error")})
            previous.setdefault("status", "failed")
            write_json(target / "info.json", previous)
            print(f"{folder.name}: failed, kept the previous listing ({info.get('last_error')})")

    # Servers added to config/noindex.txt since their listing was saved.
    for key in read_list("noindex.txt"):
        target = FILES / folder_name(key)
        if target.exists():
            shutil.rmtree(target)
            print(f"{key}: removed (config/noindex.txt)")


if __name__ == "__main__":
    main()
