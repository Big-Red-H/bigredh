"""Builds the SQLite file the tracker's PHP pages browse and search, from data/files/.

  python3 tracker/build_search.py out/files.sqlite

Rebuilt from scratch every time: the JSON in git is the real copy, this is only an index of it.
"""

import json
import sqlite3
import sys
from pathlib import Path

from update_servers import DATA, load_json

FILES = DATA / "files"


def main():
    out = Path(sys.argv[1] if len(sys.argv) > 1 else "files.sqlite")
    out.parent.mkdir(parents=True, exist_ok=True)
    tmp = out.with_suffix(".tmp")
    if tmp.exists():
        tmp.unlink()
    db = sqlite3.connect(tmp)
    db.executescript("""
        PRAGMA journal_mode = OFF;
        PRAGMA synchronous = OFF;
        CREATE TABLE servers (
            server TEXT PRIMARY KEY, name TEXT, status TEXT, indexed_at TEXT,
            files INTEGER, folders INTEGER, bytes INTEGER, note TEXT
        );
        CREATE TABLE files (
            id INTEGER PRIMARY KEY, server TEXT, parent TEXT, name TEXT,
            folder INTEGER, size INTEGER, type TEXT, creator TEXT
        );
    """)
    count = 0
    for folder in sorted(p for p in FILES.iterdir() if p.is_dir()) if FILES.exists() else []:
        info = load_json(folder / "info.json", {})
        key = info.get("server") or folder.name.replace("_", ":")
        db.execute(
            "INSERT OR REPLACE INTO servers VALUES (?,?,?,?,?,?,?,?)",
            (key, info.get("name"), info.get("status"), info.get("indexed_at"), info.get("files", 0),
             info.get("folders", 0), info.get("bytes", 0), info.get("note") or info.get("last_error")),
        )
        rows = []
        for part in sorted(folder.glob("part-*.jsonl")):
            with open(part, encoding="utf-8") as f:
                for line in f:
                    e = json.loads(line)
                    path = e[0]
                    is_folder = path.endswith("/")
                    trimmed = path[:-1] if is_folder else path
                    parent, _, name = trimmed.rpartition("/")
                    rows.append((
                        key, parent + "/", name, int(is_folder), e[1],
                        e[2] if len(e) > 2 else None, e[3] if len(e) > 3 else None,
                    ))
        db.executemany(
            "INSERT INTO files (server, parent, name, folder, size, type, creator) VALUES (?,?,?,?,?,?,?)",
            rows,
        )
        count += len(rows)
    db.executescript("""
        CREATE INDEX files_by_folder ON files (server, parent);
        CREATE INDEX files_by_name ON files (name);
        CREATE VIRTUAL TABLE names USING fts5 (name, content='files', content_rowid='id');
        INSERT INTO names (rowid, name) SELECT id, name FROM files;
        INSERT INTO names (names) VALUES ('optimize');
        PRAGMA journal_mode = DELETE;
    """)
    db.commit()
    db.execute("VACUUM")
    db.close()
    tmp.replace(out)
    print(f"{out}: {count} entries")


if __name__ == "__main__":
    main()
