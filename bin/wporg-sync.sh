#!/usr/bin/env bash
#
# Stage a release of this plugin in a WordPress.org SVN working copy.
#
#   bin/wporg-sync.sh <svn-working-copy> [--tag X.Y.Z] [--dry-run] [--force-tag]
#   bin/wporg-sync.sh --check-readme
#   bin/wporg-sync.sh --self-test
#
# It builds tackquote.zip with the normal packaging (scripts/package.sh ->
# bin/build.sh, staged from git HEAD), so trunk/ is byte-for-byte the shipped
# zip. Then it mirrors that tree into <wc>/trunk/, copies it to
# <wc>/tags/<version>/, mirrors the listing PNGs from .wordpress-org/ into
# <wc>/assets/, schedules the svn adds/deletes, sets svn:mime-type on the PNGs
# and prints the one command the owner runs to publish:
#
#   svn ci -m "Release X.Y.Z" <wc>
#
# This script NEVER commits and never talks to wordpress.org: it only runs
# local svn commands (status, add, rm, propset, propget, info). Run with -h for
# the flags and exit codes. Bash 3.2 compatible (macOS /bin/bash).

set -Eeuo pipefail

PLUGIN_SLUG="tackquote"
MAIN_FILE="tackquote.php"
# Latest stable WordPress (major.minor) for the "Tested up to" check. There is no
# network call on purpose; bump this when WordPress ships, or pass --latest-wp.
LATEST_WP_DEFAULT="7.1"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SELF="$ROOT/bin/$(basename "${BASH_SOURCE[0]}")"

# Exit codes (an API: scripts and the orchestrator branch on them).
E_OK=0
E_USAGE=1     # bad arguments
E_REFUSED=2   # a precondition failed: dirty tree, tag collision, readme invalid, ...
E_BUILD=3     # the zip could not be built or unpacked
E_SANITY=4    # the working copy does not match the zip after syncing
E_ENV=5       # a required tool is missing

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
	C_RED=$'\033[31m'; C_GRN=$'\033[32m'; C_YEL=$'\033[33m'; C_BLD=$'\033[1m'; C_OFF=$'\033[0m'
else
	C_RED=''; C_GRN=''; C_YEL=''; C_BLD=''; C_OFF=''
fi

say()  { printf '%s\n' "$*"; }
step() { printf '%s==>%s %s\n' "$C_BLD" "$C_OFF" "$*"; }
ok()   { printf '  %sok%s   %s\n' "$C_GRN" "$C_OFF" "$*"; }
warn() { printf '  %swarn%s %s\n' "$C_YEL" "$C_OFF" "$*" >&2; }
die()  { # die <exit-code> <message> [fix]
	local code="$1"; shift
	printf '%sError:%s %s\n' "$C_RED" "$C_OFF" "$1" >&2
	[ -n "${2:-}" ] && printf '  Fix: %s\n' "$2" >&2
	exit "$code"
}

usage() {
	cat <<EOF
Stage a TackQuote release in a WordPress.org SVN working copy (never commits).

Usage:
  bin/wporg-sync.sh <svn-working-copy> [options]
  bin/wporg-sync.sh --check-readme [--latest-wp X.Y]
  bin/wporg-sync.sh --self-test

Options:
  --tag X.Y.Z      Expected release version. Refused unless it equals the
                   Version: header of $MAIN_FILE at HEAD (default: that version).
  --dry-run        Print what would change in trunk/, tags/<version>/ and
                   assets/, and leave the working copy untouched.
  --force-tag      Re-stage over an existing tags/<version>/ that is NOT yet
                   committed (a previous run of this script). Without it a
                   re-run refuses before touching anything. A tag that already
                   exists on wordpress.org is never overwritten.
  --check-readme   Validate readme.txt (wordpress.org readme rules, offline) and
                   run scripts/check-release-claims.sh. Alone: validate only.
                   With a working copy: the sync always validates; this flag
                   is accepted for clarity.
  --latest-wp X.Y  Latest stable WordPress for the "Tested up to" check
                   (default $LATEST_WP_DEFAULT; no network lookup is made).
  --self-test      Build into a throwaway local SVN repository and assert the
                   three mirrors, the refusals and idempotency.
  -h, --help       Show this help.

Examples:
  bin/wporg-sync.sh ../tackquote-wporg-svn --dry-run
  bin/wporg-sync.sh ../tackquote-wporg-svn --tag 1.10.0
  bin/wporg-sync.sh ../tackquote-wporg-svn --force-tag   # re-stage after main moved
  svn ci -m "Release 1.10.0" ../tackquote-wporg-svn      # the owner, afterwards

Exit codes:
  0 staged (or dry-run / check passed)   1 usage error
  2 refused: dirty git tree, tag collision, readme invalid, bad listing asset
  3 zip build failed                     4 working copy does not match the zip
  5 a required tool (svn, git, zip, unzip, rsync, perl, file) is missing
EOF
}

need() {
	local t
	for t in "$@"; do
		command -v "$t" >/dev/null 2>&1 || die $E_ENV "'$t' is not installed or not on PATH." "install it (macOS: brew install $t) and re-run."
	done
}

# ── small helpers ────────────────────────────────────────────────────────────

# Header value from a plugin main file, the way get_file_data() reads it.
plugin_header() { # <file> <Header Name>
	HDR="$2" perl -ne 'BEGIN{$k=quotemeta($ENV{HDR})} if (/^[ \t\/*#@]*$k:(.*)$/i) { $v=$1; $v=~s/\s*(?:\*\/|\?>).*$//; $v=~s/^\s+|\s+$//g; print $v; exit }' "$1"
}

# Header value from readme.txt (the block between the === name === line and the
# first blank line after it).
readme_header() { # <readme> <Header Name>
	HDR="$2" perl -ne 'BEGIN{$k=quotemeta($ENV{HDR})} next if $. == 1 && /^===/; last if /^\s*$/ && $seen; last if /^==/; if (/^\s*$k\s*:\s*(.*?)\s*$/i) { print $1; exit } $seen=1 if /\S/;' "$1"
}

# Port of Plugin Check's License_Utils::get_normalized_license().
normalize_license() {
	LIC="$1" perl -e '
		my $l = $ENV{LIC}; $l =~ s/^\s+|\s+$//g; $l =~ s/  / /g;
		for my $s (".", "http://www.gnu.org/licenses/old-licenses/gpl-2.0.html", "https://www.gnu.org/licenses/old-licenses/gpl-2.0.html", "https://www.gnu.org/licenses/gpl-3.0.html", " or later", "-or-later", "+") {
			my $p = rindex($l, $s);
			if ($p >= 0 && $p + length($s) == length($l)) { $l = substr($l, 0, $p); $l =~ s/^\s+|\s+$//g; }
		}
		$l =~ s/-//g;
		$l =~ s/GNU General Public License \(GPL\)/GPL/g;
		$l =~ s/GNU General Public License/GPL/g;
		$l =~ s/ version /v/g;
		$l =~ s/GPL\s*[-|\.]*\s*[v]?([0-9])(\.[0])?/GPL$1/i;
		$l =~ s/EUPL\s*[-|\.]*\s*[v]?([0-9]+)(\.[0-9]+)?/EUPL/ig;
		$l =~ s/Apache.*?([0-9])(\.[0])?/Apache$1/ig;
		$l =~ s/\.//g;
		print $l;'
}

# Compare dotted versions numerically: prints -1, 0 or 1.
vcmp() {
	A="$1" B="$2" perl -e 'my @a=split /\./,$ENV{A}; my @b=split /\./,$ENV{B}; for my $i (0..($#a>$#b?$#a:$#b)) { my $x=$a[$i]//0; my $y=$b[$i]//0; if ($x<$y){print -1;exit} if ($x>$y){print 1;exit} } print 0;'
}

# Sorted list of regular files under a directory (relative paths), never .svn.
file_list() { # <dir>
	(cd "$1" && find . -path ./.svn -prune -o -type f -print | sed 's|^\./||' | LC_ALL=C sort)
}

# Content fingerprint of a tree (paths + sha1), .svn included when asked: used by
# the self-test to prove a dry run or a refusal left the working copy untouched.
tree_hash() { # <dir>
	(cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 shasum | shasum | cut -c1-40)
}

# Join stdin lines with single spaces (no trailing space).
join_lines() { tr "\n" " " | sed -E "s/ +/ /g; s/^ //; s/ $//"; }

is_listing_png_name() { # <basename>
	case "$1" in
		screenshot-*.png | banner-*.png | icon-*.png) return 0 ;;
	esac
	return 1
}
is_listing_name_any_ext() {
	case "$1" in
		screenshot-* | banner-* | icon-*) return 0 ;;
	esac
	return 1
}

# ── readme validation (offline port of Plugin Check's Plugin_Readme_Check) ───
#
# Reference: plugin-check includes/Checker/Checks/Plugin_Repo/Plugin_Readme_Check.php
# and includes/Lib/Readme/Parser.php. Errors refuse (exit 2); warnings print.
README_ERRORS=0
r_err()  { printf '  %sFAIL%s %s\n' "$C_RED" "$C_OFF" "$*" >&2; README_ERRORS=$((README_ERRORS + 1)); }

validate_readme() { # <readme> <main-php> <listing-dir (holds screenshot-N.png)> <latest-wp>
	local readme="$1" main="$2" listing="$3" latest="$4"
	README_ERRORS=0

	if [ ! -s "$readme" ]; then
		r_err "readme.txt is missing or empty."
		return 0
	fi
	if ! head -n 1 "$readme" | grep -qE '^===[[:space:]]*[^=[:space:]].*===[[:space:]]*$'; then
		r_err "first line must be the plugin name header, e.g. '=== TackQuote for WooCommerce ==='."
	fi

	local version stable tested requires requires_php contributors license tags
	version="$(plugin_header "$main" 'Version')"
	stable="$(readme_header "$readme" 'Stable tag')"
	tested="$(readme_header "$readme" 'Tested up to')"
	requires="$(readme_header "$readme" 'Requires at least')"
	requires_php="$(readme_header "$readme" 'Requires PHP')"
	contributors="$(readme_header "$readme" 'Contributors')"
	license="$(readme_header "$readme" 'License')"
	tags="$(readme_header "$readme" 'Tags')"

	# Stable tag (check_stable_tag).
	if [ -z "$stable" ]; then
		r_err "'Stable tag' header is missing; set it to the Version in $MAIN_FILE ($version)."
	elif [ "$stable" = "trunk" ]; then
		r_err "'Stable tag: trunk' is not allowed; set it to $version."
	elif [ "$stable" != "$version" ]; then
		r_err "Stable tag $stable != Version $version in $MAIN_FILE. Make them equal before tagging."
	else
		ok "Stable tag $stable == Version $version"
	fi

	# Not a Plugin Check rule, but the same release contract: the runtime constant
	# (sent as X-TackQuote-Plugin-Version) must name the version being tagged.
	local const
	const="$(perl -ne 'if (/define\(\s*.TACK_QUOTES_VERSION.\s*,\s*.([^\x27"]+)./) { print $1; exit }' "$main")"
	if [ -n "$const" ] && [ "$const" != "$version" ]; then
		r_err "TACK_QUOTES_VERSION is $const but the Version header is $version in $MAIN_FILE."
	fi

	# Tested up to (check_headers + check_tested_up_to_mismatch). The brief for
	# this repo is stricter than Plugin Check's latest+0.1 allowance: <= latest.
	if [ -z "$tested" ]; then
		r_err "'Tested up to' header is missing."
	elif ! printf '%s' "$tested" | grep -qE '^[0-9]+\.[0-9]+(\.[0-9]+)?(-[A-Za-z0-9.]+)?$'; then
		r_err "'Tested up to: $tested' is not a WordPress version (e.g. $latest)."
	else
		local t_base t_major
		t_base="${tested%%-*}"
		t_major="$(printf '%s' "$t_base" | sed -E 's/^([0-9]+\.[0-9]+).*/\1/')"
		if [ "$t_major" = "$latest" ] && printf '%s' "$t_base" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+'; then
			r_err "'Tested up to: $tested' must name the major version only ($t_major)."
		fi
		case "$(vcmp "$t_major" "$latest")" in
			1) r_err "'Tested up to: $tested' is newer than the latest WordPress ($latest). If WordPress has shipped, pass --latest-wp." ;;
			-1) warn "'Tested up to: $tested' is older than the latest WordPress ($latest): the plugin is hidden from directory searches until it is raised." ;;
			*) ok "Tested up to $tested (latest WordPress $latest)" ;;
		esac
		local h_tested
		h_tested="$(plugin_header "$main" 'Tested up to')"
		if [ -n "$h_tested" ] && [ "${h_tested%%-*}" != "$t_base" ]; then
			r_err "'Tested up to' differs: readme $tested, $MAIN_FILE header $h_tested."
		fi
	fi

	# Requires at least / Requires PHP (present, and equal to the plugin header).
	local pair label rv hv
	for pair in "Requires at least|$requires|RequiresWP" "Requires PHP|$requires_php|RequiresPHP"; do
		label="${pair%%|*}"; rv="$(printf '%s' "$pair" | cut -d'|' -f2)"
		hv="$(plugin_header "$main" "$label")"
		if [ -z "$rv" ]; then
			r_err "'$label' header is missing from readme.txt."
		elif [ -n "$hv" ] && [ "$rv" != "$hv" ]; then
			r_err "'$label' differs: readme $rv, $MAIN_FILE header $hv. They must be identical."
		else
			ok "$label $rv"
		fi
	done

	[ -n "$contributors" ] || r_err "'Contributors' header is missing."

	# License (check_license): present, a valid identifier, GPL-compatible, and the
	# same as the plugin header after Plugin Check's normalisation.
	if [ -z "$license" ]; then
		r_err "'License' header is missing; use 'GPLv2 or later'."
	else
		local n_lic n_hdr h_lic
		n_lic="$(normalize_license "$license")"
		h_lic="$(plugin_header "$main" 'License')"
		if ! printf '%s' "$n_lic" | grep -qiE '^[a-z0-9.+-]+([[:space:]]or[[:space:]][a-z0-9.+-]+)*$'; then
			r_err "License '$license' is not a valid SPDX-style identifier."
		fi
		if ! printf '%s' "$license" | grep -qE 'GPL|EUPL|GNU|MIT|FreeBSD|New BSD|BSD-3-Clause|BSD 3 Clause|OpenLDAP|Expat|Apache2|MPL20|ISC|CC0|Unlicense|WTFPL'; then
			r_err "License '$license' is not GPL-compatible."
		fi
		if [ -n "$h_lic" ]; then
			n_hdr="$(normalize_license "$h_lic")"
			if [ "$n_lic" != "$n_hdr" ]; then
				r_err "License differs: readme '$license' ($n_lic), $MAIN_FILE '$h_lic' ($n_hdr)."
			else
				ok "License $license == $h_lic ($n_lic)"
			fi
		fi
	fi

	# Tags (Parser: more than 5 are dropped; 'plugin' and 'wordpress' are ignored).
	if [ -n "$tags" ]; then
		local ntags
		ntags="$(printf '%s' "$tags" | tr ',' '\n' | sed 's/^ *//; s/ *$//' | grep -c . || true)"
		[ "$ntags" -le 5 ] || r_err "$ntags tags; wordpress.org keeps the first 5 only."
		if printf '%s' "$tags" | tr ',' '\n' | sed 's/^ *//; s/ *$//' | grep -qixE 'plugin|wordpress'; then
			r_err "tags 'plugin' and 'wordpress' are not permitted."
		fi
	fi

	# Short description: first paragraph after the header block, <= 150 chars.
	local short
	short="$(perl -ne 'if ($s==0) { $s=1 if /^===/; next } if ($s==1) { $s=2 if /^\s*$/; next } if ($s==2) { next if /^\s*$/; last if /^==/; chomp; print; last }' "$readme")"
	if [ -z "$short" ]; then
		r_err "short description (the paragraph under the headers) is missing."
	else
		local slen
		slen="$(SHORT="$short" perl -CS -Mutf8 -e 'use Encode; print length(decode("UTF-8", $ENV{SHORT}))')"
		if [ "$slen" -gt 150 ]; then
			r_err "short description is $slen characters; wordpress.org trims it at 150."
		else
			ok "short description $slen/150 characters"
		fi
	fi
	if grep -qF 'Here is a short description of the plugin.' "$readme" || printf '%s' "$tags" | grep -qw 'tag1'; then
		r_err "readme still contains the template's default text."
	fi

	# Upgrade notices <= 300 characters each.
	local long_notice
	long_notice="$(perl -ne 'if (/^==\s*Upgrade Notice\s*==/i) { $in=1; next } if ($in && /^==[^=]/) { $in=0 } next unless $in; if (/^=\s*(.+?)\s*=\s*$/) { $v=$1; next } $len{$v} += length($_) if defined $v && /\S/; END { for (sort keys %len) { print "$_\n" if $len{$_} > 300 } }' "$readme")"
	[ -z "$long_notice" ] || r_err "upgrade notice(s) over 300 characters: $(printf '%s' "$long_notice" | tr '\n' ' ')"

	# Changelog section <= 5,000 characters. Plugin Check reports a longer one as
	# readme_parser_warnings_trimmed_section_changelog ("A maximum of 5000 characters
	# is supported") and wordpress.org shows it cut short. Plugin Check's bundled
	# parser (includes/Lib/Readme/Parser.php: 'section-changelog' => 5000, trimmed with
	# 'words') actually counts WORDS, so 5,000 characters is the stricter reading and
	# is what is enforced. Measured as the parser keeps the section: everything after
	# the "== Changelog ==" line up to the next "== ... ==" heading, in characters.
	# Older entries belong in changelog.txt, which ships in the zip.
	local cl_len cl_words
	read -r cl_len cl_words < <(perl -CI -e 'local $/; my $t = <STDIN>; if ($t =~ /^==\s*Changelog\s*==[ \t]*\r?\n(.*?)(?=^==[^=]|\z)/ims) { my $b = $1; my @w = grep { length } split(/\s+/, $b); print length($b), " ", scalar(@w), "\n" } else { print "-1 0\n" }' <"$readme")
	if [ "$cl_len" = "-1" ]; then
		warn "readme.txt has no == Changelog == section."
	elif [ "$cl_len" -gt 5000 ]; then
		r_err "== Changelog == is $cl_len characters ($cl_words words); wordpress.org supports at most 5000 and truncates the rest. Move older entries to changelog.txt."
	elif [ "$cl_len" -gt 4500 ]; then
		warn "== Changelog == is $cl_len/5000 characters; keep it under 4500 so the next entry fits (move older entries to changelog.txt)."
	else
		ok "changelog section $cl_len/5000 characters ($cl_words words)"
	fi

	# Screenshots: captions are matched to assets/screenshot-N.* BY NUMBER.
	local captions files_n expect_list have_list
	captions="$(perl -ne 'if (/^==\s*Screenshots\s*==/i) { $in=1; next } if ($in && /^==/) { last } print "$1\n" if $in && /^\s*([0-9]+)\.\s+\S/' "$readme")"
	files_n="$(cd "$listing" 2>/dev/null && ls -1 screenshot-*.png 2>/dev/null | sed -E 's/^screenshot-([0-9]+)\.png$/\1/' | grep -E '^[0-9]+$' | LC_ALL=C sort -n || true)"
	local n_caps n_files
	n_caps="$(printf '%s' "$captions" | grep -c . || true)"
	n_files="$(printf '%s' "$files_n" | grep -c . || true)"
	expect_list="$( [ "$n_caps" -gt 0 ] && seq 1 "$n_caps" | join_lines || true )"
	have_list="$(printf "%s\n" "$captions" | join_lines)"
	if [ "$n_caps" != "$n_files" ]; then
		r_err "== Screenshots == lists $n_caps caption(s) but .wordpress-org has $n_files screenshot-N.png file(s)."
	elif [ "$have_list" != "$expect_list" ]; then
		r_err "screenshot captions are not numbered 1..$n_caps in order (found: $have_list)."
	elif [ "$(printf "%s\n" "$files_n" | join_lines)" != "$expect_list" ]; then
		r_err "screenshot files are not screenshot-1..$n_files.png without gaps (found: $(printf "%s\n" "$files_n" | join_lines))."
	else
		ok "$n_caps screenshot captions == $n_files screenshot-N.png files"
	fi
	return 0
}

# Validate the COMMITTED readme (what ships) and run the claims check.
check_readme_cmd() { # <latest-wp>
	need git perl
	step "Validating readme.txt at HEAD ($(git -C "$ROOT" rev-parse --short HEAD))"
	local t
	t="$(mktemp -d "${TMPDIR:-/tmp}/wporg-readme.XXXXXX")"
	# shellcheck disable=SC2064
	trap "rm -rf '$t'" EXIT
	git -C "$ROOT" archive --format=tar HEAD readme.txt "$MAIN_FILE" .wordpress-org 2>/dev/null | tar -x -C "$t" \
		|| die $E_REFUSED "readme.txt or $MAIN_FILE is not committed at HEAD." "commit them, then re-run."
	if [ -n "$(git -C "$ROOT" status --porcelain -- readme.txt "$MAIN_FILE" .wordpress-org)" ]; then
		warn "readme.txt, $MAIN_FILE or .wordpress-org/ has uncommitted edits; the COMMITTED version is what was checked (and what ships)."
	fi
	validate_readme "$t/readme.txt" "$t/$MAIN_FILE" "$t/.wordpress-org" "$1"
	step "Running scripts/check-release-claims.sh"
	if ! bash "$ROOT/scripts/check-release-claims.sh"; then
		README_ERRORS=$((README_ERRORS + 1))
	fi
	if [ "$README_ERRORS" -gt 0 ]; then
		die $E_REFUSED "readme validation failed with $README_ERRORS error(s) (listed above)." "fix readme.txt, commit, and re-run bin/wporg-sync.sh --check-readme."
	fi
	say "${C_GRN}readme OK${C_OFF}"
}

# ── svn helpers ──────────────────────────────────────────────────────────────

# `svn status` prints 7 status columns, a space, then the path (col 9 onward).
svn_status_paths() { # <wc> <status-letter>   (first column)
	svn status "$1" | awk -v L="$2" 'substr($0,1,1)==L { print substr($0,9) }'
}

# Schedule every unversioned path for addition and every missing one for
# deletion, then set svn:mime-type on the listing PNGs.
svn_schedule() { # <wc>
	local wc="$1" p
	# Additions: an unversioned directory is added recursively by one call.
	svn_status_paths "$wc" '?' | while IFS= read -r p; do
		[ -n "$p" ] && svn add --no-ignore --parents -q -- "$p"
	done
	# Deletions: skip a path whose ancestor is already being removed.
	local prev='' list
	list="$(svn_status_paths "$wc" '!' | LC_ALL=C sort)"
	if [ -n "$list" ]; then
		printf '%s\n' "$list" | while IFS= read -r p; do
			if [ -n "$prev" ] && [ "${p#"$prev"/}" != "$p" ]; then continue; fi
			svn rm --force -q -- "$p"
			prev="$p"
		done
	fi
	# wordpress.org serves assets/ with the svn:mime-type the file carries; without
	# it a PNG is offered as application/octet-stream and downloaded, not shown.
	local f
	for f in "$wc"/assets/*.png; do
		[ -f "$f" ] || continue
		if [ "$(svn propget svn:mime-type -- "$f" 2>/dev/null || true)" != "image/png" ]; then
			svn propset -q svn:mime-type image/png -- "$f"
		fi
	done
}

# ── the sync ─────────────────────────────────────────────────────────────────

# Plan for mirroring <src> into <dst> (both may be partial). Prints lines
# "A path", "M path", "D path".
mirror_plan() { # <src> <dst>
	local src="$1" dst="$2" a b
	a="$(mktemp)"; b="$(mktemp)"
	file_list "$src" >"$a"
	if [ -d "$dst" ]; then file_list "$dst" >"$b"; else : >"$b"; fi
	LC_ALL=C comm -23 "$a" "$b" | sed 's/^/A /'
	LC_ALL=C comm -12 "$a" "$b" | while IFS= read -r f; do
		cmp -s "$src/$f" "$dst/$f" || printf 'M %s\n' "$f"
	done
	LC_ALL=C comm -13 "$a" "$b" | sed 's/^/D /'
	rm -f "$a" "$b"
}

print_plan() { # <label> <plan-text>
	local label="$1" plan="$2" na nm nd
	na="$(printf '%s' "$plan" | grep -c '^A ' || true)"
	nm="$(printf '%s' "$plan" | grep -c '^M ' || true)"
	nd="$(printf '%s' "$plan" | grep -c '^D ' || true)"
	say "  $label: $na added, $nm modified, $nd deleted"
	[ -z "$plan" ] || printf '%s\n' "$plan" | sed 's/^/      /'
}

sync_cmd() { # <wc> <tag> <dry-run 0|1> <force-tag 0|1> <latest-wp>
	local wc_in="$1" want_tag="$2" dry="$3" force="$4" latest="$5"
	need git svn zip unzip rsync perl file shasum

	[ -d "$wc_in" ] || die $E_USAGE "SVN working copy '$wc_in' does not exist." "check out the plugin first: svn co https://plugins.svn.wordpress.org/$PLUGIN_SLUG <dir>"
	local wc
	wc="$(cd "$wc_in" && pwd)"
	[ -d "$wc/.svn" ] || die $E_REFUSED "'$wc' is not the root of an SVN working copy (no .svn/)." "pass the directory you checked out with svn co."
	local d
	for d in trunk tags assets; do
		[ -d "$wc/$d" ] || die $E_REFUSED "'$wc/$d' is missing: this does not look like a wordpress.org plugin checkout." "run 'svn up' in '$wc', or check out https://plugins.svn.wordpress.org/$PLUGIN_SLUG again."
	done
	svn info "$wc" >/dev/null 2>&1 || die $E_REFUSED "'svn info $wc' failed: the working copy is damaged or from a newer svn." "run 'svn cleanup $wc'."
	if svn status "$wc" | grep -qE '^(C|.C|~|......C)'; then
		die $E_REFUSED "'$wc' has conflicts or obstructions." "resolve them (svn status $wc), then re-run."
	fi

	step "Checking the git tree ($ROOT)"
	if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
		git -C "$ROOT" status --short >&2
		die $E_REFUSED "the git working tree is dirty. The zip is built from HEAD, so these edits would NOT be released." "commit or discard them, then re-run."
	fi
	local head version
	head="$(git -C "$ROOT" rev-parse --short HEAD)"
	version="$(git -C "$ROOT" show "HEAD:$MAIN_FILE" | plugin_header /dev/stdin 'Version')"
	[ -n "$version" ] || die $E_REFUSED "no 'Version:' header in $MAIN_FILE at HEAD."
	printf '%s' "$version" | grep -qE '^[0-9]+\.[0-9]+(\.[0-9]+)?$' || die $E_REFUSED "Version '$version' is not X.Y[.Z]."
	if [ -n "$want_tag" ] && [ "$want_tag" != "$version" ]; then
		die $E_REFUSED "--tag $want_tag does not match Version $version in $MAIN_FILE at $head." "bump the version (header, TACK_QUOTES_VERSION, Stable tag) and commit, or pass --tag $version."
	fi
	ok "HEAD $head, version $version"

	# Tag collision. A tag already in the repository is a release: never touched.
	# A tag this script staged earlier (scheduled add, or unversioned) is
	# replaced only with --force-tag.
	local tagdir="$wc/tags/$version"
	if [ -e "$tagdir" ]; then
		local st
		st="$(svn status --depth empty "$tagdir" 2>/dev/null | cut -c1 || true)"
		if [ "$st" != "A" ] && [ "$st" != "?" ]; then
			die $E_REFUSED "tags/$version already exists in the wordpress.org repository; a released tag is never overwritten." "bump the version for a new release."
		fi
		if [ "$force" != 1 ]; then
			die $E_REFUSED "tags/$version is already staged in '$wc' (from an earlier run, not committed)." "re-run with --force-tag to restage trunk/ and tags/$version/ from HEAD $head."
		fi
		warn "tags/$version is staged from an earlier run; --force-tag: it will be restaged from HEAD $head."
	fi

	local work
	work="$(mktemp -d "${TMPDIR:-/tmp}/wporg-sync.XXXXXX")"
	# shellcheck disable=SC2064
	trap "rm -rf '$work'" EXIT

	step "Building $PLUGIN_SLUG.zip (scripts/package.sh)"
	if ! bash "$ROOT/scripts/package.sh" "$work/out" >"$work/build.log" 2>&1; then
		cat "$work/build.log" >&2
		die $E_BUILD "scripts/package.sh failed (log above)." "fix the build, commit, and re-run."
	fi
	[ -s "$work/out/$PLUGIN_SLUG.zip" ] || die $E_BUILD "scripts/package.sh did not produce $PLUGIN_SLUG.zip."
	mkdir "$work/unz"
	unzip -q "$work/out/$PLUGIN_SLUG.zip" -d "$work/unz" || die $E_BUILD "the built zip does not unpack."
	if [ "$(ls -A "$work/unz")" != "$PLUGIN_SLUG" ]; then
		die $E_BUILD "the zip's top level must be exactly '$PLUGIN_SLUG/' (found: $(ls -A "$work/unz" | tr '\n' ' '))."
	fi
	local src="$work/unz/$PLUGIN_SLUG"
	[ -f "$src/readme.txt" ] || die $E_BUILD "readme.txt is missing from the built zip." "readme.txt must be committed at the plugin root."
	[ "$(plugin_header "$src/$MAIN_FILE" 'Version')" = "$version" ] || die $E_BUILD "the built $MAIN_FILE is not version $version."
	local zip_bytes zip_files
	zip_bytes="$(wc -c <"$work/out/$PLUGIN_SLUG.zip" | tr -d ' ')"
	zip_files="$(file_list "$src" | grep -c . || true)"
	ok "$PLUGIN_SLUG.zip: $zip_files files, $zip_bytes bytes"

	# Listing assets come from HEAD too, never the working tree.
	git -C "$ROOT" archive --format=tar HEAD .wordpress-org | tar -x -C "$work" \
		|| die $E_REFUSED ".wordpress-org/ is not committed at HEAD."
	local listing="$work/.wordpress-org" assets_src="$work/assets-src" b
	mkdir "$assets_src"
	for f in "$listing"/*; do
		[ -f "$f" ] || continue
		b="$(basename "$f")"
		is_listing_name_any_ext "$b" || continue
		if ! is_listing_png_name "$b"; then
			die $E_REFUSED ".wordpress-org/$b: only PNG listing assets are synced (screenshot-N.png, banner-*.png, icon-*.png)." "convert it to PNG (and update readme.txt captions if it is a screenshot), commit, re-run."
		fi
		if [ "$(file -b --mime-type "$f")" != "image/png" ]; then
			die $E_REFUSED ".wordpress-org/$b is named .png but is $(file -b --mime-type "$f")." "re-export it as a real PNG and commit."
		fi
		cp "$f" "$assets_src/$b"
	done
	# assets/ in the working copy: a screenshot/banner/icon in another format
	# would compete with ours on the directory page.
	for f in "$wc"/assets/*; do
		[ -f "$f" ] || continue
		b="$(basename "$f")"
		if is_listing_name_any_ext "$b" && ! is_listing_png_name "$b"; then
			die $E_REFUSED "'$wc/assets/$b' is not a PNG; wordpress.org may show it instead of the PNGs this script syncs." "svn rm '$wc/assets/$b', then re-run."
		fi
	done

	step "Validating readme.txt (as built)"
	validate_readme "$src/readme.txt" "$src/$MAIN_FILE" "$listing" "$latest"
	if [ "$README_ERRORS" -gt 0 ]; then
		die $E_REFUSED "readme validation failed with $README_ERRORS error(s) (listed above); nothing was changed in '$wc'." "fix readme.txt, commit, re-run."
	fi
	if ! bash "$ROOT/scripts/check-release-claims.sh" >"$work/claims.log" 2>&1; then
		cat "$work/claims.log" >&2
		die $E_REFUSED "scripts/check-release-claims.sh failed; nothing was changed in '$wc'."
	fi
	ok "scripts/check-release-claims.sh"

	# Plans (also the dry-run report). The assets plan only covers listing PNGs:
	# anything else in assets/ (e.g. a blueprint) is left alone.
	local plan_trunk plan_tag plan_assets assets_cur="$work/assets-cur"
	mkdir "$assets_cur"
	for f in "$wc"/assets/*.png; do
		[ -f "$f" ] || continue
		b="$(basename "$f")"
		is_listing_png_name "$b" && cp "$f" "$assets_cur/$b"
	done
	plan_trunk="$(mirror_plan "$src" "$wc/trunk")"
	plan_tag="$(mirror_plan "$src" "$tagdir")"
	plan_assets="$(mirror_plan "$assets_src" "$assets_cur")"

	if [ "$dry" = 1 ]; then
		step "Dry run: what would change in $wc"
		print_plan "trunk/" "$plan_trunk"
		print_plan "tags/$version/" "$plan_tag"
		print_plan "assets/" "$plan_assets"
		say ""
		say "Nothing was written. Run again without --dry-run to stage it."
		return 0
	fi

	step "Mirroring the zip into trunk/ and tags/$version/"
	rsync -r --delete --checksum --exclude=.svn "$src/" "$wc/trunk/"
	mkdir -p "$tagdir"
	rsync -r --delete --checksum --exclude=.svn "$src/" "$tagdir/"

	step "Mirroring listing PNGs into assets/"
	cp "$assets_src"/*.png "$wc/assets/" 2>/dev/null || true
	for f in "$wc"/assets/*.png; do
		[ -f "$f" ] || continue
		b="$(basename "$f")"
		if is_listing_png_name "$b" && [ ! -f "$assets_src/$b" ]; then
			rm -f "$f" # stale (e.g. a screenshot that was dropped); svn rm below
		fi
	done

	step "Scheduling svn adds, deletes and svn:mime-type"
	svn_schedule "$wc"

	step "Verifying the working copy against the zip"
	local bad=0 diffout
	for d in "$wc/trunk" "$tagdir"; do
		if ! diffout="$(diff -rq -x .svn "$src" "$d" 2>&1)"; then
			printf '%s\n' "$diffout" >&2
			say "  ${d#"$wc"/} differs from the zip" >&2
			bad=1
		fi
	done
	if [ "$(file_list "$wc/trunk")" != "$(file_list "$src")" ]; then
		say "  trunk/ holds files that are not in the zip" >&2
		bad=1
	fi
	for f in "$assets_src"/*.png; do
		[ -f "$f" ] || continue
		cmp -s "$f" "$wc/assets/$(basename "$f")" || { say "  assets/$(basename "$f") differs" >&2; bad=1; }
	done
	if svn status "$wc" | grep -qE '^[?!~C]'; then
		svn status "$wc" | grep -E '^[?!~C]' >&2
		say "  unversioned, missing, obstructed or conflicted paths remain" >&2
		bad=1
	fi
	for f in "$wc"/assets/*.png; do
		[ -f "$f" ] || continue
		[ "$(svn propget svn:mime-type -- "$f" 2>/dev/null || true)" = "image/png" ] || { say "  assets/$(basename "$f") lacks svn:mime-type image/png" >&2; bad=1; }
	done
	[ "$(plugin_header "$tagdir/$MAIN_FILE" 'Version')" = "$version" ] || { say "  tags/$version/$MAIN_FILE is not version $version" >&2; bad=1; }
	[ "$(readme_header "$wc/trunk/readme.txt" 'Stable tag')" = "$version" ] || { say "  trunk/readme.txt Stable tag is not $version" >&2; bad=1; }
	[ "$bad" = 0 ] || die $E_SANITY "the staged working copy does not match the zip (above). Nothing was committed." "inspect 'svn status $wc'; 'svn revert -R $wc' discards the staging."
	ok "trunk/ and tags/$version/ == zip ($zip_files files); assets/ == .wordpress-org PNGs; svn:mime-type set"

	step "Staged in $wc"
	summary "$wc" "$version"
	cat <<EOF

${C_BLD}Next (the owner, once):${C_OFF}
  svn ci -m "Release $version" "$wc"

svn will prompt for the wordpress.org username and password (the plugin
committer account, case-sensitive). Nothing has been sent anywhere yet; to
discard this staging instead: svn revert -R "$wc"  (then delete tags/$version).
To restage after main moves: bin/wporg-sync.sh "$wc" --force-tag
EOF
}

summary() { # <wc> <version>
	local wc="$1" version="$2" st
	st="$(svn status "$wc")"
	local sec n_a n_m n_d n_p
	for sec in "trunk" "tags/$version" "assets"; do
		n_a="$(printf '%s\n' "$st" | awk -v P="$wc/$sec" 'substr($0,1,1)=="A" && index(substr($0,9),P)==1' | grep -c . || true)"
		n_m="$(printf '%s\n' "$st" | awk -v P="$wc/$sec" 'substr($0,1,1)=="M" && index(substr($0,9),P)==1' | grep -c . || true)"
		n_d="$(printf '%s\n' "$st" | awk -v P="$wc/$sec" 'substr($0,1,1)=="D" && index(substr($0,9),P)==1' | grep -c . || true)"
		n_p="$(printf '%s\n' "$st" | awk -v P="$wc/$sec" 'substr($0,2,1)=="M" && index(substr($0,9),P)==1' | grep -c . || true)"
		printf '  %-14s %3s added  %3s modified  %3s deleted  %3s props  %6s KB\n' "$sec/" "$n_a" "$n_m" "$n_d" "$n_p" \
			"$(du -sk "$wc/$sec" 2>/dev/null | cut -f1)"
	done
	printf '  %-14s %3s added  %3s modified  %3s deleted  %3s props\n' "total" \
		"$(printf '%s\n' "$st" | grep -c '^A' || true)" "$(printf '%s\n' "$st" | grep -c '^M' || true)" \
		"$(printf '%s\n' "$st" | grep -c '^D' || true)" "$(printf '%s\n' "$st" | grep -c '^.M' || true)"
}

# ── self-test ────────────────────────────────────────────────────────────────
#
# Clones this repository's HEAD (plus this script, committed into the clone) and
# a throwaway LOCAL svn repository created with `svnadmin load` from a generated
# dump: trunk/ with a stale file, assets/ with a stale screenshot, a released
# tags/1.0.0. Nothing is committed by svn, nothing leaves the machine.
ST_FAIL=0
st_ok()   { printf '  %sPASS%s %s\n' "$C_GRN" "$C_OFF" "$*"; }
st_bad()  { printf '  %sFAIL%s %s\n' "$C_RED" "$C_OFF" "$*"; ST_FAIL=$((ST_FAIL + 1)); }
st_eq()   { if [ "$2" = "$3" ]; then st_ok "$1"; else st_bad "$1 (expected '$3', got '$2')"; fi; }
# st_run <name> <want-rc> <reason-regex> -- <script args...>: runs the script under
# test, asserts the exit code AND that the output names the expected reason.
st_run() {
	local name="$1" want="$2" re="$3" rc=0; shift 4
	NO_COLOR=1 bash "$S" "$@" >"$T/out" 2>&1 || rc=$?
	if [ "$rc" = "$want" ] && grep -qE -- "$re" "$T/out"; then st_ok "$name"; else st_bad "$name (exit $rc, want $want, reason /$re/)"; sed "s/^/      | /" "$T/out" | tail -8; fi
}

dump_node_file() { # <path> <content>
	local len
	len="$(printf '%s' "$2" | wc -c | tr -d ' ')"
	printf 'Node-path: %s\nNode-kind: file\nNode-action: add\nProp-content-length: 10\nText-content-length: %s\nContent-length: %s\n\nPROPS-END\n%s\n\n' \
		"$1" "$len" "$((len + 10))" "$2"
}
dump_node_dir() { printf 'Node-path: %s\nNode-kind: dir\nNode-action: add\nProp-content-length: 10\nContent-length: 10\n\nPROPS-END\n\n\n' "$1"; }

make_fixture_wc() { # <dir> <released-tag>
	local dir="$1" rel="$2"
	mkdir -p "$dir"
	{
		printf 'SVN-fs-dump-format-version: 2\n\n'
		printf 'Revision-number: 1\nProp-content-length: 10\nContent-length: 10\n\nPROPS-END\n\n'
		dump_node_dir trunk
		dump_node_dir tags
		dump_node_dir assets
		dump_node_file trunk/old-file.php '<?php // removed in this release'
		dump_node_file trunk/readme.txt 'old readme'
		dump_node_file assets/screenshot-99.png 'stale screenshot'
		dump_node_dir "tags/$rel"
		dump_node_file "tags/$rel/readme.txt" 'released'
	} >"$dir/repo.dump"
	svnadmin create "$dir/repo"
	svnadmin load -q "$dir/repo" <"$dir/repo.dump"
	svn checkout -q "file://$dir/repo" "$dir/wc"
}

self_test_cmd() {
	need git svn svnadmin zip unzip rsync perl file shasum
	local T rc v wc wc2 h1 h2
	T="$(mktemp -d "${TMPDIR:-/tmp}/wporg-selftest.XXXXXX")"
	T="$(cd "$T" && pwd -P)"
	# shellcheck disable=SC2064
	trap "rm -rf '$T'" EXIT
	step "Self-test in $T"

	git clone -q "$ROOT" "$T/repo"
	git -C "$T/repo" checkout -q "$(git -C "$ROOT" rev-parse HEAD)"
	cp "$SELF" "$T/repo/bin/wporg-sync.sh"
	if [ -n "$(git -C "$T/repo" status --porcelain)" ]; then
		git -C "$T/repo" add -- bin/wporg-sync.sh
		git -C "$T/repo" -c user.name=selftest -c user.email=selftest@invalid commit -q -m "self-test: script under test" -- bin/wporg-sync.sh
	fi
	S="$T/repo/bin/wporg-sync.sh"
	v="$(plugin_header "$T/repo/$MAIN_FILE" 'Version')"
	make_fixture_wc "$T/f1" "1.0.0"
	wc="$T/f1/wc"

	say "-- --check-readme"
	rc=0; NO_COLOR=1 bash "$S" --check-readme >"$T/out" 2>&1 || rc=$?
	st_eq "--check-readme passes on HEAD" "$rc" 0

	say "-- --dry-run leaves the working copy untouched"
	h1="$(tree_hash "$wc")"
	rc=0; NO_COLOR=1 bash "$S" "$wc" --dry-run >"$T/out" 2>&1 || rc=$?
	st_eq "dry-run exit" "$rc" 0
	h2="$(tree_hash "$wc")"
	st_eq "dry-run wrote nothing (.svn included)" "$h2" "$h1"
	grep -q '^  trunk/: .* added, .* modified, 1 deleted' "$T/out" && st_ok "dry-run reports trunk/old-file.php as a delete" || st_bad "dry-run trunk plan: $(grep 'trunk/:' "$T/out")"
	grep -q "D screenshot-99.png" "$T/out" && st_ok "dry-run reports the stale screenshot" || st_bad "dry-run assets plan lacks the stale screenshot"

	say "-- real run"
	rc=0; NO_COLOR=1 bash "$S" "$wc" --tag "$v" >"$T/out" 2>&1 || rc=$?
	st_eq "sync exit" "$rc" 0
	[ "$rc" = 0 ] || sed 's/^/      | /' "$T/out"
	(cd "$T" && rm -rf z && mkdir z && unzip -q "$T/repo/dist/$PLUGIN_SLUG.zip" -d z)
	st_eq "trunk/ file list == zip" "$(file_list "$wc/trunk")" "$(file_list "$T/z/$PLUGIN_SLUG")"
	st_eq "tags/$v/ file list == zip" "$(file_list "$wc/tags/$v")" "$(file_list "$T/z/$PLUGIN_SLUG")"
	diff -rq -x .svn "$T/z/$PLUGIN_SLUG" "$wc/trunk" >/dev/null && st_ok "trunk/ content == zip" || st_bad "trunk/ content differs from zip"
	local want_assets
	want_assets="$(cd "$T/repo/.wordpress-org" && ls -1 | grep -E '^(screenshot|banner|icon)-.*\.png$' | LC_ALL=C sort)"
	st_eq "assets/ == listing PNGs (stale screenshot gone)" "$(cd "$wc/assets" && ls -1 | LC_ALL=C sort)" "$want_assets"
	st_eq "svn: old trunk file scheduled for delete" "$(svn status "$wc/trunk/old-file.php" | cut -c1)" "D"
	st_eq "svn: stale screenshot scheduled for delete" "$(svn status "$wc/assets/screenshot-99.png" | cut -c1)" "D"
	st_eq "svn: tags/$v scheduled for add" "$(svn status --depth empty "$wc/tags/$v" | cut -c1)" "A"
	st_eq "svn: nothing unversioned or missing" "$(svn status "$wc" | grep -cE '^[?!~C]' || true)" "0"
	st_eq "svn:mime-type on screenshot-1.png" "$(svn propget svn:mime-type "$wc/assets/screenshot-1.png")" "image/png"
	st_eq "svn:mime-type on every asset PNG" "$(for f in "$wc"/assets/*.png; do svn propget svn:mime-type "$f"; done | sort -u)" "image/png"
	st_eq "tags/$v/$MAIN_FILE Version" "$(plugin_header "$wc/tags/$v/$MAIN_FILE" Version)" "$v"
	st_eq "trunk/readme.txt Stable tag" "$(readme_header "$wc/trunk/readme.txt" 'Stable tag')" "$v"
	grep -qF "svn ci -m \"Release $v\" \"$wc\"" "$T/out" && st_ok "prints the owner's commit command" || st_bad "commit command not printed"

	say "-- idempotency"
	h1="$(tree_hash "$wc")"
	rc=0; NO_COLOR=1 bash "$S" "$wc" >"$T/out" 2>&1 || rc=$?
	st_eq "re-run without --force-tag refuses" "$rc" 2
	grep -q -- '--force-tag' "$T/out" && st_ok "the refusal names --force-tag" || st_bad "refusal does not name --force-tag"
	st_eq "refused re-run wrote nothing" "$(tree_hash "$wc")" "$h1"
	local st1 st2
	st1="$(svn status "$wc")"
	rc=0; NO_COLOR=1 bash "$S" "$wc" --force-tag >"$T/out" 2>&1 || rc=$?
	st_eq "re-run with --force-tag" "$rc" 0
	st2="$(svn status "$wc")"
	st_eq "re-run is idempotent (same svn status)" "$st2" "$st1"
	st_eq "re-run is idempotent (same tree)" "$(tree_hash "$wc")" "$h1"

	say "-- refusals"
	gc() { git -C "$T/repo" -c user.name=selftest -c user.email=selftest@invalid "$@"; }
	make_fixture_wc "$T/f2" "$v"
	make_fixture_wc "$T/f3" "1.0.0"
	wc2="$T/f2/wc"
	local wc3="$T/f3/wc"
	st_run "--tag mismatch refuses" 2 'does not match Version' -- "$wc" --tag 99.0.0 --force-tag
	h1="$(tree_hash "$wc2")"
	st_run "a released tag is never overwritten, even with --force-tag" 2 'never overwritten' -- "$wc2" --force-tag
	st_eq "released-tag refusal wrote nothing" "$(tree_hash "$wc2")" "$h1"
	mkdir -p "$T/notwc/trunk" "$T/notwc/tags" "$T/notwc/assets"
	st_run "a directory without .svn refuses" 2 'not the root of an SVN working copy' -- "$T/notwc"

	printf 'dirty\n' >>"$T/repo/readme.txt"
	st_run "a dirty git tree refuses" 2 'working tree is dirty' -- "$wc3" --dry-run
	git -C "$T/repo" checkout -q -- readme.txt

	perl -pi -e "s/^Stable tag: .*/Stable tag: 0.0.1/" "$T/repo/readme.txt"
	gc commit -q -m "mutant: stable tag" -- readme.txt
	st_run "--check-readme refuses Stable tag != Version" 2 'Stable tag 0.0.1 != Version' -- --check-readme
	st_run "sync refuses Stable tag != Version (before writing)" 2 'Stable tag 0.0.1 != Version' -- "$wc3"
	st_eq "readme refusal wrote nothing" "$(svn status "$wc3" | grep -c . || true)" "0"
	gc revert --no-edit HEAD >/dev/null

	perl -pi -e "s/define\( 'TACK_QUOTES_VERSION', '[^']+' \)/define( 'TACK_QUOTES_VERSION', '0.0.2' )/" "$T/repo/$MAIN_FILE"
	gc commit -q -m "mutant: version constant" -- "$MAIN_FILE"
	st_run "--check-readme refuses TACK_QUOTES_VERSION != Version" 2 'TACK_QUOTES_VERSION is 0.0.2' -- --check-readme
	gc revert --no-edit HEAD >/dev/null

	perl -pi -e "s/^Tested up to: .*/Tested up to: 9.9/" "$T/repo/readme.txt"
	gc commit -q -m "mutant: tested up to" -- readme.txt
	st_run "--check-readme refuses Tested up to > latest WordPress" 2 'newer than the latest WordPress' -- --check-readme
	gc revert --no-edit HEAD >/dev/null

	perl -pi -e "s/^Tested up to: .*/Tested up to: 7.1.2/" "$T/repo/readme.txt"
	gc commit -q -m "mutant: tested up to minor" -- readme.txt
	st_run "--check-readme refuses a minor in Tested up to" 2 'major version only' -- --check-readme --latest-wp 7.1
	gc revert --no-edit HEAD >/dev/null

	perl -0pi -e "s/^Requires PHP: .*\n//m" "$T/repo/readme.txt"
	gc commit -q -m "mutant: requires php" -- readme.txt
	st_run "--check-readme refuses a missing Requires PHP" 2 "'Requires PHP' header is missing" -- --check-readme
	gc revert --no-edit HEAD >/dev/null

	perl -pi -e "s/^Requires at least: .*/Requires at least: 6.0/" "$T/repo/readme.txt"
	gc commit -q -m "mutant: requires at least" -- readme.txt
	st_run "--check-readme refuses Requires at least != plugin header" 2 "'Requires at least' differs" -- --check-readme
	gc revert --no-edit HEAD >/dev/null

	perl -pi -e "s/^License: .*/License: Proprietary/" "$T/repo/readme.txt"
	gc commit -q -m "mutant: license" -- readme.txt
	st_run "--check-readme refuses a non-GPL license" 2 'not GPL-compatible' -- --check-readme
	gc revert --no-edit HEAD >/dev/null

	perl -e 'print "* ", "x" x 5100, "\n"' >>"$T/repo/readme.txt"
	gc commit -q -m "mutant: changelog over 5000 characters" -- readme.txt
	st_run "--check-readme refuses a changelog over 5000 characters" 2 'supports at most 5000' -- --check-readme
	gc revert --no-edit HEAD >/dev/null

	local last
	last="$(cd "$T/repo/.wordpress-org" && ls -1 screenshot-*.png | sed -E 's/screenshot-([0-9]+)\.png/\1/' | sort -n | tail -1)"
	cp "$T/repo/.wordpress-org/screenshot-1.png" "$T/repo/.wordpress-org/screenshot-$((last + 1)).png"
	git -C "$T/repo" add .wordpress-org
	gc commit -q -m "mutant: extra screenshot"
	st_run "--check-readme refuses screenshots != captions" 2 'Screenshots == lists' -- --check-readme
	gc revert --no-edit HEAD >/dev/null

	printf 'not an image\n' >"$T/repo/.wordpress-org/banner-9x9.png"
	git -C "$T/repo" add .wordpress-org
	gc commit -q -m "mutant: fake png"
	st_run "a .png that is not a PNG refuses" 2 'named .png but is' -- "$wc3" --dry-run
	gc revert --no-edit HEAD >/dev/null

	cp "$T/repo/.wordpress-org/icon-128x128.png" "$T/repo/.wordpress-org/icon-128x128.jpg"
	git -C "$T/repo" add .wordpress-org
	gc commit -q -m "mutant: jpg listing asset"
	st_run "a non-PNG listing asset refuses" 2 'only PNG listing assets' -- "$wc3" --dry-run
	gc revert --no-edit HEAD >/dev/null

	st_run "an unknown flag is a usage error" 1 "unknown option '--bogus'" -- "$wc3" --bogus
	st_run "the final tree passes again (mutants reverted)" 0 'readme OK' -- --check-readme

	say ""
	if [ "$ST_FAIL" -gt 0 ]; then
		say "${C_RED}self-test: $ST_FAIL failure(s)${C_OFF}"
		exit 1
	fi
	say "${C_GRN}self-test: all checks passed${C_OFF}"
}

# ── main ─────────────────────────────────────────────────────────────────────

main() {
	local wc='' tag='' dry=0 force=0 check=0 selftest=0 latest="$LATEST_WP_DEFAULT"
	if [ "$#" -eq 0 ]; then usage; exit $E_USAGE; fi
	while [ "$#" -gt 0 ]; do
		case "$1" in
			-h | --help) usage; exit $E_OK ;;
			--dry-run) dry=1 ;;
			--force-tag) force=1 ;;
			--check-readme) check=1 ;;
			--self-test) selftest=1 ;;
			--tag)
				[ "$#" -ge 2 ] || die $E_USAGE "--tag needs a version, e.g. --tag 1.10.0."
				tag="$2"; shift ;;
			--tag=*) tag="${1#--tag=}" ;;
			--latest-wp)
				[ "$#" -ge 2 ] || die $E_USAGE "--latest-wp needs a version, e.g. --latest-wp 7.1."
				latest="$2"; shift ;;
			--latest-wp=*) latest="${1#--latest-wp=}" ;;
			-*) die $E_USAGE "unknown option '$1'." "run bin/wporg-sync.sh --help." ;;
			*)
				[ -z "$wc" ] || die $E_USAGE "only one working copy can be given (got '$wc' and '$1')."
				wc="$1" ;;
		esac
		shift
	done
	printf '%s' "$latest" | grep -qE '^[0-9]+\.[0-9]+$' || die $E_USAGE "--latest-wp must be X.Y (got '$latest')."
	if [ -n "$tag" ] && ! printf '%s' "$tag" | grep -qE '^[0-9]+\.[0-9]+(\.[0-9]+)?$'; then
		die $E_USAGE "--tag must be X.Y.Z (got '$tag')."
	fi

	if [ "$selftest" = 1 ]; then self_test_cmd; exit $E_OK; fi
	if [ -z "$wc" ]; then
		[ "$check" = 1 ] || die $E_USAGE "no SVN working copy given." "bin/wporg-sync.sh <svn-working-copy> [--dry-run], or --check-readme alone."
		check_readme_cmd "$latest"
		exit $E_OK
	fi
	sync_cmd "$wc" "$tag" "$dry" "$force" "$latest"
}

main "$@"
