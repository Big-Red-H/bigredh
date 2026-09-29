#!/usr/bin/env bash
# Commits the given data paths and pushes, retrying if another run pushed first.
#   scripts/save-data.sh "message" path...
set -euo pipefail

message="$1"
shift
cd "$(dirname "$0")/.."

git config user.name "BigRedH Tracker"
git config user.email "github-actions[bot]@users.noreply.github.com"
git add -A -- "$@"
if git diff --cached --quiet; then
  echo "Nothing changed."
  exit 0
fi
git commit -q -m "$message"
for attempt in 1 2 3 4 5; do
  if git push -q; then
    exit 0
  fi
  git pull -q --rebase
  sleep $((attempt * 5))
done
echo "Couldn't push after 5 tries." >&2
exit 1
