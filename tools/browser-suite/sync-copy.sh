#!/usr/bin/env bash
# Sync the checkout's code into a site copy and RECORD WHICH REVISION IT IS (PLAN.md D-029).
#
# The recording is the point. requireCurrentCode() in harness.mjs refuses to run when the
# copy's revision does not match the checkout's HEAD, and a guard nobody can satisfy gets
# switched off — so the sync that makes it true lives here, next to it.
#
# Only code moves: app/, lang/, public/assets and migrations/. Not storage, not the
# database, not public/m or public/cache — those belong to the copy (D-013), and --delete is
# never used for the same reason (08-update puts a migration of its own into the copy's
# migrations/, which a --delete would remove under it). migrations/ was missing until the
# demo seed first needed a new table (0016, forms): the copy installed without it and the
# install failed at the site step.
#
#   tools/browser-suite/sync-copy.sh [target]
#
# The target defaults to BOXLET_SUITE_SITE_DIR, then to ~/boxlet-browser/fresh, which is
# what config.mjs resolves.
set -euo pipefail

checkout="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target="${1:-${BOXLET_SUITE_SITE_DIR:-$HOME/boxlet-browser/fresh}}"

if [ ! -f "$target/public/index.php" ]; then
  echo "Refusing: $target does not look like a Boxlet copy." >&2
  exit 1
fi
if [ "$(cd "$target" && pwd)" = "$checkout" ]; then
  echo "Refusing: $target is the checkout itself." >&2
  exit 1
fi

for part in app lang public/assets migrations; do
  rsync -a "$checkout/$part/" "$target/$part/"
done

revision="$(git -C "$checkout" rev-parse HEAD)"
mkdir -p "$target/storage"
printf '%s\n' "$revision" > "$target/storage/checkout.rev"

echo "Synced app, lang, public/assets and migrations into $target"
echo "Recorded revision ${revision:0:12}"
