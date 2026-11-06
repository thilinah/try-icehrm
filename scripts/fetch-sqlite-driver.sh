#!/bin/sh
# Fetch the MySQL-on-SQLite driver (WordPress/sqlite-database-integration) at a
# pinned release into vendor-src/. GPL-2.0-or-later, compatible with IceHrm's GPLv3.
set -eu

TAG="v3.1.0"
REPO="https://github.com/WordPress/sqlite-database-integration.git"
DEST="$(dirname "$0")/../vendor-src/sqlite-database-integration"

rm -rf "$DEST"
git clone --quiet --depth 1 --branch "$TAG" "$REPO" "$DEST"
echo "MySQL-on-SQLite driver $TAG -> $DEST ($(git -C "$DEST" rev-parse --short HEAD))"
