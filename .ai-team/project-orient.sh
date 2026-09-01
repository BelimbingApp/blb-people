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
# because CI pins the platform at a moving ref (see the hazard below), this
# repository can go red with no commit of its own. Checking costs one API call
# and has already saved one agent from being blamed for a break they inherited.
ci_state=$(gh run list --branch "$BASE" --workflow ci.yml --limit 1 \
  --json conclusion,headSha,createdAt \
  --jq '.[0] | "\(.conclusion)\t\(.headSha[0:8])\t\(.createdAt)"' 2>/dev/null)
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
    *)
      printf '  note    latest ci run on %s is %s (%s)\n' "$BASE" "${conclusion:-in progress}" "$head_sha" ;;
  esac
fi

echo
echo "== project: what this domain ships =="
modules=$(git ls-tree -r --name-only "origin/$BASE" 2>/dev/null | grep -E '^[A-Z][A-Za-z]*/composer\.json$' | cut -d/ -f1 | sort)
if [ -n "$modules" ]; then
  # paste -sd', ' would alternate the two delimiter characters, not use both
  # between every pair; join on a comma and space it out afterwards.
  printf '  modules   %s\n' "$(printf '%s\n' "$modules" | paste -sd, - | sed 's/,/, /g')"
else
  printf '  modules   none resolved from origin/%s\n' "$BASE"
fi
printf '  tests     %s test file(s)\n' \
  "$(git ls-tree -r --name-only "origin/$BASE" 2>/dev/null | grep -c 'Tests/.*Test\.php$')"

cat <<'TXT'

== project: hazards worth knowing before you claim ==
  linear history   main forbids merge commits. land.sh hardcodes merge_method=merge
                   and fails with a 405 whose text wrongly blames a missing
                   approval. Squash at the reviewed SHA, then re-run land.sh to
                   terminalize. Tracked upstream as BelimbingApp/ai-team#66.

  closing links    Put closing references in PR body text only. Never set the
                   GitHub Development-panel link: a lane gated as issue-less can
                   still close an issue through it. Tracked as ai-team#67.

  moving platform  ci.yml pins BelimbingApp/belimbing/.../domain-ci.yml@main and
                   platform-ref defaults to main. This repository can go from
                   green to red with no commit of its own, and the next unrelated
                   PR gets the blame. See #47.

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
