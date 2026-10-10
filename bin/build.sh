#!/usr/bin/env bash
# Build a distributable zip of the Tack Quotes plugin.
set -euo pipefail

PLUGIN_SLUG="tackquote"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
STAGE="$DIST/$PLUGIN_SLUG"

rm -rf "$DIST"
mkdir -p "$STAGE"

# Stage from git, never from the working tree: the committed tree (HEAD) is
# extracted to a scratch directory and the exclusions below run against that.
# Local litter (composer.lock, .php-cs-fixer.cache, an outdir from an earlier
# scripts/package.sh run, ...) is untracked, so it can never reach the zip, and
# the exclusion list no longer has to anticipate it. Uncommitted edits are not
# packed either -- commit first.
# The tree goes in a subdirectory: rsync copies the source root's mode onto
# $STAGE (the zip's top-level folder), and mktemp -d is 0700.
SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"' EXIT
SRC="$SCRATCH/tree"
mkdir "$SRC"
git -C "$ROOT" archive --format=tar HEAD | tar -x -C "$SRC"

# Copy runtime files only (exclude dev/build artifacts).
rsync -a --delete \
	--exclude 'dist' \
	--exclude 'bin' \
	--exclude '.git*' \
	--exclude 'vendor' \
	--exclude 'node_modules' \
	--exclude 'tests' \
	`# Repository scaffolding from the standalone repo (scripts/package.sh and`\
	`# the repo-level LICENSE). Anchored to the plugin root so nothing inside`\
	`# the plugin with the same name is caught. .github/ is covered by .git*.`\
	--exclude '/scripts' \
	--exclude '/LICENSE' \
	`# WordPress.org listing assets — banners, icons and screenshots. They`\
	`# belong to the DIRECTORY PAGE, not the plugin: wordpress.org reads them`\
	`# from the SVN assets/ folder, and every byte shipped here is downloaded`\
	`# by every user on every install and update for nothing. Leaving them in`\
	`# took the zip from 84KB to 625KB.`\
	--exclude '.wordpress-org' \
	`# Coding-standards tooling: phpcs.xml.dist (covered by *.dist) and the`\
	`# Composer manifest + lock that install it. The plugin has NO Composer`\
	`# runtime dependency, so a shipped composer.json would only invite a`\
	`# merchant's host to run composer install inside wp-content/plugins.`\
	--exclude '/composer.json' \
	--exclude '/composer.lock' \
	--exclude '*.dist' \
	--exclude '.DS_Store' \
	--exclude '*.md' \
	"$SRC/" "$STAGE/"

cd "$DIST"
zip -r -q "$PLUGIN_SLUG.zip" "$PLUGIN_SLUG"
rm -rf "$STAGE"

# ── The zip must contain the PLUGIN and nothing else ────────────────────────
#
# An exclusion that silently stops matching is invisible: the build still
# succeeds, the plugin still works, and the only symptom is a zip that quietly
# grew. That already happened once — WordPress.org listing assets landed in
# .wordpress-org/, `--exclude '.git*'` did not cover them, and the zip went from
# 84KB to 625KB of images downloaded by every user on every update.
#
# So assert the outcome rather than trusting the exclusion list.
ENTRIES=$(unzip -l "$DIST/$PLUGIN_SLUG.zip" | grep -E '^[[:space:]]+[0-9]+[[:space:]]')
LEAKED=$(printf '%s\n' "$ENTRIES" | grep -cE '\.(png|jpg|jpeg|gif|zip)$' || true)
if [ "$LEAKED" -gt 0 ]; then
	echo "BUILD FAILED: $LEAKED image/archive file(s) are inside the plugin zip." >&2
	echo "Listing assets belong in .wordpress-org/ and must be excluded above." >&2
	printf '%s\n' "$ENTRIES" | grep -E '\.(png|jpg|jpeg|gif|zip)$' >&2
	exit 1
fi

# Same idea for the development tooling. composer.json / composer.lock are
# committed (reproducible PHPCS in CI), vendor/ is what they install, and
# phpcs.xml.dist is the ruleset: none of it is plugin code, and a composer.json
# inside wp-content/plugins/ is an invitation for a host to run `composer
# install` against the live site. Match on the entry's path within the zip
# (`tackquote/composer.json`, `tackquote/vendor/...`), anchored at the plugin
# root, so a product called "vendor" or a merchant's own file is not caught.
TOOLING=$(printf '%s\n' "$ENTRIES" | awk '{print $NF}' \
	| grep -E "^$PLUGIN_SLUG/(vendor/|composer\.(json|lock)$|phpcs\.xml(\.dist)?$|\.phpcs\.xml(\.dist)?$)" || true)
if [ -n "$TOOLING" ]; then
	echo "BUILD FAILED: development tooling is inside the plugin zip:" >&2
	printf '%s\n' "$TOOLING" | sed 's/^/  /' >&2
	echo "vendor/, composer.json, composer.lock and phpcs.xml.dist must be excluded above." >&2
	exit 1
fi

echo "Built $DIST/$PLUGIN_SLUG.zip"
