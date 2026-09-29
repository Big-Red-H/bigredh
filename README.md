# BigRedH

The source for [bigredh.com](http://bigredh.com) and its Hotline tracker at
[tracker.bigredh.com](http://tracker.bigredh.com). GitHub does the work and keeps the data;
DreamHost only serves the pages.

Both sites work in any browser, including classic Mac OS browsers over plain HTTP: HTML 4
tables, colors set in the HTML, and no JavaScript.

## What runs when

| When | Workflow | What it does |
|---|---|---|
| Every hour | `servers.yml` | Asks the trackers in `config/trackers.txt` for their lists, merges them, checks each server answers, saves the list to GitHub and uploads it. |
| The 1st of each month | `files.yml` | Logs into each new server (one not indexed yet) as a guest, records its files and folders, saves them to GitHub and uploads a new search index. |
| Every 6 hours | `population.yml` | Logs into each listed server, reads who's online, and uploads the Population page's list. Never saved to git (see below). |
| Every push to `main` | `deploy.yml` | Uploads both sites. |

GitHub's schedules are best effort and often run late or not at all, so a cron job on DreamHost
starts each workflow on time (`~/bin/run-workflow.sh`, with its token in
`~/editor-config/dispatch.env`; `crontab -l` shows the times). The token is a fine-grained one
for the Big-Red-H repos with only **Actions: Read and write**. GitHub's own schedules stay as a
backup.

Any of them can be started by hand from the **Actions** tab (**Run workflow**). The monthly one
can be given a few `host:port` addresses to index (or re-index) just those servers, or told to
re-index everything.

## The data

Everything is JSON, saved to this repo by the workflows:

- `data/servers.json`: every server seen in the last 30 days (name, description, which trackers
  list it, first and last day seen). Saved whenever it changes.
- `data/live.json`: the latest hour's check (user counts, which servers answered, which trackers
  answered). Saved once a day, at midnight UTC.
- `data/files/<host>_<port>/`: one server's files, one entry per line.
  A folder is `["/Path/Folder/", items]`, a file is `["/Path/File", bytes, "TYPE", "CREA"]`.
  `info.json` says when it was indexed and how it went.

`data/population.json` (who was online, and the names seen in the last 30 days) is the one thing
that is **not** saved here: it's uploaded to the site only, and each run starts from the site's
copy, so there's no permanent public record of who was online when. Names in
`config/population-hidden.txt` are left off.

The site's search runs on an SQLite file built from `data/files/` on every upload. It's never
saved to git; it can always be rebuilt.

A server stays as it was indexed until someone re-indexes it, and its listing is kept even
after it drops off the trackers. When a server comes back at a new address under exactly the
same name (and nothing else is listed under that name), the hourly check moves its listing to
the new address instead of indexing it again; the old address is kept in `previous_addresses`. A new server that can't be
reached is tried again the next month.

## Changing things

| To | Edit |
|---|---|
| Add or remove a tracker | `config/trackers.txt` |
| Hide a server from the list | `config/hidden.txt` |
| List a server that isn't on any tracker | `config/extra-servers.txt` |
| Leave a name off the Population page | `config/population-hidden.txt` |
| Handle a removal request | Issues labeled **removal** come from the form linked on the tracker's pages. Add the server to `config/hidden.txt` (off the list) and/or `config/noindex.txt` (out of the file index and Population), or the name to `config/population-hidden.txt`, then close the issue. |
| Keep a server out of the file index | `config/noindex.txt`: it drops out of search on the next upload and out of `data/files/` on the next monthly save. (A server owner can also make a folder named `noindex` before it's first indexed.) |
| Change the landing page | `site/www/index.html` |
| Change the tracker pages | `site/tracker/` |

## Setting up DreamHost

The workflows need these on the repo or the Big-Red-H organization
(**Settings > Secrets and variables > Actions**):

| Name | Kind | Example |
|---|---|---|
| `DEPLOY_SSH_KEY` | secret | the private key whose public half is in the DreamHost user's `~/.ssh/authorized_keys` |
| `DEPLOY_HOST` | secret | `iad1-shared-b7-01.dreamhost.com` |
| `DEPLOY_USER` | secret | the DreamHost SSH user |
| `DEPLOY_PATH_WWW` | variable | `/home/USER/bigredh.com` |
| `DEPLOY_PATH_TRACKER` | variable | `/home/USER/tracker.bigredh.com` |

Until they're set, the workflows still run and save to GitHub; they just skip the upload.

In the DreamHost panel, host both `bigredh.com` and `tracker.bigredh.com` with PHP 8, and leave
**HTTPS redirect** off so old browsers can still connect. A deploy only deletes files in a
folder it set up itself (it leaves a `.bigredh-deploy` file there); in a folder that already had
something in it, it uploads without deleting and says so.

## Running it on your computer

Python 3 and PHP, nothing to install:

```bash
python3 tracker/update_servers.py
python3 tracker/index_files.py 24.6.82.54:5500
python3 tracker/build_search.py build/tracker/db/files.sqlite
```

To preview the tracker, copy `site/tracker/` to `build/tracker/`, put `data/servers.json` and
`data/live.json` in `build/tracker/data/`, then:

```bash
php -S 127.0.0.1:8000 -t build/tracker
```
