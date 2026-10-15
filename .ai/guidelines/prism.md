# PRism project rules

PRism is an open-source desktop app (NativePHP Desktop on macOS and Windows) for reviewing GitHub pull requests across many repositories with Claude Code. It's a Laravel 12 + Inertia v2 + Vue 3 app. Use the `nativephp-desktop` skill for anything touching the desktop runtime, and the `shipping-changes` skill when finishing a change.

## Never add secrets, tokens or personal data

This applies to every file, test, fixture and doc, every commit message, pull request, issue and changelog entry, and anything you paste into logs or screenshots.

- Never add a real secret, even temporarily or "just for testing". That means:
  - GitHub tokens (`ghp_…`, `gho_…`, `ghu_…`, `ghs_…`, `ghr_…`, `github_pat_…`)
  - Claude credentials: Anthropic API keys (`sk-ant-…`) and Claude Code OAuth tokens (from `claude setup-token` or `claude login`)
  - the app lock PIN and `APP_KEY`
  - passwords and SSH or deploy keys
  - code-signing certificates and their passwords (`CSC_LINK`, `CSC_KEY_PASSWORD`) and Apple ID credentials
  - any other key or credential

  Secrets belong only in the user's local `.env`, the app's encrypted settings or GitHub Actions secrets.
- Never commit local state: `.env` files, databases (`*.sqlite`), logs, anything under `storage/`, browser test screenshots and traces, local agent worktrees (`.claude/worktrees/`) or `settings.local.json`. Check `git status` and `git diff --staged` before every commit.
- Never copy real data into the repository or onto GitHub. That means no real people's names, GitHub logins, email addresses, organisation or company names, private repository names, pull request titles, or anything else from the user's GitHub account, desktop database or logs. The project's own identity (`rbaskam/PRism`) is the only exception.
- Tests and fixtures use made-up data:
  - `octocat` for the connected GitHub user, and other invented logins for other people
  - `acme/…` for organisations, teams and repositories
  - `example.com` for URLs and emails
  - obviously fake secrets such as `ghp_test` or `sk-ant-test`
  - `fake()` for anything else
- When you debug with the user's real data, keep the details in the conversation. In commits, pull requests and the changelog describe it generically, e.g. "a pull request requested through a team" rather than the team or repository name.
- If a secret or personal data is ever committed or pushed, tell the user straight away so they can revoke or replace it. Deleting the commit afterwards does not make it safe.

## Every change goes through a pull request

- Never commit directly to `main`. Create a branch named after the change (`feat/…`, `fix/…`, `chore/…`, `docs/…`).
- Use Conventional Commit messages and PR titles, e.g. `feat: highlight lines and ask Claude`, `fix: keep flash messages during polls`.
- Open the pull request with `gh pr create` once the checks below pass locally. `main` only accepts pull requests whose CI checks pass, and merging releases automatically.

## Always update CHANGELOG.md

- Add every user-facing change under `## [Unreleased]` in `CHANGELOG.md`, in the right section: `### Added`, `### Changed`, `### Deprecated`, `### Removed`, `### Fixed` or `### Security`. Write entries for users, not developers: say what changed for them.
- Mark breaking changes with `BREAKING:` at the start of the entry.
- The section decides the next version: `### Removed` or `BREAKING` bumps major, `### Added`/`### Changed`/`### Deprecated` bump minor, anything else bumps patch.
- Never edit released version sections, never bump versions by hand, and never create tags: the release workflow does all of that when the pull request is merged.
- A pull request without user-facing changes (CI, refactors, tests) is labelled `skip-changelog` instead.

## Checks that must pass (CI runs them on every pull request)

- `vendor/bin/pint --test` (run `vendor/bin/pint` to fix)
- `vendor/bin/phpstan analyse --memory-limit=1G` (Larastan, level 6)
- `php artisan test --compact --testsuite=Unit,Feature`
- `npx eslint .`, `npx prettier --check resources/`, `npx vue-tsc --noEmit`, `npm run build`
- `vendor/bin/pest --testsuite=Browser --parallel` after `npm run build` (install the browser once with `npx playwright install chromium`). CI runs the browser tests in two time-balanced shards.

## Browser tests

- Every page has browser tests in `tests/Browser` (Pest 4's browser plugin driving Playwright). When you add or change a page, add or update its browser test, and add a new page to the `pages` dataset in `tests/Browser/ThemeTest.php` so it is checked in light and dark mode with an accent colour. The CI shard timings in `tests/.pest/shards.json` are kept per test file. Refresh them with `vendor/bin/pest --testsuite=Browser --update-shards` only when you add a new browser test file, and leave them alone otherwise: every refresh conflicts with other open pull requests.
- Never call the real GitHub API. Describe what GitHub should return with `Tests\Browser\Support\FakeGithub`, then call `syncFromGithub()` so the data arrives the way it does in the app. `BrowserTestCase` blocks other HTTP requests, fakes processes and hides the `claude`, `gh` and `git` commands, so pages behave the same on every machine.
- Use made-up data only (see "Never add secrets, tokens or personal data").
- Click with a CSS selector or a role selector such as `internal:role=button[name="Save (2)"s]` whenever a button's text includes a count, an icon label or other text: a plain-text click only matches an element whose whole text is exactly that text.
- The app serves one request at a time, so after an action that posts to the server, `navigate()` to a page before asserting on the database.
- Playwright is pinned to the version `pestphp/pest-plugin-browser` supports (`PLAYWRIGHT_VERSION` in its `PlaywrightNpmServer`). With a newer Playwright, waiting for a missing element hangs forever instead of failing after 10 seconds, so only upgrade Playwright together with the plugin.

## Product rules

- Never write to GitHub on the user's behalf without an explicit action in the UI, and only when the matching setting in Settings → PR review allows it (posting findings, replying to comments, pushing code changes).
- Claude Code runs headless with read-only tools for reviews and questions. Code changes happen only in worktrees under `storage/app/worktrees`, never in the user's own checkout, and are pushed only after the user approves the diff.
- Never run `claude`, `gh` or `git` inside an HTTP request: the desktop app's PHP server handles one request at a time. Dispatch a queued job (long AI work goes on the `reviews` queue).
- Secrets (GitHub token, Claude credentials, PIN) are stored encrypted or hashed; never log or return them.
- The app version comes from `NATIVEPHP_APP_VERSION`, which release builds set from the git tag.

## Themes: every UI change must work in all of them

- Users choose a mode (Light, Dark or System) and an accent colour (Neutral, Blue, Green, Violet, Rose or Orange) in Settings → Appearance. `resources/js/composables/useAppearance.ts` saves both in `localStorage` (`appearance`, `accent`) and applies them to `<html>`: the `dark` class for dark mode, and `data-accent="…"` for every accent except Neutral.
- The colour tokens live in `resources/css/app.css`: `:root` for light, `.dark` for dark, then a `[data-accent='…']` and `.dark[data-accent='…']` pair per accent that overrides `--primary`, `--primary-foreground`, `--ring` and the sidebar's primary, accent and ring tokens.
- Build app chrome and interactive elements from the semantic Tailwind colours (`bg-background`, `text-foreground`, `bg-muted`, `text-muted-foreground`, `border-border`, `bg-primary`, `text-primary-foreground`, `ring-ring`, `bg-sidebar-accent`, …) so they follow the mode and the accent. Never hard-code `black`, `white`, `gray-*`, `neutral-*`, `zinc-*` or a brand colour on buttons, links, switches, selected items or focus states, or the accent stops applying to them.
- Use fixed colours only where the colour carries meaning that must not change with the accent: status and severity, CI results, diff lines, GitHub label colours. Give each one a `dark:` variant in the existing style, e.g. `border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200`. Prefer the `Badge` variants (`success`, `danger`, `warning`, `info`, `muted`) and the shared styles in `resources/js/lib/pull-requests.ts` and `resources/js/components/pull-request/utils.ts` over new combinations.
- A new colour token goes in both `:root` and `.dark`, and in every `[data-accent]` block if it should follow the accent. A new accent needs the `Accent` type in `useAppearance.ts`, a swatch in `resources/js/components/AccentPicker.vue`, and both its light and dark blocks in `app.css`.
- Before finishing a UI change, check it in light mode, in dark mode and with at least one accent other than Neutral. Text must stay readable, nothing may disappear into its background, and focus rings, primary buttons, switches and selected items must take the accent colour. Say in the pull request if you couldn't check it visually.
