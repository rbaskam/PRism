<p align="center">
  <img src="resources/icons/prism.svg" width="112" alt="PRism logo">
</p>

<h1 align="center">PRism: GitHub pull request review with Claude Code</h1>

<p align="center">
  A PR dashboard and AI code review tool for all your GitHub repositories and team review requests. A free desktop app for macOS and Windows.
</p>

<p align="center">
  <a href="https://github.com/rbaskam/PRism/actions/workflows/ci.yml"><img src="https://github.com/rbaskam/PRism/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI"></a>
  <a href="https://github.com/rbaskam/PRism/actions/workflows/release.yml"><img src="https://github.com/rbaskam/PRism/actions/workflows/release.yml/badge.svg" alt="Release"></a>
  <a href="https://github.com/rbaskam/PRism/releases/latest"><img src="https://img.shields.io/github/v/release/rbaskam/PRism?label=latest" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/platform-macOS%20%7C%20Windows-lightgrey" alt="macOS and Windows">
  <a href="LICENSE"><img src="https://img.shields.io/badge/licence-MIT%20%2B%20Commons%20Clause-blue" alt="Licence: MIT with the Commons Clause"></a>
</p>

**PRism** is a desktop app for developers and tech leads who review a lot of GitHub pull requests across many repositories. It collects review requests across multiple repositories and organisations, including team review requests sent to groups like `@org/reviewers`, into one PR dashboard, colour coded by where each pull request stands: red when it's waiting for your review, green once you've approved it. Run an AI code review with [Claude Code](https://claude.com/claude-code), triage what it finds, ask Claude about any lines in the diff, let it make fixes in an isolated git worktree, and decide exactly what gets written back to GitHub.

PRism runs on your own Mac or Windows PC with your own GitHub and Claude accounts. There's no PRism server and nothing to sign up for. It's built with [Laravel](https://laravel.com), Vue and [NativePHP](https://nativephp.com), and it's free to use, with its source code here on GitHub under the [MIT licence with the Commons Clause](#licence).

**[Download PRism for macOS or Windows](https://github.com/rbaskam/PRism/releases/latest)**

## Contents

- [Why PRism?](#why-prism)
- [Features](#features)
  - [One PR dashboard for all your GitHub repositories](#one-pr-dashboard-for-all-your-github-repositories)
  - [Team review requests](#team-review-requests)
  - [AI code review with Claude Code](#ai-code-review-with-claude-code)
  - [Ask Claude about code](#ask-claude-about-code)
  - [Review, comment and reply from the app](#review-comment-and-reply-from-the-app)
  - [Files changed with Viewed checkboxes](#files-changed-with-viewed-checkboxes)
  - [Fix with Claude in an isolated git worktree](#fix-with-claude-in-an-isolated-git-worktree)
  - [Inbox and desktop notifications](#inbox-and-desktop-notifications)
  - [Repositories, organisations and local clones](#repositories-organisations-and-local-clones)
  - [App lock with a PIN and Touch ID](#app-lock-with-a-pin-and-touch-id)
  - [Light and dark mode with accent colours](#light-and-dark-mode-with-accent-colours)
  - [Updates and what's new](#updates-and-whats-new)
- [Privacy and security](#privacy-and-security)
- [Install](#install)
- [How to use PRism](#how-to-use-prism)
  - [Keyboard shortcuts](#keyboard-shortcuts)
- [FAQ](#faq)
- [Run from source](#run-from-source)
- [Build the installers](#build-the-installers)
- [Contributing](#contributing)
- [CI and releases](#ci-and-releases)
- [Configuration](#configuration)
- [Licence](#licence)

## Why PRism?

If you review code in several repositories, or you're a tech lead whose team is added to every pull request, review requests end up scattered across repository pages, team requests and notification emails. PRism is built for exactly that job.

- **See everything that needs you in one place.** Every open pull request that involves you, across up to 500 repositories and all your organisations, on one screen with counts: how many are awaiting your review, how many you've approved, and which have new commits since you last looked.
- **Don't miss team review requests.** A request sent to `@org/backend` or `@org/reviewers` counts as a request for you. It's labelled "via @org/team", and you can filter by team.
- **Get an AI first pass before you start.** Claude Code reviews the pull request in the background and lists findings by severity, from critical to low, with the file, the line and a suggested fix. It can even start automatically when your review is requested, so the findings are ready when you open it.
- **Triage fast and keep your own judgement.** Mark a finding read, not useful or not a blocker with one key. Re-runs re-check earlier findings, mark fixed ones as resolved and don't repeat the ones you've already dealt with.
- **Ask questions in context.** Highlight lines in the diff and ask "is this right?" or "is there a better way to write this?" without copying code into a chat window.
- **Decide what reaches GitHub.** Nothing is posted, replied to or pushed until you turn it on in Settings and click the button. Claude reviews with read-only tools.
- **Keep it on your machine.** PRism runs locally with your GitHub token and your Claude Code login, Claude subscription or Anthropic API key. Your secrets are stored encrypted.
- **Use it without AI too.** The dashboard, team review requests, Files changed, Conversation, Inbox and desktop notifications all work before you connect Claude.

## Features

### One PR dashboard for all your GitHub repositories

- **My pull requests** lists every open pull request you opened, were asked to review (directly or through one of your teams), are assigned to or have reviewed, across all the repositories you follow.
- **Colour coded by where it stands for you:** red when it's awaiting your review, green when you approved it, amber when you requested changes, blue when you commented, and purple for your own pull requests.
- **Summary cards with counts** for awaiting your review, approved by you (including how many have new commits since), changes requested, your pull requests and via teams. Click a card to filter by it.
- **Filters** for status, repository, how your review was requested (directly, through any team or through one team), hiding drafts, only pull requests with AI findings, only failing checks, and recently closed (closed or merged in the last two weeks, so you can get back to their AI reviews). Filters are remembered for each page.
- **Search** by title, number, author, repository, branch or label. Press `/` to jump to the search box.
- **Sort** by recently updated, oldest first, newest first, largest change, most AI findings or awaiting review first, and **group by repository** with groups you can collapse.
- **Everything at a glance on each row:** labels, draft state, CI checks, merge conflicts, lines added and removed, files changed, comments, "New commits since your review", the number of open and new AI findings, and whether an AI review is running.
- **Repositories** shows a card for each repository with its open pull requests, how many await your review and how many you've approved. Each repository has its own page listing all its open pull requests, including ones that don't involve you.
- A notice tells you when you have review requests in repositories you don't track yet.
- The sidebar shows how many pull requests await your review and how many notifications are unread. On macOS, the Dock icon shows the number awaiting your review.
- PRism syncs with GitHub every five minutes. Use **Sync with GitHub** in the sidebar to sync straight away.

### Team review requests

- Review requests sent to your GitHub teams are recognised as requests for you and labelled "via @org/team". This needs the `read:org` scope, and PRism warns you if your token is missing it.
- Filter by "requested from you directly", "requested via any team" or one particular team, or click the **Via teams** card.
- Each pull request shows who a review is requested from, including "you via @org/team".
- Settings → GitHub lists the teams PRism found for you.
- Desktop notifications cover team mentions as well as mentions of you.

### AI code review with Claude Code

- **Review with AI** runs [Claude Code](https://claude.com/claude-code) on your computer in the background, so you can keep working. The sidebar shows which reviews are running.
- **Claude reads the real code.** When the repository's local clone is linked, Claude reads the code at the pull request's exact commit (in a temporary worktree), not only the diff. Without a clone, it reviews from the diff.
- **A thorough default review prompt** reads the changed files in full and checks correctness, security, performance, tests and maintainability, with clear severity levels.
- **A customisable prompt.** Change it in Settings → PR review, with placeholders such as `{repository}`, `{number}`, `{title}`, `{author}`, `{head}`, `{base}` and `{url}`, or reset it to the default.
- **Structured findings**, each with a severity (critical, high, medium or low), a category (bug, security, duplication, complexity, missing tests, performance or other), the file and line, an explanation and a suggested fix.
- **Triage** each finding as read, not useful or not a blocker, one at a time with keyboard shortcuts or several at once. Filter findings by status (new, read, not useful, not a blocker, resolved or posted) and by severity.
- **Re-runs re-check instead of repeating.** Earlier findings and your decisions on them are sent back to Claude, fixed findings are marked as resolved, and PRism tells you when new commits arrive after a review.
- **A summary and a verdict** for every review: Claude suggests approving, suggests changes, or has comments.
- **Review history** for every run: new, still present and resolved findings, cost, duration, model, the commit that was reviewed and the prompt that was used.
- **Automatic reviews (optional).** Turn on "Automatically run an AI review when my review is requested" in Settings → PR review, and the findings are ready by the time you open the pull request.
- **Your Claude account, your limits.** Use your existing Claude Code login, a Claude Pro or Max subscription token from `claude setup-token`, or an Anthropic API key. You can pick the model (for example `opus` or `sonnet`) and set a maximum spend per review in Settings → Claude.

### Ask Claude about code

- In **Files changed**, click a line number (shift-click for a range) or select some code, then press `a` to ask Claude about it, for example "Is this right?" or "Is there a better way to write this?".
- The answer appears under the lines, and you can ask follow-up questions in the same thread.
- Every question is saved. The **Questions** tab lists them by file with **Show in diff** links, and marks answers that were given on an earlier commit.
- You get a desktop notification when Claude has answered.

### Review, comment and reply from the app

- **Approve**, **Request changes** or **Comment** with a message, and attach AI findings: findings with a file and line become inline comments on the diff, and the rest are added to the review message.
- **Post a single finding** to the pull request, after editing it if you like. It's placed on the line in the diff when that line is part of the change, or added as a comment that mentions the file and line.
- **Comment on lines** in **Files changed**: click a line number (shift-click for a range), select some code or use the arrow keys, then press `c`. **Comment now** posts it to GitHub straight away; **Add to review** keeps it with your review, like a pending review on GitHub, and posts it when you approve, request changes or comment. Pending comments show under their lines, where you can edit or remove them. Review threads show under the lines they're on, where you can reply to and resolve them.
- **Suggest a change**: for lines on the new version, insert the selected code as a GitHub suggestion, edit it, and the author can apply it with one click on GitHub.
- **Conversation** shows reviews, review threads grouped by file (with how many are unresolved) and comments. Reply, add a comment, resolve threads, or let Claude **draft a reply** for you to edit, optionally telling it what to say ("agree, I'll fix it in a follow-up").
- **Description** shows the pull request description as GitHub renders it, and **Open on GitHub** is always one click away.
- Conversations and diffs are saved, so they open instantly until something changes on GitHub. **Refresh from GitHub** fetches the latest straight away.

### Files changed with Viewed checkboxes

- The full diff, with a **Viewed** checkbox for each file that stays in sync with github.com and a progress bar showing how much you've viewed.
- **Syntax highlighting** for over 40 languages, in light and dark mode, with the words that changed within a line highlighted too. Code is coloured with [Shiki](https://shiki.style) and shown as text, never inserted as HTML.
- **Unified or side by side**: switch the layout in the toolbar; PRism remembers your choice.
- **Show the hidden lines** between and after the changes, to read the code around them.
- **AI findings under their lines**, so you can triage them next to the code.
- Hide viewed files, filter files by name, jump to a file from the file list, and expand or collapse every file at once. Press `j`/`k` to move between files and `v` to mark one viewed.
- See whether each file was added, modified, renamed or deleted, and how many lines were added and removed.

### Fix with Claude in an isolated git worktree

- **Fix with Claude** asks Claude to change the code. Choose the findings or review threads to address, or describe what you want, for example "Rename the helper and add a test".
- Claude works in a separate git worktree made from your local clone, at the pull request's exact commit, and commits there. Your own checkout and branch are never touched.
- Review the diff in the **Changes** tab, then **push** it to the pull request's branch, **open a new pull request** into that branch, or discard it. Earlier attempts are kept, and you can try again.
- It's off by default. It needs your local code folder (Settings → GitHub) and the code-change settings in Settings → PR review, and pushing is a separate setting.

### Inbox and desktop notifications

- **Inbox** shows your GitHub notifications: review requests, mentions, team mentions, assignments, activity on your pull requests, CI and more. Show unread or all, filter by reason, search, and mark notifications as read one at a time or all at once.
- Clicking a pull request notification, on the desktop or in the Inbox, opens it in PRism, even if it hasn't synced yet or is in a repository you don't track.
- PRism checks GitHub every minute. **Choose which desktop notifications you get** in Settings → Notifications: review requests, mentions, later activity on those pull requests, activity on your own pull requests, replies where you commented, CI results, new pull requests in your repositories, and finished AI work (reviews with their number of findings, answers to your questions and fixes that are ready).
- Review requests and mentions alert you once per pull request, not on every later comment or commit, unless you ask for that too.
- **Notification sounds:** on macOS, pick any of the built-in system sounds; on Windows, keep the default sound or have none. You can also turn sounds off. **Send test notification** plays your choice before you save it.

### Repositories, organisations and local clones

- **Connect GitHub** with your GitHub CLI (`gh`) login, a classic personal access token, or "Sign in with GitHub" (see [Configuration](#configuration)). Settings → GitHub shows which scopes your token has.
- **Choose the repositories to follow**, up to 500. Search them, show only the ones with review requests for you, or show only the ones you've selected.
- **Organisation repositories** are listed even when GitHub leaves them out of your own repository list. When an organisation needs single sign-on (SAML) authorisation, PRism shows a warning with a direct link to fix it. You can hide these warnings.
- **Local code folder.** Point PRism at the folder where you keep your clones and it finds the checkouts of your repositories (up to three folders deep) and links them, without moving or changing anything. It also offers to track repositories it found that you don't follow yet. You can set or change each repository's checkout separately.

### App lock with a PIN and Touch ID

- Lock PRism with a PIN of 4 to 8 digits, and unlock it with Touch ID on Macs with a fingerprint reader.
- Lock it automatically after a period of inactivity, every time the app starts, or straight away with `⌘L` (`Ctrl+L` on Windows).

### Light and dark mode with accent colours

- Choose Light, Dark or System mode in Settings → Appearance.
- Pick an accent colour (Neutral, Blue, Green, Violet, Rose or Orange) for buttons, switches, focus rings and the selected item in the sidebar, in both light and dark mode.

### Updates and what's new

- PRism checks its GitHub releases for new versions every six hours, or when you click **Check for updates** in Settings → About, and shows **Update available** in the sidebar.
- **Download** picks the right installer for your computer. Install it over the old version; your data and settings are kept.
- A **What's new** note appears after you update, and Settings → About has the full changelog and a link to report an issue.

## Privacy and security

PRism is designed so that you stay in control of your code and your GitHub account.

- **It runs on your computer.** PRism talks only to GitHub and to the Claude Code CLI you installed. There's no PRism server, account or analytics.
- **Claude is read-only for reviews and questions.** Claude Code runs with read-only tools: it can read the code and fetch the pull request, but it can't edit files or write to GitHub.
- **Nothing is written to GitHub without you.** Posting findings, commenting, replying to comments and pushing code changes each need the matching setting in Settings → PR review (all off by default), and then a click on the button. AI reviews never post anything by themselves.
- **Code changes stay isolated.** Fix with Claude works only in separate git worktrees in PRism's own storage, never in your own checkout, and its changes are pushed only after you've reviewed and approved the diff.
- **Secrets are encrypted.** Your GitHub token and Claude credentials are stored encrypted in the app's local database, with an encryption key unique to your installation. The app lock PIN is stored as a one-way hash. When PRism pushes, git gets your token from the environment, never from the command line.
- **You control the cost.** AI reviews only run when you start them, unless you turn on automatic reviews, and you can set a maximum spend per review in Settings → Claude.

## Install

Download the latest installer from [Releases](https://github.com/rbaskam/PRism/releases/latest).

| Platform | File |
| --- | --- |
| macOS, Apple Silicon (M1 and later) | `PRism-x.y.z-arm64.dmg` |
| macOS, Intel | `PRism-x.y.z-x64.dmg` |
| Windows 10/11 (x64) | `PRism-x.y.z-setup.exe` |

- **macOS:** open the `.dmg` and drag PRism into Applications. Until releases are signed with an Apple Developer ID, macOS blocks the first launch with "Apple could not verify PRism". To allow it, either:
  - try to open PRism once, then open **System Settings → Privacy & Security**, scroll down and click **Open Anyway**, or
  - run `xattr -dr com.apple.quarantine /Applications/PRism.app` in Terminal.

  Until you do, Spotlight shows PRism with a 🚫 symbol. The downloaded `.dmg` itself always shows macOS's generic disk image icon.
- **Windows:** run the installer. If SmartScreen warns about an unknown publisher, choose **More info → Run anyway**.

PRism checks GitHub for new releases. When one is available it shows **Update available** in the sidebar. Install the new version over the old one; your data and settings are kept.

### What you need

- A GitHub account. PRism connects with your GitHub CLI login, a personal access token (classic, scopes `repo`, `read:org`, `notifications`), or "Sign in with GitHub" (see [Configuration](#configuration)).
- For AI features, [Claude Code](https://claude.com/claude-code) installed and signed in (`claude`), or an Anthropic API key. Claude is optional and can be connected later.
- The [GitHub CLI](https://cli.github.com) (`gh`) is recommended. Claude uses it to read pull requests.
- `git` and local clones of your repositories, if you want Claude to read the code at the pull request's exact commit or make fixes. Point PRism at the folder you keep them in (Settings → GitHub → Local code folder).

> If your organisation uses SAML single sign-on, authorise your token for it. On your tokens page, click **Configure SSO** next to the token. PRism shows a warning with a direct link when repositories are hidden for this reason.

## How to use PRism

### 1. Set it up (first launch)

1. **Connect GitHub.** Use your GitHub CLI login if you have `gh` signed in, or paste a classic personal access token with the `repo`, `read:org` and `notifications` scopes. `read:org` is what lets PRism recognise review requests sent to your teams.
2. **Choose repositories.** Pick the repositories to follow. Ones where someone is waiting on your review are suggested first. If an organisation's repositories are missing, PRism shows a warning with a link to fix it (usually single sign-on authorisation).
3. **Connect Claude (optional).** Use your existing Claude Code login, an Anthropic API key, or a token from `claude setup-token`. You can skip this and connect Claude later in Settings → Claude.

### 2. See what needs you

**My pull requests** lists every open pull request that involves you across the repositories you follow, colour coded:

| Colour | Meaning |
| --- | --- |
| 🔴 Red | Waiting for your review, directly or through one of your teams ("via @org/team") |
| 🟢 Green | You approved it |
| 🟠 Amber | You requested changes |
| 🔵 Blue | You commented |
| 🟣 Purple | Your own pull request |

Click the summary cards to filter, or combine the filters: status, repository, requested directly or via a team, hide drafts, only with AI findings, only failing checks, recently closed. Press `/` to search. **Repositories** shows the same list for one repository, including pull requests that don't involve you. PRism syncs with GitHub every five minutes; use **Sync with GitHub** in the sidebar to sync now.

### 3. Review a pull request

Open a pull request to see its tabs:

- **AI findings.** Click **Review with AI** and Claude Code reviews the pull request using your prompt (Settings → PR review). It runs in the background, so you can keep working. For each finding, mark it **Read**, **Not useful** or **Not a blocker**, or post it to the pull request. Run the review again after new commits: Claude re-checks the earlier findings, marks fixed ones as resolved and doesn't repeat ones you've already triaged.
- **Files changed.** The diff, with a **Viewed** checkbox per file that stays in sync with github.com. Select lines (click a line number, shift-click to extend, or drag across the code) and press `a` to **ask Claude** about them, for example "Is there a better way to write this?". Answers appear under the lines, you can ask follow-ups, and everything is saved.
- **Questions.** Every question you've asked on this pull request, with links back to the lines.
- **Changes.** Results of **Fix with Claude** (see below).
- **Conversation.** Reviews, review threads and comments. Reply, let Claude draft a reply for you to edit, and resolve threads. Use **Refresh from GitHub** to fetch the latest straight away.
- **Description** and **Review history.**

When you're done, use **Approve**, **Request changes** or **Comment** in the header, optionally attaching findings as inline comments.

### 4. Let Claude make fixes

For pull requests you're working on, **Fix with Claude** asks Claude to change the code: pick findings or review threads to address, or describe what you want. Claude works in a separate copy of your local clone at the pull request's exact commit, so your own checkout and branch are never touched. You review the diff, then **push** it to the pull request's branch or **open a new pull request** into it, or discard it. This needs your local code folder (Settings → GitHub) and the code-change settings in Settings → PR review.

### 5. Stay on top of things

- **Inbox** shows your GitHub notifications. PRism checks every minute and sends a desktop notification for review requests and mentions (including team mentions); clicking one opens the pull request in PRism.
- **Settings → Notifications** chooses which desktop notifications you get and the sound they play.
- **Settings → PR review** controls what PRism may write to GitHub. Posting findings, commenting and replying, and pushing code changes are all off until you turn them on, and nothing happens without you clicking a button. It's also where you can have an AI review start automatically when your review is requested.
- **Settings → Security** adds an app lock with a PIN, Touch ID on Macs, auto-lock after inactivity, and locking when the app starts. Press `⌘L` (`Ctrl+L` on Windows) to lock it straight away.
- **Settings → Appearance** switches between light, dark and system mode and sets the accent colour.
- **Settings → About** shows your version, checks for updates and has the full changelog.

### Keyboard shortcuts

| Where | Keys |
| --- | --- |
| Pull request and repository lists | `/` search |
| AI findings (with a finding focused) | `j`/`k` or ↓/↑ move · `Enter` expand · `r` read · `u` not useful · `b` not a blocker · `n` back to new · `x` select · `p` post to the pull request · `f` fix with Claude |
| Files changed | `j`/`k` next/previous file · `v` mark the file viewed · `↑`/`↓` select lines in a focused diff (`Shift` to extend) · `c` comment on the selected lines · `a` ask Claude about them · `Esc` clear the selection |
| Comment box | `⌘Enter` / `Ctrl+Enter` comment now · `⌘⇧Enter` / `Ctrl+Shift+Enter` add to your review |
| Dialogs and comment boxes | `⌘Enter` / `Ctrl+Enter` send |
| Anywhere | `⌘B` / `Ctrl+B` show or hide the sidebar |
| Anywhere (with the app lock on) | `⌘L` / `Ctrl+L` lock |

## FAQ

**Is PRism free?**
Yes. It's free to use, change and share under the [MIT licence with the Commons Clause](#licence), which means it can't be sold. If you use the AI features, you pay only for your own Claude usage.

**Do I need Claude to use PRism?**
No. The PR dashboard, team review requests, Files changed, Conversation, Inbox and desktop notifications all work with just a GitHub account. Connect Claude Code whenever you want AI code reviews, answers and fixes.

**Which Claude accounts work?**
Your existing Claude Code login, a long-lived Claude Pro or Max subscription token from `claude setup-token`, or a pay-as-you-go Anthropic API key.

**Will PRism post comments or push code on its own?**
No. Posting findings, commenting and replying, changing code and pushing are each off until you turn them on in Settings → PR review, and even then nothing happens until you click a button.

**Does Claude change my local checkout?**
No. Reviews and questions use read-only tools. Fix with Claude works in a separate git worktree in PRism's own storage, and its changes are pushed only after you've reviewed the diff.

**Does it work with GitHub organisations that use single sign-on?**
Yes. Authorise your token for the organisation's SAML single sign-on, and PRism warns you, with a direct link, when an organisation's repositories are hidden because it isn't authorised yet.

**Which platforms are supported?**
macOS (Apple Silicon and Intel) and Windows 10 and 11 (x64).

## Run from source

PRism is a [Laravel](https://laravel.com) 12 app with Inertia, Vue 3, TypeScript and Tailwind CSS, packaged as a desktop app with [NativePHP](https://nativephp.com). Running from source opens the real desktop app with hot reloading.

### Requirements

- PHP 8.3 with the `zip`, `sqlite3`, `pdo_sqlite`, `mbstring` and `intl` extensions
- Composer 2
- Node.js 22 and npm
- git

### macOS

```bash
brew install php@8.3 composer node git      # if you don't have them yet
git clone https://github.com/rbaskam/PRism.git
cd PRism
composer install
npm install
cp .env.example .env
php artisan key:generate
composer native:dev
```

### Windows

Install PHP 8.3 (for example with [Laravel Herd](https://herd.laravel.com/windows) or from [windows.php.net](https://windows.php.net/download/)), [Composer](https://getcomposer.org/download/), [Node.js 22](https://nodejs.org) and [Git for Windows](https://git-scm.com/download/win). Then, in PowerShell:

```powershell
git clone https://github.com/rbaskam/PRism.git
cd PRism
composer install
npm install
copy .env.example .env
php artisan key:generate
composer native:dev
```

`composer native:dev` starts the desktop app and Vite together. The app's database (`database/nativephp.sqlite`) is created and migrated on the first run. After pulling changes that add migrations, run `php artisan native:migrate`.

If the app doesn't start, run `php artisan native:run -v` to see Electron's output. `composer native:dev` clears `NODE_OPTIONS`, because Electron refuses to start when it contains flags such as `--openssl-legacy-provider`. If you run `php artisan native:run` directly, unset it first.

### Web version with Docker (optional)

`./vendor/bin/sail up -d` serves the UI at http://localhost:7081 (MySQL on port 33081), with queue workers and the scheduler. This is handy for working on the interface. The container can't see your computer's `claude` and `gh` commands, your local clones or desktop notifications, so use the desktop app for AI features.

## Build the installers

Build on the platform you're targeting:

```bash
# On a Mac: Apple Silicon and Intel .dmg files
php artisan native:build mac all

# On Windows: the setup .exe
php artisan native:build win x64
```

The installers are written to `nativephp/electron/dist/`. The build runs `npm run build` first and uses `NATIVEPHP_APP_VERSION` from `.env` as the app version, so set it to the version you're releasing.

### Signing the macOS app

Signed and notarised builds open without the "could not verify" warning. This needs an [Apple Developer Program](https://developer.apple.com/programs/) membership. With a **Developer ID Application** certificate in your Keychain, `php artisan native:build` signs the app automatically; set these environment variables as well to notarise it:

| Variable | Value |
| --- | --- |
| `NATIVEPHP_APPLE_ID` | Your Apple ID email address |
| `NATIVEPHP_APPLE_ID_PASS` | An [app-specific password](https://account.apple.com/account/manage) for that Apple ID |
| `NATIVEPHP_APPLE_TEAM_ID` | Your Team ID, shown under Membership details on [your developer account](https://developer.apple.com/account) |

Create the certificate in Xcode (Settings → Accounts → Manage Certificates → **+** → Developer ID Application) or on the [certificates page](https://developer.apple.com/account/resources/certificates/add).

## Contributing

1. Create a branch from `main` (`feat/…`, `fix/…`).
2. Add your user-facing change under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md), in the right section (`Added`, `Changed`, `Fixed`, …). Changes with nothing user-facing get the `skip-changelog` label instead.
3. Run the checks:
   ```bash
   vendor/bin/pint --test
   vendor/bin/phpstan analyse --memory-limit=1G
   php artisan test --compact
   npx eslint .
   npx prettier --check resources/
   npx vue-tsc --noEmit
   npm run build
   ```
4. Commit with a [Conventional Commit](https://www.conventionalcommits.org) message and open a pull request (`gh pr create --fill`).

AI coding agents get the same rules from [AGENTS.md](AGENTS.md) and the skills in `.ai/skills` (synced to `.claude`, `.cursor` and `.junie` by `php artisan boost:update`).

## CI and releases

- **Every pull request** runs [CI](.github/workflows/ci.yml): Pint, Larastan (PHPStan), the Pest/PHPUnit test suite, ESLint, Prettier, vue-tsc, a production frontend build, and a check that `CHANGELOG.md` was updated. `main` is protected, so a pull request can only be merged once all of them pass.
- **Merging into `main`** runs CI again. When it passes, the [Release](.github/workflows/release.yml) workflow builds a release, if `## [Unreleased]` in the changelog has entries:
  1. Works out the next version: `### Removed` or `BREAKING` bumps major, `### Added`/`### Changed`/`### Deprecated` bump minor, anything else bumps patch.
  2. Moves those entries into a new version section, commits `chore(release): vX.Y.Z` and tags `vX.Y.Z`.
  3. Runs `php artisan native:build` on GitHub's macOS and Windows machines. This is the same command as building locally, so the installers are identical.
  4. Publishes the GitHub release with the installers attached.

To rebuild the installers for an existing version, run the **Release** workflow manually (Actions → Release → Run workflow) and enter the version, for example `0.2.0`.

### One-time repository setup

The repository owner runs this once (it needs admin rights):

```bash
gh auth switch --user <owner>        # or gh auth login, if the owner isn't signed in yet
bash .github/scripts/setup-repository.sh
```

It protects `main` with a ruleset, so changes need a pull request and passing CI, and branches can't be force-pushed or deleted. It also adds a deploy key (the `RELEASE_DEPLOY_KEY` secret) that lets the release workflow push its version commit to the protected branch, and creates the `skip-changelog` label.

### Publishing a release by hand

If you'd rather release from your own machines, `php artisan native:publish` builds the app and uploads the installers to a draft GitHub release. Add these to `.env` on each machine you publish from:

```bash
NATIVEPHP_UPDATER_ENABLED=true
NATIVEPHP_UPDATER_PROVIDER=github
GITHUB_OWNER=rbaskam
GITHUB_REPO=PRism
GITHUB_TOKEN=github_pat_…              # fine-grained token for this repository with Contents: read and write
```

NativePHP removes every `GITHUB_*` key from the `.env` it bundles into the app, so the token never ships. Then:

1. Cut the version through a pull request: on a branch, run `php artisan changelog release "$(php artisan changelog next-version)"`, commit and merge it.
2. Set `NATIVEPHP_APP_VERSION` in `.env` to that version and run `php artisan native:publish mac` on a Mac (Apple Silicon and Intel) and `php artisan native:publish win` on Windows.
3. Add the changelog and publish: `php artisan changelog notes "$version" > release-notes.md`, then `gh release edit "v$version" --notes-file release-notes.md --draft=false --latest`.

Running copies of PRism find new releases in Settings → About and show "What's new" from the changelog after updating.

## Configuration

Optional settings in `.env`:

| Key | Purpose |
| --- | --- |
| `GH_OAUTH_CLIENT_ID` | Client ID of a GitHub OAuth App with device flow enabled. It adds "Sign in with GitHub" to onboarding. |
| `PRISM_REPOSITORY` | The repository releases are read from (default `rbaskam/PRism`). |
| `NATIVEPHP_APP_ID` | The app's bundle identifier. |

## Licence

PRism is free to use, modify and share under the [MIT licence with the Commons Clause](LICENSE). The Commons Clause means you may not sell it or offer it as a paid product or service. This makes PRism source-available rather than "open source" in the OSI sense.
