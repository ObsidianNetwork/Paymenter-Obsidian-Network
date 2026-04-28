#!/usr/bin/env bash
# /ralph-loop pre-merge verification gate v3 (dp-process-03)
# Usage: ralph-loop-verify.sh <PR_NUMBER> [OPTIONS]
#
# Options:
#   --repo <owner/name>           GitHub repo (default: CWD origin remote)
#   --expected-base <regex>       Base branch must match this regex
#                                 (default: rejects master/main/develop)
#   --allow-actionable            Bypass CodeRabbit clean-verdict check
#                                 REQUIRES --reason "..." for audit trail
#   --allow-direct-default        Allow PR targeting repo default branch
#                                 REQUIRES --reason "..." for audit trail
#   --quiet-period-seconds <N>    Quiet period threshold in seconds (default: 600)
#                                 or set QUIET_PERIOD_SECONDS=<N>
#   --wait                        Wait out Rule 8 instead of hard-failing
#   --skip-quiet-period           Bypass Rule 8 quiet-period check
#                                 REQUIRES --reason "..." for audit trail
#   --dry-run                     Print all rule outcomes; never exit 1
#   --reason "..."                Required audit message for bypass flags
#
# Exit codes:
#   0  all pre-conditions satisfied, safe to merge
#   1  one or more pre-conditions failed, DO NOT merge
#   2  invocation error (missing args, missing gh CLI, etc.)

set -euo pipefail

usage() {
  cat >&2 <<'USAGE'
Usage: ralph-loop-verify.sh <PR_NUMBER> [OPTIONS]

Options:
  --repo <owner/name>           GitHub repo (default: CWD origin remote)
  --expected-base <regex>       Base branch must match this regex
                                (default: rejects master/main/develop)
  --allow-actionable            Bypass CodeRabbit clean-verdict check
                                REQUIRES --reason "..." for audit trail
  --allow-direct-default        Allow PR targeting repo default branch
                                REQUIRES --reason "..." for audit trail
  --quiet-period-seconds <N>    Quiet period threshold in seconds (default: 600)
  --wait                        Wait out Rule 8 instead of hard-failing
  --skip-quiet-period           Bypass Rule 8 quiet-period check
                                REQUIRES --reason "..." for audit trail
  --dry-run                     Print all rule outcomes; never exit 1
  --reason "..."                Required audit message when a bypass flag is used

Exit codes: 0=PASS  1=FAIL  2=invocation error
USAGE
  exit 2
}

[ $# -ge 1 ] || usage
command -v gh >/dev/null 2>&1 || { echo "FAIL: gh CLI not found in PATH" >&2; exit 2; }
command -v python3 >/dev/null 2>&1 || { echo "FAIL: python3 not found in PATH" >&2; exit 2; }

pr="$1"
shift || true

repo_flag=""
expected_base_regex=""
allow_actionable=0
allow_direct_default=0
quiet_period_seconds="${QUIET_PERIOD_SECONDS:-600}"
wait_for_quiet_period=0
skip_quiet_period=0
dry_run=0
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
    --allow-direct-default)
      allow_direct_default=1; shift ;;
    --quiet-period-seconds)
      [ $# -ge 2 ] || { echo "FAIL: --quiet-period-seconds requires a value" >&2; exit 2; }
      quiet_period_seconds="$2"; shift 2 ;;
    --wait)
      wait_for_quiet_period=1; shift ;;
    --skip-quiet-period)
      skip_quiet_period=1; shift ;;
    --dry-run)
      dry_run=1; shift ;;
    --reason)
      [ $# -ge 2 ] || { echo "FAIL: --reason requires a value" >&2; exit 2; }
      reason="$2"; shift 2 ;;
    *) echo "Unknown flag: $1" >&2; usage ;;
  esac
done

if ! [[ "$quiet_period_seconds" =~ ^[0-9]+$ ]]; then
  echo "FAIL: --quiet-period-seconds must be an integer" >&2
  exit 2
fi

if { [ "$allow_actionable" -eq 1 ] || [ "$allow_direct_default" -eq 1 ] || [ "$skip_quiet_period" -eq 1 ]; } && [ -z "$reason" ]; then
  echo "FAIL: bypass flags require --reason \"...\" for audit trail" >&2
  exit 2
fi

if [ -n "$repo_flag" ]; then
  repo_display="$repo_flag"
else
  origin_url=$(git remote get-url origin 2>/dev/null || true)
  if [ -z "$origin_url" ]; then
    echo "FAIL: no --repo flag and no git origin remote found in CWD" >&2
    exit 2
  fi
  repo_display=$(printf '%s' "$origin_url" | sed -E 's|.*github\.com[:/]||; s|\.git$||')
fi

repo_arg="--repo $repo_display"
owner="${repo_display%%/*}"
repo_name="${repo_display##*/}"
failures=0

info() { echo "INFO: $*"; }
rule_pass() { echo "PASS: Rule $1 — $2"; }
rule_fail() {
  failures=$((failures + 1))
  echo "FAIL: Rule $1 — $2" >&2
}

write_waiver() {
  local kind="$1"
  local detail="$2"
  local waiver_dir waiver_file ts actor

  waiver_dir="$(git rev-parse --show-toplevel 2>/dev/null || echo ".")/.sisyphus/notepads"
  mkdir -p "$waiver_dir"
  waiver_file="$waiver_dir/ralph-loop-waivers.jsonl"
  ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  actor=$(gh api /user --jq .login 2>/dev/null || echo unknown)

  python3 - "$waiver_file" "$pr" "$repo_display" "$ts" "$reason" "$actor" "$kind" "$detail" <<'PY'
import json
import sys

waiver_file, pr, repo, ts, reason, actor, kind, detail = sys.argv[1:9]
with open(waiver_file, 'a', encoding='utf-8') as fh:
    fh.write(json.dumps({
        'pr': int(pr),
        'repo': repo,
        'ts': ts,
        'reason': reason,
        'actor': actor,
        'kind': kind,
        'detail': detail,
    }) + '\n')
PY

  info "waiver logged to .sisyphus/notepads/ralph-loop-waivers.jsonl ($kind)"
}

default_branch=$(gh repo view "$repo_display" --json defaultBranchRef --jq '.defaultBranchRef.name')
base_branch=$(gh pr view "$pr" $repo_arg --json baseRefName --jq '.baseRefName')

# Rule 0: PR author
pr_author=$(gh pr view "$pr" $repo_arg --json author --jq '.author.login')
if [ "$pr_author" = "Jordanmuss99" ]; then
  rule_pass 0 "PR author = $pr_author"
else
  rule_fail 0 "PR #$pr author is '$pr_author' (expected 'Jordanmuss99'). Close and reopen with 'gh auth switch -u Jordanmuss99'. PR author is immutable on GitHub."
fi

# Rule 1: expected base / forbidden defaults
if [ -n "$expected_base_regex" ]; then
  if printf '%s' "$base_branch" | grep -qE "$expected_base_regex"; then
    rule_pass 1 "Base branch '$base_branch' matches --expected-base '$expected_base_regex'"
  else
    rule_fail 1 "Base branch '$base_branch' does not match --expected-base pattern '$expected_base_regex'"
  fi
else
  if printf '%s' "$base_branch" | grep -qE '^(master|main|develop)$'; then
    rule_fail 1 "Base branch '$base_branch' is a forbidden default for dp-NN PRs. Use --expected-base '^master\$' only for intentional config/infra PRs."
  else
    rule_pass 1 "Base branch '$base_branch'"
  fi
fi

# Rule 2: commit author emails
noreply_email="164892154+Jordanmuss99@users.noreply.github.com"
bad_emails=$(gh api "repos/$owner/$repo_name/pulls/$pr/commits" --paginate \
  --jq '.[].commit.author.email' \
  | sort -u \
  | grep -v '^164892154+Jordanmuss99@users\.noreply\.github\.com$' \
  || true)
if [ -n "$bad_emails" ]; then
  rule_fail 2 "Commits contain unexpected author email(s): $bad_emails (expected: $noreply_email). Run: git config user.email \"$noreply_email\""
else
  rule_pass 2 "All commit author emails = $noreply_email"
fi

# Rule 3: CodeRabbit status check
cr_line=$(gh pr checks "$pr" $repo_arg 2>/dev/null | grep -E '^CodeRabbit\b' || true)
if [ -z "$cr_line" ]; then
  if [ "$allow_actionable" -eq 1 ]; then
    write_waiver "allow-actionable" "CodeRabbit status check missing"
    rule_pass 3 "CodeRabbit status check missing but bypassed via --allow-actionable"
  else
    rule_fail 3 "CodeRabbit status check 'CodeRabbit' not found on PR #$pr. CR may not have reviewed yet. Wait for 'CodeRabbit  pass  ...' in 'gh pr checks $pr $repo_arg', or check that .coderabbit.yaml is on the default branch."
  fi
else
  cr_status=$(printf '%s' "$cr_line" | awk '{print $2}')
  case "$cr_status" in
    pass|success|SUCCESS|PASS)
      rule_pass 3 "CodeRabbit status=$cr_status" ;;
    pending|PENDING)
      cr_started=$(gh pr checks "$pr" $repo_arg --json name,startedAt --jq '.[] | select(.name=="CodeRabbit") | .startedAt' 2>/dev/null || echo "")
      if [ -z "$cr_started" ] || [ "$cr_started" = "null" ] || [ "$cr_started" = "0001-01-01T00:00:00Z" ]; then
        cr_started=$(gh pr view "$pr" $repo_arg --json createdAt --jq '.createdAt' 2>/dev/null || echo "")
      fi
      age_s=0
      if [ -n "$cr_started" ]; then
        started_epoch=$(date -u -d "$cr_started" +%s 2>/dev/null || echo 0)
        now_epoch=$(date -u +%s)
        age_s=$((now_epoch - started_epoch))
      fi
      if [ "$age_s" -ge 900 ] && [ "$allow_actionable" -eq 1 ]; then
        if ! printf '%s' "$reason" | grep -qE '^CR outage [0-9]{4}-[0-9]{2}-[0-9]{2} per https://status\.coderabbit\.ai/.+'; then
          rule_fail 3 "Outage bypass requires --reason 'CR outage YYYY-MM-DD per https://status.coderabbit.ai/<incident-id>'"
        else
          write_waiver "allow-actionable" "CodeRabbit status pending for ${age_s}s"
          rule_pass 3 "CodeRabbit status=pending for ${age_s}s but bypassed via --allow-actionable"
        fi
      elif [ "$age_s" -ge 900 ]; then
        rule_fail 3 "CR status pending for ${age_s}s. CR may be experiencing an outage. Verify at https://status.coderabbit.ai/ then re-run with --allow-actionable --reason 'CR outage YYYY-MM-DD per https://status.coderabbit.ai/<incident-id>' if confirmed."
      else
        rule_fail 3 "CodeRabbit status=pending (started ${cr_started}). Wait for CR to complete its review."
      fi ;;
    *)
      if [ "$allow_actionable" -eq 1 ]; then
        write_waiver "allow-actionable" "CodeRabbit status=$cr_status"
        rule_pass 3 "CodeRabbit status=$cr_status but bypassed via --allow-actionable"
      else
        rule_fail 3 "CodeRabbit status=$cr_status (expected pass/success). Wait for CR to complete its review and address any findings."
      fi ;;
  esac
fi

# Rule 4: mergeStateStatus
merge_state=$(gh pr view "$pr" $repo_arg --json mergeStateStatus --jq '.mergeStateStatus')
if [ "$merge_state" = "CLEAN" ]; then
  rule_pass 4 "mergeStateStatus=CLEAN"
else
  rule_fail 4 "mergeStateStatus=$merge_state (expected CLEAN) — CI checks pending/failing or branch out of date"
fi

# Rule 5: zero unresolved threads
unresolved=$(gh api graphql \
  -f query='query($o:String!,$r:String!,$p:Int!){repository(owner:$o,name:$r){pullRequest(number:$p){reviewThreads(first:100){nodes{isResolved}}}}}' \
  -F o="$owner" -F r="$repo_name" -F p="$pr" \
  --jq '[.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved==false)] | length')
if [ "$unresolved" -gt 0 ]; then
  rule_fail 5 "$unresolved unresolved review thread(s) on PR #$pr — reply with reasoning or a fix commit, then resolve each thread"
else
  rule_pass 5 "Zero unresolved review threads"
fi

# Rule 6: default branch targeting
if [ "$base_branch" = "$default_branch" ]; then
  if [ -n "$expected_base_regex" ] && printf '%s' "$default_branch" | grep -qE "$expected_base_regex"; then
    rule_pass 6 "PR targets repo default '$default_branch', which is also the expected integration branch"
  elif [ "$allow_direct_default" -eq 1 ]; then
    write_waiver "allow-direct-default" "PR targets repo default branch '$default_branch'"
    rule_pass 6 "PR targets default branch '$default_branch' but bypassed via --allow-direct-default"
  else
    rule_fail 6 "PR #$pr targets default branch '$default_branch'. Use a feature branch off it and PR back to it, or pass --allow-direct-default --reason '...' only for true bootstrap PRs."
  fi
else
  rule_pass 6 "PR base '$base_branch' is not the repo default '$default_branch'"
fi

# Rule 7: every non-nit CR thread must have a Jordan reply
missing_replies=$(gh api graphql \
  -f query='query($o:String!,$r:String!,$p:Int!){repository(owner:$o,name:$r){pullRequest(number:$p){reviewThreads(first:100){nodes{id isResolved comments(first:100){nodes{author{login} body}}}}}}}' \
  -F o="$owner" -F r="$repo_name" -F p="$pr" \
  --jq '
    .data.repository.pullRequest.reviewThreads.nodes[]
    | select(any(.comments.nodes[]?; (.author.login == "coderabbitai" or .author.login == "coderabbitai[bot]")))
    | select(all(.comments.nodes[]?; .author.login != "Jordanmuss99"))
    | select((([.comments.nodes[] | select(.author.login=="coderabbitai" or .author.login=="coderabbitai[bot]")][0].body) // "") | test("(?i)nitpick") | not)
    | .id')
if [ -n "$missing_replies" ]; then
  rule_fail 7 "CR thread(s) missing a Jordanmuss99 reply before resolution: $(printf '%s' "$missing_replies" | paste -sd ', ' -)"
else
  rule_pass 7 "Every non-nit CR-authored thread has a Jordanmuss99 reply"
fi

get_quiet_period_payload() {
  gh api graphql \
    -f query='query($o:String!,$r:String!,$p:Int!){repository(owner:$o,name:$r){pullRequest(number:$p){commits(last:1){nodes{commit{committedDate}}} comments(first:100){nodes{author{login} body createdAt}} reviews(first:100){nodes{author{login} submittedAt}} reviewThreads(first:100){nodes{comments(first:100){nodes{author{login} body createdAt}}}}}}}' \
    -F o="$owner" -F r="$repo_name" -F p="$pr"
}

parse_quiet_period_payload() {
  python3 -c '
import json
import sys

payload = json.load(sys.stdin)["data"]["repository"]["pullRequest"]
last_commit = payload["commits"]["nodes"][0]["commit"].get("committedDate") if payload["commits"]["nodes"] else ""
cr_logins = {"coderabbitai", "coderabbitai[bot]"}

activity = []
excluded = []

def handle_comment(comment):
    body = comment.get("body") or ""
    created_at = comment.get("createdAt") or ""
    login = ((comment.get("author") or {}).get("login")) or ""
    if "@coderabbitai pause" in body:
        excluded.append(("pause", created_at))
    if "Actions performed" in body:
        excluded.append(("actions-performed", created_at))
    if login in cr_logins and "Actions performed" not in body and last_commit and created_at >= last_commit:
        activity.append(created_at)

for comment in payload.get("comments", {}).get("nodes", []):
    handle_comment(comment)

for thread in payload.get("reviewThreads", {}).get("nodes", []):
    for comment in thread.get("comments", {}).get("nodes", []):
        handle_comment(comment)

for review in payload.get("reviews", {}).get("nodes", []):
    login = ((review.get("author") or {}).get("login")) or ""
    submitted_at = review.get("submittedAt") or ""
    if login in cr_logins and last_commit and submitted_at >= last_commit:
        activity.append(submitted_at)

last_activity = max(activity) if activity else ""
latest_excluded = ""
if excluded:
    kind, created_at = max(excluded, key=lambda item: item[1])
    latest_excluded = f"{kind}@{created_at}"

print(last_commit)
print(last_activity)
print(latest_excluded)
'
}

refresh_quiet_period_state() {
  quiet_payload=$(get_quiet_period_payload)
  mapfile -t quiet_state < <(printf '%s' "$quiet_payload" | parse_quiet_period_payload)
  last_commit="${quiet_state[0]:-}"
  last_cr_activity="${quiet_state[1]:-}"
  latest_excluded_comment="${quiet_state[2]:-}"
}

refresh_quiet_period_state

# Rule 8: quiet period
if [ "$skip_quiet_period" -eq 1 ]; then
  write_waiver "skip-quiet-period" "Skipped Rule 8 quiet-period check"
  rule_pass 8 "Quiet period bypassed via --skip-quiet-period"
elif [ -z "$last_commit" ]; then
  rule_fail 8 "Could not determine latest PR commit timestamp for quiet-period check"
else
  quiet_rule_satisfied=0
  while [ "$quiet_rule_satisfied" -eq 0 ]; do
    if [ -z "$last_cr_activity" ]; then
      rule_pass 8 "No post-last-commit CR activity detected; quiet period satisfied"
      quiet_rule_satisfied=1
      break
    fi

    age=$(( $(date +%s) - $(date -d "$last_cr_activity" +%s) ))
    if [ "$age" -ge "$quiet_period_seconds" ]; then
      rule_pass 8 "Last CR activity at $last_cr_activity is ${age}s old (threshold ${quiet_period_seconds}s)"
      quiet_rule_satisfied=1
      break
    fi

    remaining=$((quiet_period_seconds - age))
    if [ "$wait_for_quiet_period" -eq 1 ] && [ "$dry_run" -eq 0 ]; then
      info "Rule 8 waiting: last CR activity at $last_cr_activity is ${age}s old; sleeping ${remaining}s"
      sleep "$remaining"
      refresh_quiet_period_state
    else
      rule_fail 8 "CR activity at $last_cr_activity is ${age}s old; quiet period requires ${quiet_period_seconds}s. Wait ${remaining}s and re-run."
      quiet_rule_satisfied=1
    fi
  done
fi

if [ "$dry_run" -eq 1 ]; then
  if [ -n "$latest_excluded_comment" ]; then
    info "Rule 8 dry-run: latest excluded comment = $latest_excluded_comment"
  else
    info "Rule 8 dry-run: no excluded pause/actions-performed comments observed"
  fi
fi

if [ "$failures" -gt 0 ]; then
  if [ "$dry_run" -eq 1 ]; then
    echo "DRY-RUN: $failures rule(s) would fail for PR #$pr on $repo_display"
    exit 0
  fi
  echo "FAIL: PR #$pr on $repo_display failed $failures /ralph-loop rule(s)" >&2
  exit 1
fi

if [ "$dry_run" -eq 1 ]; then
  echo "DRY-RUN: PR #$pr on $repo_display passes all /ralph-loop v3 rules"
else
  echo "PASS: PR #$pr on $repo_display meets all /ralph-loop v3 merge pre-conditions"
fi
