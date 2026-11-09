# Contributing to PRism

Thanks for helping. Bug reports, ideas and pull requests are all welcome.

## Issues

- **Bugs and ideas:** open an issue with the [bug report](https://github.com/rbaskam/PRism/issues/new?template=bug_report.yml) or [feature request](https://github.com/rbaskam/PRism/issues/new?template=feature_request.yml) template. New issues are labelled `needs-triage` until a maintainer has looked at them.
- **Security problems:** report them privately, as described in [SECURITY.md](SECURITY.md). Never in a public issue.
- **Before a big change:** open an issue first, so we can agree on the approach before you spend time on it.

Leave private data out of issues and pull requests: tokens, keys, private repository or organisation names, pull request titles and people's names.

## Setting up

Fork the repository, then follow [Run from source](README.md#run-from-source) in the README.

## Working with an AI coding agent

PRism is built with AI coding agents, and you're welcome to use yours. The project's rules are in [AGENTS.md](AGENTS.md), with skills in `.ai/skills`. Claude Code, Cursor and Junie pick them up automatically from `AGENTS.md`, `.claude`, `.cursor` and `.junie`. After changing `.ai/guidelines` or `.ai/skills`, run `php artisan boost:update` to sync them.

Your agent runs on your machine with your own account or API key. The project's CI never runs an AI model and never uses anyone's credentials. Review everything your agent writes before you open a pull request: the checks below apply to it like any other code.

## Making a change

1. Create a branch in your fork named after the change: `feat/…`, `fix/…`, `chore/…` or `docs/…`.
2. Write the change **with tests** (see [Tests](#tests)).
3. Add a user-facing change under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md), in the right section (`Added`, `Changed`, `Fixed`, …), written for users. A change with nothing user-facing gets the `skip-changelog` label instead. Never edit released sections or version numbers: the release workflow does that.
4. Run the [checks](#checks).
5. Commit with a [Conventional Commit](https://www.conventionalcommits.org) message (`feat: …`, `fix: …`) and open a pull request into `main`. The template has a short checklist.

A maintainer reviews it once CI passes. Merging releases automatically.

## Tests

Every change comes with tests:

- **Backend changes** get Pest feature tests (`tests/Feature`), or unit tests for pure logic (`tests/Unit`).
- **Pages** get browser tests (`tests/Browser`). A new page is added to the `pages` dataset in `tests/Browser/ThemeTest.php`, so it's checked in light and dark mode with an accent colour.
- **Fake everything outside the app.** Tests never call the real GitHub API or run `claude`, `gh` or `git`: use `Http::fake()`, `Process::fake()` and `Tests\Browser\Support\FakeGithub`.
- **Use made-up data.** `octocat` for the user, `acme/…` for repositories, `example.com` for URLs and emails, and obviously fake secrets like `ghp_test`.

CI enforces this in two ways:

- **Tests included:** a pull request that changes code (`app`, `routes`, `database`, `config`, `bootstrap`, `resources/js` or `resources/views`) must also change something under `tests/`. If it only changes copy, styling or layout, a maintainer labels it `skip-tests`.
- **Coverage:** the unit and feature tests must cover at least **80%** of the lines in `app/`, or the PHP check fails.

To see coverage locally you need [PCOV](https://github.com/krakjoe/pcov) or Xdebug:

```bash
php artisan test --testsuite=Unit,Feature --coverage --min=80
```

## Design rules

PRism has light, dark and system modes and six accent colours, and every screen has to work in all of them, down to the 960px minimum window width. The full rules are under "Themes" and "Design conventions" in [AGENTS.md](AGENTS.md). The short version:

- Use the semantic colours (`bg-background`, `text-foreground`, `bg-muted`, `text-muted-foreground`, `border-border`, `bg-primary`, `ring-ring`, …), never `gray-*`, `neutral-*`, `zinc-*`, black or white on app chrome and controls.
- Fixed colours are only for meaning (status, severity, CI results, diffs), with a `dark:` variant for pale tints and dark text.
- Selected states use the accent (`bg-primary text-primary-foreground`, or `border-primary ring-1 ring-primary bg-primary/5` for cards).
- Every interactive element shows keyboard focus in the accent colour. Icon-only buttons have an `aria-label`.
- Reuse the shared components (`Badge`, `SeverityBadge`, `EmptyState`, `MarkdownText`, `filterChipClass()`) before writing new markup.

`npm run lint:design` checks the colour and focus rules automatically, and CI runs it. Check everything else by eye in light mode, dark mode and with an accent colour before you open the pull request.

## Checks

CI runs all of these on every pull request, and `main` only accepts pull requests that pass:

```bash
vendor/bin/pint --test                                # fix with: vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G
php artisan test --compact --testsuite=Unit,Feature   # CI adds --coverage --min=80
npx eslint .
npm run lint:design
npx prettier --check resources/                       # fix with: npx prettier --write resources/
npx vue-tsc --noEmit
npm run build
vendor/bin/pest --testsuite=Browser --parallel        # after npm run build; first time: npx playwright install chromium
```

CI also checks that `CHANGELOG.md` was updated, and that code changes come with tests.

## Licence

By contributing, you agree that your contributions are licensed under the same licence as PRism (see [LICENSE](LICENSE)).
