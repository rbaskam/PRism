#!/usr/bin/env bash
#
# One-time repository setup for PRism. Run it as the repository owner (an admin):
#
#   gh auth switch --user <owner>   # or: gh auth login
#   bash .github/scripts/setup-repository.sh
#
# It is safe to run again: existing rules, keys and labels are replaced or kept.
#
# 1. Protects main with a ruleset: changes need a pull request, the CI checks must pass,
#    and branches can't be deleted or force-pushed. Repository admins can bypass.
# 2. Creates a deploy key the release workflow uses to push its "chore(release)" commit and
#    tag to the protected branch, and stores its private half as the RELEASE_DEPLOY_KEY secret.
# 3. Creates the skip-changelog label.
set -euo pipefail

repo="${1:-$(gh repo view --json nameWithOwner --jq .nameWithOwner)}"
echo "Setting up ${repo}"

if [ "$(gh api "repos/${repo}" --jq .permissions.admin)" != "true" ]; then
    echo "You need admin rights on ${repo}. Run 'gh auth switch' to the repository owner and try again." >&2
    exit 1
fi

# 1. Ruleset protecting the default branch
ruleset='{
  "name": "Protect main",
  "target": "branch",
  "enforcement": "active",
  "conditions": { "ref_name": { "include": ["~DEFAULT_BRANCH"], "exclude": [] } },
  "bypass_actors": [
    { "actor_id": 5, "actor_type": "RepositoryRole", "bypass_mode": "always" },
    { "actor_id": null, "actor_type": "DeployKey", "bypass_mode": "always" }
  ],
  "rules": [
    { "type": "deletion" },
    { "type": "non_fast_forward" },
    {
      "type": "pull_request",
      "parameters": {
        "required_approving_review_count": 0,
        "dismiss_stale_reviews_on_push": false,
        "require_code_owner_review": false,
        "require_last_push_approval": false,
        "required_review_thread_resolution": false
      }
    },
    {
      "type": "required_status_checks",
      "parameters": {
        "strict_required_status_checks_policy": true,
        "required_status_checks": [
          { "context": "PHP (Pint, Larastan, Pest)" },
          { "context": "Frontend (ESLint, Prettier, vue-tsc, build)" },
          { "context": "Browser tests (1/2)" },
          { "context": "Browser tests (2/2)" },
          { "context": "Changelog" }
        ]
      }
    }
  ]
}'

existing="$(gh api "repos/${repo}/rulesets" --jq '.[] | select(.name == "Protect main") | .id' || true)"
if [ -n "$existing" ]; then
    echo "$ruleset" | gh api -X PUT "repos/${repo}/rulesets/${existing}" --input - >/dev/null
    echo "✓ Updated the 'Protect main' ruleset"
else
    echo "$ruleset" | gh api -X POST "repos/${repo}/rulesets" --input - >/dev/null
    echo "✓ Created the 'Protect main' ruleset"
fi

# 2. Deploy key for the release workflow
key_title="PRism release workflow"
for id in $(gh api "repos/${repo}/keys" --jq ".[] | select(.title == \"${key_title}\") | .id"); do
    gh api -X DELETE "repos/${repo}/keys/${id}" >/dev/null
done

key_dir="$(mktemp -d)"
trap 'rm -rf "$key_dir"' EXIT
ssh-keygen -q -t ed25519 -N '' -C "prism-release@${repo}" -f "${key_dir}/release_key"
gh repo deploy-key add "${key_dir}/release_key.pub" --repo "$repo" --title "$key_title" --allow-write >/dev/null
gh secret set RELEASE_DEPLOY_KEY --repo "$repo" < "${key_dir}/release_key"
echo "✓ Added the release deploy key and the RELEASE_DEPLOY_KEY secret"

# 3. Label for pull requests without user-facing changes
gh label create skip-changelog --repo "$repo" --description "No user-facing change" --color ededed --force >/dev/null
echo "✓ skip-changelog label is ready"

echo "Done. Pull requests into main now need passing CI, and merges release automatically."
