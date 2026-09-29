#!/usr/bin/env bash
# Uploads to DreamHost over SSH.
#
#   scripts/deploy.sh all     both sites: pages, this hour's list, and a fresh search index
#   scripts/deploy.sh data    only this hour's server list (the hourly check)
#   scripts/deploy.sh index   the server list and a fresh search index (after the monthly scan)
#   scripts/deploy.sh population   who's online (every few hours; never saved to git)
#
# Needs DEPLOY_SSH_KEY, DEPLOY_HOST, DEPLOY_USER, DEPLOY_PATH_WWW and DEPLOY_PATH_TRACKER. When
# they aren't set yet it says so and stops without failing, so the checks still run and save to
# GitHub before the DreamHost side exists.
set -euo pipefail

mode="${1:-all}"
cd "$(dirname "$0")/.."

for name in DEPLOY_SSH_KEY DEPLOY_HOST DEPLOY_USER DEPLOY_PATH_WWW DEPLOY_PATH_TRACKER; do
  if [ -z "${!name:-}" ]; then
    echo "$name isn't set yet; skipping the upload."
    exit 0
  fi
done

mkdir -p ~/.ssh
printf '%s\n' "$DEPLOY_SSH_KEY" > ~/.ssh/deploy_key
chmod 600 ~/.ssh/deploy_key
ssh-keyscan -H "$DEPLOY_HOST" >> ~/.ssh/known_hosts 2>/dev/null
ssh_cmd="ssh -i $HOME/.ssh/deploy_key"
remote="$DEPLOY_USER@$DEPLOY_HOST"

# rsync --delete only into a folder this script set up: a folder with a marker file, or an empty
# one. Anything else (a site that's still there from before) gets uploaded to without deleting.
upload_site() {
  local from="$1" to="$2"
  local delete=""
  if $ssh_cmd "$remote" "mkdir -p '$to' && { test -e '$to/.bigredh-deploy' || test -z \"\$(ls -A '$to' | grep -v -e '^.dh-diag\$' -e '^.well-known\$' -e '^favicon\.\(ico\|gif\)\$')\"; }"; then
    delete="--delete"
  else
    echo "::warning::$to has files this deploy didn't put there, so nothing will be deleted from it. Clear it out, or add a .bigredh-deploy file to it, to allow deletes."
  fi
  rsync -rlvz $delete --delay-updates --chmod=D755,F644 \
    --exclude /.well-known/ --exclude /.dh-diag --exclude /.bigredh-deploy --exclude /data/ \
    -e "$ssh_cmd" "$from" "$remote:$to/"
  $ssh_cmd "$remote" "touch '$to/.bigredh-deploy'"
}

upload_data() {
  $ssh_cmd "$remote" "mkdir -p '$DEPLOY_PATH_TRACKER/data'"
  rsync -rlvz --chmod=F644 -e "$ssh_cmd" data/servers.json data/live.json "$remote:$DEPLOY_PATH_TRACKER/data/"
}

upload_index() {
  python3 tracker/build_search.py build/files.sqlite
  $ssh_cmd "$remote" "mkdir -p '$DEPLOY_PATH_TRACKER/db'"
  # rsync writes to a temporary name and renames it into place, so a page never reads half a file.
  rsync -rlvz --chmod=F644 -e "$ssh_cmd" build/files.sqlite "$remote:$DEPLOY_PATH_TRACKER/db/"
}

case "$mode" in
  all)
    upload_site site/www/ "$DEPLOY_PATH_WWW"
    rm -rf build/tracker
    mkdir -p build/tracker/db
    cp -R site/tracker/. build/tracker/
    python3 tracker/build_search.py build/tracker/db/files.sqlite
    upload_site build/tracker/ "$DEPLOY_PATH_TRACKER"
    # data/ belongs to the hourly check and Population, which keep it fresher than the copy in
    # git, so a deploy only fills it in when it's missing.
    $ssh_cmd "$remote" "mkdir -p '$DEPLOY_PATH_TRACKER/data'"
    rsync -rlvz --ignore-existing --chmod=F644 -e "$ssh_cmd" data/servers.json data/live.json "$remote:$DEPLOY_PATH_TRACKER/data/"
    ;;
  data)
    upload_data
    ;;
  index)
    upload_data
    upload_index
    ;;
  population)
    $ssh_cmd "$remote" "mkdir -p '$DEPLOY_PATH_TRACKER/data'"
    rsync -rlvz --chmod=F644 -e "$ssh_cmd" data/population.json "$remote:$DEPLOY_PATH_TRACKER/data/"
    ;;
  *)
    echo "usage: $0 all|data|index|population" >&2
    exit 2
    ;;
esac
