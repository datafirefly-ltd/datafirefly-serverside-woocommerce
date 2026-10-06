#!/usr/bin/env bash
# Rebuild assets/*.min.js from the sources. Run after ANY change to an assets/*.js file:
#   sh build-min.sh            (from the plugin folder)
#   sh build-min.sh path/to/plugin
#
# The minified files are exactly `terser --compress --mangle` of their source (checked byte for
# byte on the 2.28.0 files). Until a build is redone, the plugin serves the source when it is
# newer than its build; with SCRIPT_DEBUG on it always serves the source.
set -euo pipefail
DIR="${1:-$(cd "$(dirname "$0")" && pwd)}"
TERSER="${TERSER:-npx --yes terser@5}"
cd "$DIR/assets"
for src in dfss-tracker dfss-engagement dfss-dest-meta dfss-dest-tiktok dfss-dest-openai; do
    $TERSER "$src.js" --compress --mangle --output "$src.min.js"
    printf '%s.min.js  %s bytes\n' "$src" "$(wc -c < "$src.min.js" | tr -d ' ')"
done
