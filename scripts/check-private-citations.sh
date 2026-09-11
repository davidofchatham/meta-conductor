#!/usr/bin/env bash
#
# Fail if a committed file cites a private path.
#
# .scratch/ and .claude/ resolve for exactly one person. A committed file pointing
# there fails SILENTLY for every other reader: it looks like a working relative
# link, so nothing ever reports it. When the tracker alignment landed, 16 such
# citations were dangling across five committed files -- ROADMAP.md, CHANGELOG.md,
# docs/architecture.md, docs/storage-model.md and the future-work index -- left
# behind months earlier when the plans moved from .claude/plans/ to .scratch/plans/.
# Nothing followed them and nothing complained.
#
# Cite a live plan through its FW-N row in docs/future-work.md. Cite a plan by path
# only once it is finished and lifted into docs/design-history/.
#
# WHAT IS NOT A VIOLATION: naming the CONVENTION. docs/agents/ has to write
# ".scratch/<slug>/spec.md" to describe the layout at all, so a path containing a
# <placeholder> or a * glob is a pattern, not a citation. Only fully literal paths
# are citations, and only those fail.
#
# ONE EXEMPTION: docs/future-work.md -- the tracker IS the sanctioned index over
# private homes. Pointing at them is its entire job. It is the one file allowed to
# hold the path, which is exactly what lets every other file be held to the rule.
#
# docs/design-history/ is NOT exempt, unlike the origin repo's frozen spec-history.
# A plan is sanitised as part of being lifted there, and a lifted plan citing a
# still-private sibling is the same dangling pointer one directory over.

set -uo pipefail

# Every committed prose surface, plus CLAUDE.md.
#
# CLAUDE.md is gitignored here and stays that way (the repo is public and the file
# names client sites and testbed containers), so it is not "committed prose" -- but
# it is the densest caller of .scratch/plans/ in the tree, and when a plan moves its
# links rot exactly like a tracked file's. Private authorship does not make a dead
# link resolve. Scanning it costs one pathspec entry.
SCOPE=(includes docs tools tests scripts README.md readme.txt CHANGELOG.md CONTEXT.md ROADMAP.md CLAUDE.md)

# A literal path: no '<', no '*'. Anchored on a .md so a bare directory mention
# ("plans live in .scratch/plans/") is prose, not a pointer. Matches any .claude/
# subtree, not just .claude/plans/ -- the private harness tree has several and all
# are equally unreadable to anyone else.
PATTERN='(\.scratch|\.claude)(/[A-Za-z0-9._-]+)+\.md'

# --untracked so a NEW file's violations surface before it is committed rather than
# after. --no-exclude-standard on top of it because CLAUDE.md is gitignored here and
# would otherwise be skipped by the very flag meant to widen the scan; the SCOPE
# pathspec is what keeps .scratch/ and vendor/ out, not the ignore rules.
#
# git grep exits 1 for "no matches" and >1 for a real error. Collapsing both to
# "clean" would permanent-green this guard the day a path argument goes stale --
# the same silent-failure class it exists to catch, one level up.
raw=$(git grep -nIE --untracked --no-exclude-standard "$PATTERN" -- "${SCOPE[@]}" 2>&1)
status=$?
if [ "$status" -gt 1 ]; then
	echo "::error::git grep failed (exit ${status}); the guard did NOT run"
	printf '%s\n' "$raw" | sed 's/^/    /'
	exit 2
fi
[ "$status" -eq 1 ] && raw=""

hits=$(printf '%s\n' "$raw" \
	| grep -v '^docs/future-work\.md:' \
	| grep -v '^scripts/check-private-citations\.sh:' || true)

if [ -n "$hits" ]; then
	echo "::error::Committed file cites a private path"
	printf '%s\n' "$hits" | sed 's/^/    /'
	cat <<-'MSG'

	These resolve for one person and fail silently for everyone else. Repair:
	  - live plan      -> cite its FW-N row in docs/future-work.md
	  - finished plan  -> lift it into docs/design-history/ (it MOVES, no copy
	                      left behind) and cite that path
	  - a memory file  -> name it, do not link it: memory lives outside the repo
	MSG
	exit 1
fi

echo "No private paths cited by committed files."
