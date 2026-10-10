# Releasing

How a version of this plugin reaches merchants: a GitHub release (the website's
download button) and the WordPress.org directory (slug `tackquote`). This file is
repository documentation; `bin/build.sh` keeps `docs/` out of `tackquote.zip`.

> **GitHub Actions is billing-blocked (owner decision, 2026-10-10: skip it for
> now).** `.github/workflows/release.yml` would build and attach the zip on a `v*`
> tag, but it does not run. Until it does, both channels are released by hand from
> a local build, exactly as below. A green check on a PR is vacuous while Actions
> is blocked: run the gates locally.

## 0. Before you start

- The release PR is merged and `main` is what you want to ship.
- The version agrees everywhere, in one commit: the `Version:` header and
  `TACK_QUOTES_VERSION` in `tackquote.php`, `Stable tag:` in `readme.txt`, and a
  `= X.Y.Z =` section at the top of `== Changelog ==`. `bin/wporg-sync.sh
  --check-readme` refuses a release where any of them disagree.
- Gates pass on that commit: `php -l`, `php tests/run.php`, PHPCS, `bash
  scripts/package.sh <dir>` and `bash scripts/check-release-claims.sh` (see the
  README's Development section).

```bash
git switch main && git pull --ff-only
bin/wporg-sync.sh --check-readme          # offline wordpress.org readme rules + release claims
```

## 1. GitHub release

Tag the merge commit, build the zip from it, publish it under the asset name the
website links to (`releases/latest/download/tackquote.zip`; never rename it).

```bash
V=1.10.0                                   # the version being released
git tag -a "v$V" -m "Release $V" <merge-commit-sha>
git push origin "v$V"

bash scripts/package.sh dist               # builds from git HEAD -> dist/tackquote.zip
                                           # (check out the tag first if main has moved on)

# Release notes = this version's changelog section from readme.txt.
awk -v v="= $V =" '$0==v {f=1; next} /^= [0-9]/ {f=0} f' readme.txt > "/tmp/tackquote-$V-notes.md"

gh release create "v$V" dist/tackquote.zip -R tackquote/woocommerce \
  --title "$V" --notes-file "/tmp/tackquote-$V-notes.md"
```

Check the result: `gh release view "v$V" -R tackquote/woocommerce` lists one
asset, `tackquote.zip`, and
`https://github.com/tackquote/woocommerce/releases/latest/download/tackquote.zip`
downloads it.

## 2. WordPress.org (SVN)

WordPress.org serves the plugin from Subversion, not from GitHub:
`trunk/` is the development copy, `tags/X.Y.Z/` is what users install (the
`Stable tag` in `trunk/readme.txt` picks the tag), and `assets/` holds the
directory page's banners, icons and screenshots.

`bin/wporg-sync.sh` stages all three from the same build and **never commits**:

```bash
# Once: a working copy (read-only checkout; no credentials needed).
svn co https://plugins.svn.wordpress.org/tackquote ../tackquote-wporg-svn
svn up ../tackquote-wporg-svn                       # later releases: refresh it first

bin/wporg-sync.sh ../tackquote-wporg-svn --dry-run  # what would change
bin/wporg-sync.sh ../tackquote-wporg-svn --tag "$V" # stage it
svn status ../tackquote-wporg-svn | head            # review
svn ci -m "Release $V" ../tackquote-wporg-svn       # publish (the owner)
```

`svn ci` prompts for the **wordpress.org** username and password of the plugin's
committer account (case-sensitive; an SVN password set in the wordpress.org
profile, not the GitHub one). It is the only step that sends anything.

What the script does, in order, and what makes it stop (exit codes in
`bin/wporg-sync.sh --help`):

1. Refuses a dirty git tree: the zip is built from HEAD, so uncommitted edits
   would silently not ship.
2. Refuses `--tag` that is not the `Version:` header, and refuses if
   `tags/<version>/` exists: a tag already on wordpress.org is never touched; a
   tag staged by an earlier run is restaged only with `--force-tag`.
3. Builds `tackquote.zip` with `scripts/package.sh` (so trunk is exactly the
   shipped files) and unpacks it.
4. Validates the readme offline against the wordpress.org rules Plugin Check
   applies (Stable tag == Version, `Tested up to` <= the latest WordPress
   (`--latest-wp`, default in the script), `Requires at least`/`Requires PHP`
   present and equal to the plugin header, GPL license matching the header, at
   most 5 tags, a short description of at most 150 characters, one caption per
   `screenshot-N.png`), and runs `scripts/check-release-claims.sh`.
5. Mirrors the zip into `trunk/` (deleting files the zip no longer has), copies it
   to `tags/<version>/`, and mirrors `.wordpress-org/{screenshot,banner,icon}-*.png`
   into `assets/` (deleting stale ones, e.g. a dropped screenshot; refusing any
   listing asset that is not a real PNG). Other files in `assets/` are left alone.
6. `svn add` / `svn rm` from `svn status`, `svn:mime-type image/png` on every
   asset PNG (without it wordpress.org offers the image as a download instead of
   showing it).
7. Verifies trunk and the tag equal the zip byte for byte, nothing is left
   unversioned or missing, `tags/<version>/tackquote.php` says the version and
   `trunk/readme.txt` carries the Stable tag. Then prints the counts and the
   `svn ci` command.

**Re-running.** If `main` moves after staging (for example a late fix), commit,
then `bin/wporg-sync.sh ../tackquote-wporg-svn --force-tag`: trunk and the staged
tag are rebuilt from the new HEAD and the svn schedule is recomputed. A re-run is
idempotent; without `--force-tag` it refuses before writing anything.

**Discarding a staging.** `svn revert -R ../tackquote-wporg-svn`, then delete the
unversioned `tags/<version>/` folder it leaves behind.

`bin/wporg-sync.sh --self-test` exercises all of the above against a throwaway
local SVN repository (`svnadmin load`, no network, no commit).

## 3. After publishing

- The directory page updates within minutes; the "Download" button serves
  `tags/<Stable tag>/`. Check `https://wordpress.org/plugins/tackquote/` shows the
  version, the screenshots and the banner.
- Record the release in the team handoff notes.
