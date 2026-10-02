"""After the monthly scan: moves each server's results from the CI runs into data/files/.

  python3 tracker/merge_results.py results/

A finished listing (or an opt-out) replaces the server's folder. An unfinished pass only replaces
its next/ folder and info.json, so the previous listing stays up until the new one is complete.
A failure only updates info.json, so the previous listing stays too.
"""

import shutil
import sys
from pathlib import Path

from index_files import FILES, folder_name
from update_servers import CONFIG, load_json, read_list, write_json


def add_archive(info):
    """A server whose files didn't change between two indexes goes on the archives list."""
    key = info.get("server", "")
    if not key or key in set(read_list("archives.txt")):
        return
    line = (f"{key:<21} # {info.get('name', '')}: unchanged between its "
            f"{info['unchanged_since'][:10]} and {info.get('indexed_at', '')[:10]} indexes (added automatically)\n")
    with open(CONFIG / "archives.txt", "a", encoding="utf-8") as f:
        f.write(line)
    print(f"{key}: unchanged since its last index; added to config/archives.txt")


def merge_one(result):
    info = load_json(result / "info.json", {})
    target = FILES / result.name
    if info.get("pass"):
        target.mkdir(parents=True, exist_ok=True)
        if (target / "next").exists():
            shutil.rmtree(target / "next")
        shutil.copytree(result / "next", target / "next")
        write_json(target / "info.json", info)
        print(f"{result.name}: {info['pass']['folders_done']} folders so far, {info['pass']['folders_to_go']} to go")
    elif info.get("status") in ("ok", "partial", "opted out"):
        if target.exists():
            shutil.rmtree(target)
        shutil.copytree(result, target)
        print(f"{result.name}: {info.get('status')}")
        if info.get("unchanged_since"):
            add_archive(info)
    else:
        previous = load_json(target / "info.json", {})
        previous.update({k: v for k, v in info.items() if k in ("server", "name", "last_attempt", "last_error")})
        previous.setdefault("status", "failed")
        write_json(target / "info.json", previous)
        print(f"{result.name}: failed, kept the previous listing ({info.get('last_error')})")


def main():
    results = Path(sys.argv[1])
    for folder in sorted(p for p in results.iterdir() if p.is_dir()) if results.exists() else []:
        merge_one(folder)
    # Servers added to config/noindex.txt since their listing was saved.
    for key in read_list("noindex.txt"):
        target = FILES / folder_name(key)
        if target.exists():
            shutil.rmtree(target)
            print(f"{key}: removed (config/noindex.txt)")


if __name__ == "__main__":
    main()
