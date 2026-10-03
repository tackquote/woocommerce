#!/usr/bin/env bash
#
# Build the installable WooCommerce artifact, reproducibly: dist/tackquote.zip.
#
# Split out of the hub repository's scripts/package-all.sh
# (tackquote/tack-ecommerce-extensions, retired 2026-10-04) when this plugin moved to
# its own repository. The top-level directory inside the zip MUST be the
# WordPress.org slug -- `tackquote`, the slug assigned to this submission. WP
# derives the plugin folder from it, a mismatch breaks updates, and WordPress.org
# additionally requires the text domain to equal the slug. This delegates to the
# plugin's own builder (bin/build.sh) so the two cannot drift; that builder
# excludes bin/, tests/, *.md and the repository scaffolding (scripts/, LICENSE,
# .github/), so THIS is the artifact to submit to WordPress.org.
#
# Usage: scripts/package.sh [outdir]     (default: dist)

set -Eeuo pipefail

OUT="${1:-dist}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

say() { printf '  %s\n' "$*"; }

# bin/build.sh always writes (and first wipes) $ROOT/dist, so build before
# preparing OUT -- OUT may be that same directory.
say "woocommerce"
bash bin/build.sh >/dev/null

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
if [ "$OUT" != "$ROOT/dist" ]; then
  rm -f "$OUT/tackquote.zip"
  cp dist/tackquote.zip "$OUT/"
fi
say "tackquote.zip  $(wc -c < "$OUT/tackquote.zip" | tr -d ' ') bytes"

echo
echo "artifacts in $OUT:"
ls -1 "$OUT"
