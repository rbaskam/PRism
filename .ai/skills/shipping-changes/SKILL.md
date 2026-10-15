---
name: shipping-changes
description: "Use when finishing any change to PRism: create a branch, add a CHANGELOG.md entry under Unreleased, run every CI check locally (Pint, Larastan, Pest, ESLint, Prettier, vue-tsc, build, browser tests), check UI changes in light, dark and accent-colour themes, commit with a Conventional Commit message and open a pull request with gh. Also explains how releases are made automatically after merge."
metadata:
  author: PRism
---

# Shipping a change to PRism

Work through these steps in order whenever a change is ready. Don't skip the changelog or the checks: CI blocks the pull request without them.

## 1. Branch

```bash
git switch main && git pull --ff-only
git switch -c feat/short-description   # or fix/…, chore/…, docs/…
```

Never commit to `main` directly.

## 2. Changelog

Add the change under `## [Unreleased]` in `CHANGELOG.md`, creating the section heading if it isn't there yet:

```markdown
## [Unreleased]

### Added

- Ask Claude about highlighted lines in a pull request diff.
```

- Sections: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
- Start breaking changes with `BREAKING:`.
- Never edit released sections or bump versions; the release workflow does it.
- No user-facing change (CI, refactor, tests only)? Skip the entry and add the `skip-changelog` label to the pull request.

## 3. Run the checks CI runs

```bash
vendor/bin/pint                                      # then: vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G         # Larastan, level 6
php artisan test --compact --testsuite=Unit,Feature
npx eslint .
npx prettier --check resources/                      # fix with: npx prettier --write resources/
npx vue-tsc --noEmit
npm run build
vendor/bin/pest --testsuite=Browser --parallel       # needs the build above; first time: npx playwright install chromium
```

Fix everything before opening the pull request.

If the change touches the UI, also check it against every theme setting (see "Themes" in the project rules): open Settings → Appearance and look at the change in light mode, dark mode and at least one accent colour other than Neutral. Text must stay readable, and buttons, switches, focus rings and selected items must take the accent. Say in the pull request if you couldn't check it visually.

## 4. Commit and open the pull request

Before committing, read `git status` and `git diff --staged`: nothing secret, personal or sensitive may go in (see "Never add secrets, tokens or personal data" in the project rules). That means no secrets, `.env`, databases, logs, test screenshots or `.claude/worktrees/`, and no real names, logins, organisations or repositories in code, tests, the commit message or the pull request description.

```bash
git add -A
git commit -m "feat: ask Claude about highlighted lines"
git push -u origin HEAD
gh pr create --fill --base main
```

Use a Conventional Commit title (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`). Describe what changed and how it was tested in the body.

## 5. What happens after merge

1. CI must pass before GitHub allows the merge, and runs again on `main`.
2. The **Release** workflow reads `## [Unreleased]`, works out the next version, moves the entries into a new version section, commits `chore(release): vX.Y.Z`, tags it and creates a draft release.
3. macOS (Apple Silicon + Intel) and Windows apps are built with `php artisan native:build` and attached to the release, which is then published.
4. Running copies of PRism see the update in Settings → About and show "What's new" from the changelog after updating.
