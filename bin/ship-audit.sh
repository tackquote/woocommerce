#!/usr/bin/env bash
#
# Release audit: the shipped zip may contain only what a merchant or a
# WordPress.org reviewer should read.
#
# Unzips the built package and searches every text file in it for internal
# project notes (work-item and defect ids, internal review names, test-run notes,
# paths into other repositories), test data (sample SKUs and prices, test
# emails, test keys) and debugging leftovers. Any hit fails the audit, so a
# release cannot ship them. Comments should say what the code does and why, in
# product terms; project history belongs in git and the pull request.
#
# Usage: bin/ship-audit.sh [path/to/tackquote.zip]     (default: dist/tackquote.zip)
# Exit:  0 clean, 1 a hit (each one listed), 2 usage or tooling error.

set -Eeuo pipefail
export LC_ALL=C

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="${1:-$ROOT/dist/tackquote.zip}"

if [ ! -f "$ZIP" ]; then
	echo "ship-audit: no zip at $ZIP (build it with bin/build.sh or scripts/package.sh)." >&2
	exit 2
fi
command -v unzip >/dev/null 2>&1 || { echo "ship-audit: unzip is required." >&2; exit 2; }

# One pattern per line (POSIX ERE, case-sensitive), joined with | below.
DENY_PATTERNS=(
	# Internal work items, reviews and test runs.
	'\b[WS][0-9]{1,2}\b'                 # work-lane ids (W10, S3)
	'\bW[0-9]+-[a-z]'                    # named lanes (W1-forms)
	'\b[Ll]ane\b'
	'[(,] ?D[0-9]{1,2}\b'                 # defect ids: (D4), ", D10"
	'\b[Dd]efect D[0-9]'
	'E2E'
	'\b[Aa]ttempt [0-9]'
	'\b[A-Z]{1,2}-[0-9]{1,2}\b'          # audit finding ids (L-3, F-12)
	'\b[Aa]udit finding'
	'\bPR ?#[0-9]+'
	'pull/[0-9]+'
	'\btack #[0-9]+'
	'\(#[0-9]+\)'
	'\b[Pp]arity row'
	'[Oo]rchestrator'
	'\b(Fable|Opus|Sonnet|Claude)\b'
	'\b[Oo]wner report'
	'\bStudio\b'
	'[Dd]ev ?store'
	'[Vv]erified (on|against|live)'
	'\bcommit [0-9a-f]{7,}'
	'merge-base'
	# Paths into other repositories and internal notes.
	'apps/(api|web)/'
	'packages/widget'
	'/Volumes/'
	'tack-notes'
	'HANDOFF'
	'\.(spec|service|guard|core|controller|dto)\.ts\b'
	'\b(Submit[A-Za-z]*|[A-Za-z]+Plugin[A-Za-z]*)Dto\b'
	# Test data.
	'TQ-[A-Z]'
	'\b(47\.50|42\.00)\b'
	'example\.(com|org|net)'
	'@[A-Za-z0-9.-]+\.test\b'
	'-test-'                             # test slugs and ids
	'\b(sk|pk)_(test|live)_'
	'tack_sk_'
	'\b([Tt]est|[Dd]emo|[Dd]ummy|[Ss]ample) (store|shop|site|data|product|customer|order|key|email)'
	'\bAcme\b'
	'[Ll]orem ipsum'
	# Debugging leftovers and local addresses.
	'var_dump'
	'print_r\('
	'console\.log'
	'\bdebugger\b'
	'TODO'
	'FIXME'
	'XXX'
	'localhost'
	'127\.0\.0\.1'
	'\.local\b'
)

# Exact substrings that are allowed although a pattern above matches them. Each
# is removed from a matching line before the line is tested again, so another
# hit on the same line still fails.
ALLOW_SUBSTRINGS=(
	# The email field's placeholder (RFC 2606 reserved domain).
	'you@example.com'
	# DOM id of the settings page's "Test connection" card.
	'tack-test-heading'
	# Tack_Settings::is_non_public_host(): the reserved development hosts the
	# connection check refuses to call over plain HTTP. Code, not a note.
	"'localhost' === \$host"
	"array( '.local', '.localhost', '.test', '.internal' )"
)

DENY="$(IFS='|'; echo "${DENY_PATTERNS[*]}")"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
unzip -q "$ZIP" -d "$WORK"

FILES=$(cd "$WORK" && find . -type f | wc -l | tr -d ' ')
if [ "$FILES" -eq 0 ]; then
	echo "ship-audit: $ZIP is empty." >&2
	exit 2
fi

# grep exits 1 for "no match"; anything above 1 is a real error.
set +e
CANDIDATES=$(cd "$WORK" && grep -rnIE -- "$DENY" .)
RC=$?
set -e
if [ "$RC" -gt 1 ]; then
	echo "ship-audit: grep failed (exit $RC)." >&2
	exit 2
fi

HITS=""
if [ -n "$CANDIDATES" ]; then
	while IFS= read -r LINE; do
		REST="$LINE"
		for A in "${ALLOW_SUBSTRINGS[@]}"; do
			REST="${REST//"$A"/}"
		done
		# Test only the matched text, not the "./path:line:" prefix.
		BODY="${REST#*:}"
		BODY="${BODY#*:}"
		if printf '%s\n' "$BODY" | grep -qE -- "$DENY"; then
			HITS+="$LINE"$'\n'
		fi
	done <<< "$CANDIDATES"
fi

if [ -n "$HITS" ]; then
	COUNT=$(printf '%s' "$HITS" | grep -c '')
	echo "SHIP AUDIT FAILED: $COUNT line(s) in $(basename "$ZIP") carry internal notes, test data or debug code:" >&2
	printf '%s' "$HITS" | cut -c1-240 | sed 's/^\.\//  /' >&2
	echo "Rewrite each in product terms (what the code does and why), rebuild and re-run." >&2
	exit 1
fi

echo "ship-audit: clean ($FILES files in $(basename "$ZIP"))."
