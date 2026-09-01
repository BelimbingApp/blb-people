#!/usr/bin/env bash
# Project-local orientation for BelimbingApp/blb-people.
#
# This is the one file an adopting repository replaces: repository-specific
# facts — assembly shape, live hazards, project commands — belong here, so the
# guide and the generic mechanisms beside it stay portable. It sits outside
# docs/ai-team/, so a package refresh never overwrites it.
#
# blb-people is not a standalone application. It is the People domain, composed
# into the Belimbing platform at app/Domains/People. Nothing here runs on its
# own, and that shapes almost everything below.

# orient.sh exports AI_TEAM_DEFAULT_BRANCH before invoking this hook — copied
# out to .ai-team/ at the repository root (#8), this file has no relative path
# back to docs/ai-team/scripts/_default_branch.sh, so it cannot resolve the
# branch itself. The fallback covers a direct run of this hook outside orient.sh.
set -u
BASE="${AI_TEAM_DEFAULT_BRANCH:-$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null | sed 's#^origin/##')}"
BASE="${BASE:-main}"

echo "== project: toolchain the mechanisms need =="
for tool in bash python3 git gh jq; do
  if command -v "$tool" >/dev/null 2>&1; then
    case "$tool" in
      bash)    version=$(bash --version | head -1) ;;
      python3) version=$(python3 --version) ;;
      git)     version=$(git --version) ;;
      gh)      version=$(gh --version | head -1) ;;
      jq)      version=$(jq --version) ;;
      # The loop list and these arms are maintained together; a tool added to
      # one without the other still prints its name instead of a blank.
      *)       version=$tool ;;
    esac
    echo "  ok      $version"
  else
    echo "  MISSING $tool is not on PATH — mechanisms that call it will fail"
  fi
done

echo
echo "== project: is $BASE green right now? =="
# The single most useful thing this hook can say. `ci / ci` is a required check
# on the default branch, so while it is red nothing merges here at all — and
# CI runs rarely enough (no schedule) that main can sit red for weeks. Checking
# costs one API call and has already saved one agent from being blamed for a
# break they inherited.
# `empty` on a zero-length result keeps an unread history distinguishable from a
# read one. The conclusion is normalized to the sentinel "pending" rather than
# left empty, because tab is an IFS *whitespace* character: `IFS=$'\t' read`
# strips a leading empty field instead of preserving it, so every variable would
# shift one place left and the in-progress arm could never match.
ci_state=$(gh run list --branch "$BASE" --workflow ci.yml --limit 1 \
  --json conclusion,headSha,createdAt \
  --jq 'if length == 0 then empty else .[0]
        | [((.conclusion // "") | if . == "" then "pending" else . end),
           .headSha[0:8], .createdAt] | @tsv end' 2>/dev/null)
if [ -z "$ci_state" ]; then
  echo "  unknown — could not read CI history; check before assuming you can land"
else
  IFS=$'\t' read -r conclusion head_sha created_at <<<"$ci_state"
  case "$conclusion" in
    success)
      printf '  ok      ci passed on %s (%s)\n' "$head_sha" "$created_at" ;;
    failure|timed_out|cancelled)
      printf '  *** %s IS RED — ci %s on %s (%s) ***\n' "$BASE" "$conclusion" "$head_sha" "$created_at"
      echo '  ci / ci is a REQUIRED check: no PR can merge until it passes.'
      echo "  Do not assume your branch caused it. Confirm against $BASE first." ;;
    pending)
      printf '  note    latest ci run on %s is still in progress (%s)\n' "$BASE" "$head_sha" ;;
    *)
      printf '  note    latest ci run on %s is %s (%s)\n' "$BASE" "$conclusion" "$head_sha" ;;
  esac
fi

echo
echo "== project: what this domain ships =="
# --full-tree, or ls-tree scopes to the current prefix and a run from a
# subdirectory silently undercounts everything below.
#
# Keyed on ServiceProvider.php, not composer.json: the platform discovers
# modules by path convention, and Employees/ ships a provider without its own
# composer.json. Keying on the manifest hid a real module (7 modules, not 6)
# while the test count below still counted its files — the block contradicted
# itself.
tracked=$(git ls-tree -r --full-tree --name-only "origin/$BASE" 2>/dev/null)
modules=$(printf '%s\n' "$tracked" | grep -E '^[A-Z][A-Za-z]*/ServiceProvider\.php$' | cut -d/ -f1 | sort)
if [ -n "$modules" ]; then
  # paste -sd', ' would alternate the two delimiter characters, not use both
  # between every pair; join on a comma and space it out afterwards.
  printf '  modules   %s\n' "$(printf '%s\n' "$modules" | paste -sd, - | sed 's/,/, /g')"
else
  printf '  modules   none resolved from origin/%s\n' "$BASE"
fi
printf '  tests     %s test file(s)\n' \
  "$(printf '%s\n' "$tracked" | grep -c 'Tests/.*Test\.php$')"

cat <<'TXT'

== project: hazards worth knowing before you claim ==
  linear history   main forbids merge commits. land.sh hardcodes merge_method=merge
                   and fails with a 405 whose text wrongly blames a missing
                   approval. Squash at the reviewed SHA, then re-run land.sh to
                   terminalize. Tracked upstream as BelimbingApp/ai-team#66.

  closing links    Put closing references in PR body text only. Never set the
                   GitHub Development-panel link: a lane gated as issue-less can
                   still close an issue through it. Tracked as ai-team#67.

  rare CI          CI runs only on push, PR, and manual dispatch — there is no
                   schedule. This repository carried a bug that fails one day a
                   month and it survived at least two of them undetected, until
                   an unrelated PR tripped over it and looked responsible. Check
                   whether main is already red before blaming your branch. #47
                   adds a schedule; note its original body blames platform drift,
                   which was disproven — see the correction comment on it.

  bare date compare  Comparing a date column to a 'Y-m-d' string with plain
                   where() is the most common defect class here — a sweep found
                   ~23 predicates across 12 files. SQLite stores those columns as
                   'Y-m-d H:i:s', so a range compare misses on boundary dates and
                   an equality NEVER matches, which is worse: it fails silently
                   every day and its tests pass for the wrong reason. Postgres
                   truncates, so these are invisible in production and only ever
                   fail in CI. Use whereDate(). See #46 (fixed), #51, #52 and
                   #54.

  swallowed actions  RecoverFromActionFailure reports a broken Livewire action
                   and turns it into a polite error toast, but the report may
                   not appear in the test log channel. A broken action and a
                   working one can therefore look identical from outside.
                   When an action appears to do nothing, assert its notify
                   dispatch before assuming it worked. Two shipped actions
                   were found this way in #55. See #56.

  pest --parallel  Flaky here; it produces order-dependent failures that are not
                   real. Reproduce serially before treating anything as a
                   regression.

== project: commands worth knowing ==
  docs/ai-team/scripts/orient.sh                                    read the board
  docs/ai-team/scripts/claim.sh <issue>                             claim a task
  docs/ai-team/scripts/gate.sh <pr> <full-sha>                      gate a merge
  python3 -m unittest discover -s docs/ai-team/scripts -p 'test_*.py'  mechanism suite

This domain composes into the platform at app/Domains/People; it does not run
standalone. To test a change, mount it into a Belimbing checkout at that path
and run the suite there.
TXT
