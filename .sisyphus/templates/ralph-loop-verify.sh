#!/usr/bin/env bash
# /ralph-loop pre-merge verification gate v2 (dp-process-01)
# Usage: ralph-loop-verify.sh <PR_NUMBER> [OPTIONS]
#
# Options:
#   --repo <owner/name>       GitHub repo (default: CWD origin remote)
#   --expected-base <regex>   Base branch must match this regex
#                             (default: rejects master/main/develop for dp-NN PRs)
#   --allow-actionable        Bypass CodeRabbit clean-verdict check
#                             REQUIRES --reason "..." for audit trail
#   --reason "..."            Required message when --allow-actionable is passed
#
# Exit codes:
#   0  all pre-conditions satisfied, safe to merge
#   1  one or more pre-conditions failed, DO NOT merge
#   2  invocation error (missing args, missing gh CLI, etc.)
#
# Rules (v2 — CodeRabbit-native signals):
#   0. PR author = Jordanmuss99  (Pro-entitled; immutable on GitHub)
#   1. Base branch not in {master,main,develop}  (or matches --expected-base)
#   2. All commit author emails = 164892154+Jordanmuss99@users.noreply.github.com
#   3. CodeRabbit commit status check = pass/success
#   4. mergeStateStatus = CLEAN
#   5. Zero unresolved review threads
#
# This is the machine-enforceable half of .sisyphus/templates/ralph-loop-contract.md.
# Do not bypass. Do not patch to exit 0 without fixing the failing condition.

set -euo pipefail

usage() {
  cat >&2 <<'USAGE'
Usage: ralph-loop-verify.sh <PR_NUMBER> [OPTIONS]

Options:
  --repo <owner/name>       GitHub repo (default: CWD origin remote)
  --expected-base <regex>   Base branch must match this regex
                            (default: rejects master/main/develop)
  --allow-actionable        Bypass CodeRabbit clean-verdict check
                            REQUIRES --reason "..." for audit trail
  --reason "..."            Required audit message when --allow-actionable is used

Exit codes: 0=PASS  1=FAIL  2=invocation error
USAGE
  exit 2
}

[ $# -ge 1 ] || usage
command -v gh >/dev/null 2>&1 || { echo "FAIL: gh CLI not found in PATH" >&2; exit 2; }

pr="$1"
shift || true

repo_flag=""
expected_base_regex=""
allow_actionable=0
reason=""

while [ $# -gt 0 ]; do
  case "$1" in
    --repo)
      [ $# -ge 2 ] || { echo "FAIL: --repo requires a value" >&2; exit 2; }
      repo_flag="$2"; shift 2 ;;
    --expected-base)
      [ $# -ge 2 ] || { echo "FAIL: --expected-base requires a value" >&2; exit 2; }
      expected_base_regex="$2"; shift 2 ;;
    --allow-actionable)
      allow_actionable=1; shift ;;
    --reason)
      [ $# -ge 2 ] || { echo "FAIL: --reason requires a value" >&2; exit 2; }
      reason="$2"; shift 2 ;;
    *) echo "Unknown flag: $1" >&2; usage ;;
  esac
done

if [ "$allow_actionable" -eq 1 ] && [ -z "$reason" ]; then
  echo "FAIL: --allow-actionable requires --reason \"...\" for audit trail" >&2
  exit 2
fi

# Resolve --repo or fall back to CWD's origin remote
if [ -n "$repo_flag" ]; then
  repo_display="$repo_flag"
else
  origin_url=$(git remote get-url origin 2>/dev/null || true)
  if [ -z "$origin_url" ]; then
    echo "FAIL: no --repo flag and no git origin remote found in CWD" >&2
    exit 2
  fi
  repo_display=$(echo "$origin_url" | sed -E 's|.*github\.com[:/]||; s|\.git$||')
fi
# repo_arg is intentionally unquoted below so --repo and owner/name are separate args
repo_arg="--repo $repo_display"

fail() { echo "FAIL: $*" >&2; exit 1; }
info() { echo "INFO: $*"; }

write_waiver() {
  local waiver_dir
  waiver_dir="$(git rev-parse --show-toplevel 2>/dev/null || echo ".")/.sisyphus/notepads"
  mkdir -p "$waiver_dir"
  local ts; ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  local actor; actor=$(gh api /user --jq .login 2>/dev/null || echo unknown)
  jq -n \
    --argjson pr "$pr" \
    --arg repo "$repo_display" \
    --arg ts "$ts" \
    --arg reason "$reason" \
    --arg actor "$actor" \
    '{"pr":$pr,"repo":$repo,"ts":$ts,"reason":$reason,"actor":$actor}' \
    >> "$waiver_dir/ralph-loop-waivers.jsonl"
  info "waiver logged to .sisyphus/notepads/ralph-loop-waivers.jsonl"
}

# Parse owner/repo_name from repo_display (format: owner/name)
owner="${repo_display%%/*}"
repo_name="${repo_display##*/}"

# ----------------------------------------------------------------------------
# Rule 0: PR author must be Jordanmuss99 (Pro-entitled; entitlement is immutable)
# ----------------------------------------------------------------------------
pr_author=$(gh pr view "$pr" $repo_arg --json author --jq '.author.login')
if [ "$pr_author" != "Jordanmuss99" ]; then
  fail "PR #$pr author is '$pr_author' (expected 'Jordanmuss99'). Close and reopen with 'gh auth switch -u Jordanmuss99'. PR author is immutable on GitHub."
fi
info "PR author = $pr_author (Pro-entitled)"

# ----------------------------------------------------------------------------
# Rule 1: Base branch must not be a forbidden default (unless --expected-base given)
# dp-NN PRs target integration branches (dynamic-slider/*), NOT master/main/develop.
# Config PRs that legitimately target master pass --expected-base '^master$'.
# ----------------------------------------------------------------------------
base_branch=$(gh pr view "$pr" $repo_arg --json baseRefName --jq '.baseRefName')
if [ -n "$expected_base_regex" ]; then
  if ! echo "$base_branch" | grep -qE "$expected_base_regex"; then
    fail "Base branch '$base_branch' does not match --expected-base pattern '$expected_base_regex'"
  fi
  info "Base branch '$base_branch' matches --expected-base '$expected_base_regex'"
else
  if echo "$base_branch" | grep -qE '^(master|main|develop)$'; then
    fail "Base branch '$base_branch' is a forbidden default for dp-NN PRs. Use --expected-base '^master\$' if this is an intentional config/infra PR."
  fi
  info "Base branch '$base_branch'"
fi

# ----------------------------------------------------------------------------
# Rule 2: All commit author emails must be the noreply form
# Prevents GH007 push rejections and ensures consistent attribution.
# ----------------------------------------------------------------------------
noreply_email="164892154+Jordanmuss99@users.noreply.github.com"
bad_emails=$(gh api "repos/$owner/$repo_name/pulls/$pr/commits" --paginate \
  --jq '.[].commit.author.email' \
  | sort -u \
  | grep -v '^164892154+Jordanmuss99@users\.noreply\.github\.com$' \
  || true)
if [ -n "$bad_emails" ]; then
  fail "Commits contain unexpected author email(s): $bad_emails (expected: $noreply_email). Run: git config user.email \"$noreply_email\""
fi
info "All commit author emails = $noreply_email"

# ----------------------------------------------------------------------------
# Rule 3: CodeRabbit commit status check must be pass/success
# CR posts the 'CodeRabbit' status check (pending while reviewing, pass when done).
# This replaces the old timestamp-comparison + silence-is-clean logic with a
# direct, documented CR signal. Requires .coderabbit.yaml on the default branch.
# ----------------------------------------------------------------------------
cr_line=$(gh pr checks "$pr" $repo_arg 2>/dev/null | grep -E '^CodeRabbit\b' || true)
if [ -z "$cr_line" ]; then
  if [ "$allow_actionable" -eq 1 ]; then
    info "CodeRabbit check not found — bypassed via --allow-actionable"
    write_waiver
  else
    fail "CodeRabbit status check 'CodeRabbit' not found on PR #$pr. CR may not have reviewed yet. Wait for 'CodeRabbit  pass  ...' in 'gh pr checks $pr $repo_arg', or check that .coderabbit.yaml is on the default branch."
  fi
else
  cr_status=$(echo "$cr_line" | awk '{print $2}')
  case "$cr_status" in
    pass|success|SUCCESS|PASS)
      info "CodeRabbit status=$cr_status" ;;
    *)
      if [ "$allow_actionable" -eq 1 ]; then
        info "CodeRabbit status=$cr_status — bypassed via --allow-actionable"
        write_waiver
      else
        fail "CodeRabbit status=$cr_status (expected pass/success). Wait for CR to complete its review and address any findings."
      fi ;;
  esac
fi

# ----------------------------------------------------------------------------
# Rule 4: mergeStateStatus must be CLEAN
# Covers: mergeable + all required CI checks SUCCESS + no blocking reviews.
# ----------------------------------------------------------------------------
merge_state=$(gh pr view "$pr" $repo_arg --json mergeStateStatus --jq '.mergeStateStatus')
if [ "$merge_state" != "CLEAN" ]; then
  fail "mergeStateStatus=$merge_state (expected CLEAN) — CI checks pending/failing or branch out of date"
fi
info "mergeStateStatus=CLEAN"

# ----------------------------------------------------------------------------
# Rule 5: Zero unresolved review threads
# Every CR thread must be closed out: fix commit, reasoned rejection, or deferral.
# Silent dismissal (resolving without a reply) is a contract violation.
# ----------------------------------------------------------------------------
unresolved=$(gh api graphql \
  -f query='query($o:String!,$r:String!,$p:Int!){repository(owner:$o,name:$r){pullRequest(number:$p){reviewThreads(first:100){nodes{isResolved}}}}}' \
  -F o="$owner" -F r="$repo_name" -F p="$pr" \
  --jq '[.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved==false)] | length')

if [ "$unresolved" -gt 0 ]; then
  fail "$unresolved unresolved review thread(s) on PR #$pr — reply with reasoning or a fix commit, then resolve each thread"
fi
info "Zero unresolved review threads"

echo "PASS: PR #$pr on $repo_display meets all /ralph-loop v2 merge pre-conditions"
exit 0
