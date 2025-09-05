#!/usr/bin/env bash
set -euo pipefail

# Extract version from plugin header.
VERSION=$(php -r "preg_match('/^Version:\\s*(.+)$/m', file_get_contents('gexe-copy.php'), $m); echo $m[1];")

echo "Tagging release v$VERSION"

git tag -a "v$VERSION" -m "Release $VERSION"
git push origin "v$VERSION"
