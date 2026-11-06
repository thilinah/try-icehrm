#!/bin/sh
# Deploy dist/ to GitHub Pages: push it as a single commit to the try_icehrm branch of
# icehrm/icehrm.github.io, which Pages serves at https://icehrm.github.io/.
# The branch holds only build output and is overwritten on every deploy.
#
# Usage:
#   npm run build:bundle && npm run build && npm run deploy
#   npm run deploy -- --dry-run     everything except updating the branch
#
# Environment:
#   DEPLOY_REMOTE  git URL to push to (default: the icehrm.github.io repo over SSH)
#   DEPLOY_BRANCH  branch to overwrite (default: try_icehrm)
set -eu

REMOTE="${DEPLOY_REMOTE:-git@github.com:icehrm/icehrm.github.io.git}"
BRANCH="${DEPLOY_BRANCH:-try_icehrm}"
DIST="$(cd "$(dirname "$0")/.." && pwd)/dist"
DRY_RUN=""
if [ "${1:-}" = "--dry-run" ]; then DRY_RUN=1; fi

# The branch is replaced, history and all - never point this at a source branch.
case "$BRANCH" in
  master|main) echo "Refusing to overwrite the $BRANCH branch." >&2; exit 1 ;;
esac

for file in index.html sw.js icehrm-bundle.zip; do
  if [ ! -f "$DIST/$file" ]; then
    echo "dist/$file is missing. Run: npm run build:bundle && npm run build" >&2
    exit 1
  fi
done

# Without this file Pages runs the site through Jekyll.
touch "$DIST/.nojekyll"

# A throwaway repository whose work tree is dist/, so nothing is copied.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git init -q --bare "$TMP/deploy.git"
export GIT_DIR="$TMP/deploy.git" GIT_WORK_TREE="$DIST"
git symbolic-ref HEAD "refs/heads/$BRANCH"
cd "$DIST"
git add -A .
TREE="$(git write-tree)"

# Skip the upload when the branch already holds exactly these files.
if git fetch -q --depth 1 --filter=blob:none "$REMOTE" "$BRANCH" 2>/dev/null \
  && [ "$(git rev-parse 'FETCH_HEAD^{tree}')" = "$TREE" ]; then
  echo "Nothing to deploy: $BRANCH already has this build ($(git rev-parse --short FETCH_HEAD))."
  exit 0
fi

git commit -q -m "Deploy Try IceHrm playground ($(date -u +%Y-%m-%dT%H:%MZ))"
echo "Deploying $(git ls-files | wc -l | tr -d ' ') files ($(du -sh "$DIST" | cut -f1)) to $REMOTE $BRANCH"

# http.postBuffer: over HTTPS, GitHub rejects a push this large with git's default buffer.
git -c http.postBuffer=1048576000 push --force ${DRY_RUN:+--dry-run} "$REMOTE" "HEAD:refs/heads/$BRANCH"

if [ -n "$DRY_RUN" ]; then
  echo "Dry run: $BRANCH was not changed."
else
  echo "Deployed $(git rev-parse --short HEAD). GitHub Pages publishes it within a few minutes."
fi
