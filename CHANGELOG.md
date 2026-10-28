# Changelog

All notable changes to PRism are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Every pull request adds its user-facing changes under **Unreleased**. When it is merged, the release workflow turns
**Unreleased** into the next version: breaking changes or a `### Removed` section bump the major version, `### Added`,
`### Changed` or `### Deprecated` the minor version, and anything else (`### Fixed`, `### Security`) the patch version.

## [Unreleased]

### Added

- AI findings now show in Files changed under the lines they're about, so you can read them next to the code and mark them read, not useful or not a blocker there. The file list and each file's header show how many findings it has.
- Files changed highlights the words that changed within an edited line, as GitHub does.

### Changed

- Accent colours are easier to read. In light mode, Green, Rose and Orange buttons now have enough contrast for their text; in dark mode, Violet, Rose and Orange do, and their focus rings stand out from the page. The loading bar, links in descriptions and comments, selected lines in a diff, active filters and the selected option in dialogs now take your accent colour too.
- Critical findings have a solid red badge, so they stand out from High ones.
- Claude's review summaries and finding explanations show formatting such as code and bold text, instead of raw Markdown.
- Buttons, links and other controls show a focus ring in your accent colour when you move around with the keyboard.

### Fixed

- In a narrow window, pages no longer run off the right-hand edge. The pull request header, file headers and comment threads wrap instead, and the pull request tabs fade at the edge when there are more to scroll to.
- Settings stays highlighted in the sidebar on every settings page, not just GitHub, and the selected settings section is visible in dark mode.
- When all your pull requests had been closed recently, My pull requests and repository pages said "No pull requests match your filters", with a Clear filters button that did nothing. They now say nothing needs you, and offer to show the recently closed ones.
- Switches that are off or unavailable were hard to see in dark mode.
- Dark mode no longer flashes white while a page loads.
- Settings → About no longer says "You're up to date" before PRism has checked for updates.
- Screen readers now announce unread notifications, form errors and failed actions.

## [0.15.0] - 2026-10-23

### Added

- Comment on lines of code in Files changed, not just ask Claude about them. Select a line or a range and press `c` (or click **Comment**) to post a review comment to GitHub on those lines. Review threads from GitHub now also show under the lines they're on, where you can reply to and resolve them. Commenting needs "Allow commenting and replying" turned on in Settings → PR review (it was called "Allow replying to comments").

## [0.14.1] - 2026-10-20

### Fixed

- Repository names on the Repositories page were cut off when the owner's name was long. Each card now shows the repository name in full on its own line, with the owner underneath.

## [0.14.0] - 2026-10-16

### Changed

- PRism now syncs with GitHub as soon as it opens and whenever you switch back to its window, so a pull request you just approved, commented on or opened on github.com shows up within seconds instead of at the next sync, up to five minutes later. The sidebar shows Syncing… while it catches up.

## [0.13.2] - 2026-10-16

### Fixed

- PRism no longer writes an error to its log every minute while everything is working. Since v0.13.0, the once-a-minute check that PRism's server is still running was wrongly logged as a failure.

## [0.13.1] - 2026-10-16

### Fixed

- Files you tick as Viewed on github.com now show as viewed in PRism too, the next time you open a pull request's Files changed tab. Before, PRism only picked them up after new commits were pushed.

## [0.13.0] - 2026-10-16

### Added

- If PRism's background server stops responding, PRism now says so and offers a Restart PRism button, instead of showing a raw error such as "Method Not Allowed". If even the restart can't get through, PRism restarts by itself within a minute, and it notes the restart in its log.

## [0.12.1] - 2026-10-16

### Fixed

- The app lock no longer locks you out while you're using the app. Clicking, typing, scrolling and moving the mouse now count as activity, so reading a long diff or writing a question for Claude doesn't trigger the auto-lock; it only locks once you've been away for the time you chose in Settings → Security.

## [0.12.0] - 2026-10-16

### Changed

- On macOS, desktop notifications now play the Glass sound unless you pick another in Settings → Notifications. Windows keeps its own notification sound.

## [0.11.1] - 2026-10-16

### Fixed

- Entering your PIN on a lock screen that had been open for more than two hours, for example overnight, showed "419 Page expired" and you couldn't get back into the app. The desktop app now keeps you signed in, and if a page ever does expire, PRism reloads it with a message so you can try again.

## [0.11.0] - 2026-10-16

### Changed

- Pull request lists now show which filters are hiding pull requests: each active filter appears next to "Showing … pull requests" (for example "Repository: acme/api"), with a × to remove just that one, and the repository and review request drop-downs are highlighted while they filter the list.

### Fixed

- Your own pull requests no longer count as awaiting your review when your team is asked to review them, so the sidebar badge, the Dock badge and the repository cards match the "Awaiting your review" count on My pull requests.

## [0.10.0] - 2026-10-15

### Added

- A notifications bell at the top of every page lists everything PRism alerted you about in the last 30 days, such as review requests, new pull requests, mentions and finished AI reviews, with a count of the ones you haven't read and Mark all as read. Clicking an alert takes you to where it happened: a finished review opens the pull request's AI findings, a comment or mention opens its conversation, and an answer or Claude's changes open the Questions or Changes tab.

### Changed

- The switches in Settings → Notifications now choose what you get alerts about, and "Show desktop notifications" only controls the pop-ups. With pop-ups off, the alerts you switched on are still listed under the bell.

## [0.9.0] - 2026-10-15

### Changed

- Pull requests that are merged or closed on GitHub now leave your lists within a minute or two, without pressing Sync with GitHub. New activity on any pull request you're involved in, such as comments, new commits or a merge, also shows up as soon as GitHub notifies you, instead of at the next full sync every five minutes.

## [0.8.0] - 2026-10-15

### Added

- Snooze a pull request for a day, three days or a week, from its row in a pull request list or from its page. A snoozed pull request leaves your lists and the "awaiting your review" counts, including the sidebar and Dock badges, until the snooze ends or new commits are pushed to it. Turn on the Snoozed filter to see snoozed pull requests or end a snooze early.

## [0.7.1] - 2026-10-15

### Fixed

- Files changed no longer gets stuck on "Loading the changed files from GitHub…" after you open a file on GitHub or sync with GitHub while looking at a pull request.

## [0.7.0] - 2026-10-15

### Added

- In Files changed, the files-viewed progress (for example "13 of 55 files viewed") stays at the top while you scroll through a large pull request.
- Ticking Viewed on a file now scrolls straight to the next file you haven't viewed, so you can review a pull request file by file without scrolling.

## [0.6.1] - 2026-10-15

### Fixed

- Screen readers now announce what each drop-down is for, such as the repository, review request and sort filters on the pull request list, and clicking a drop-down's label (for example "Sound" in Settings → Notifications) now selects it.

## [0.6.0] - 2026-10-15

### Added

- Choose the sound desktop notifications play in Settings → Notifications, or turn sounds off. On macOS you can pick any of the built-in system sounds; on Windows you can keep the default sound or have none. Send test notification plays your pick before you save it.
- Pick an accent colour (Neutral, Blue, Green, Violet, Rose or Orange) in Settings → Appearance. It colours buttons, switches, focus rings and the selected item in the sidebar, in both light and dark mode.

## [0.5.2] - 2026-10-15

### Fixed

- A pull request you had just approved, or otherwise reviewed, went back to "Awaiting your review" in the pull request list for a few minutes when your review had been requested through a team.

## [0.5.1] - 2026-10-14

### Fixed

- Marking a notification as read in the Inbox failed with "GitHub returned 500".

## [0.5.0] - 2026-10-14

### Changed

- Clicking a review request or other pull request notification, on the desktop or in the Inbox, opens the pull request in PRism instead of the browser, even if it hasn't synced yet or is in a repository you don't track.

## [0.4.0] - 2026-10-14

### Added

- "Recently closed" filter on pull request lists, so you can get back to AI reviews of pull requests closed or merged in the last two weeks.
- Choose which desktop alerts you get in Settings → Notifications: review requests, mentions, activity on your own pull requests, replies where you commented, CI results, new pull requests in your repositories, and finished AI work.
## [0.3.0] - 2026-10-14

### Changed

- Clicking "AI review running" in the sidebar opens the pull request being reviewed, or lists them when several reviews are running.
- Review requests and mentions now alert once per pull request instead of on every later comment or commit. Turn on "Later activity on those pull requests" to get those too.
- A more thorough default AI review prompt: it reads the changed files in full and checks correctness, security, performance, tests and maintainability, with clear severity levels. A prompt you've customised is kept.

### Fixed

- Closed or merged pull requests no longer count as awaiting your review.

- Messages such as "AI review started" no longer pop up again and again while a review is running.

## [0.2.0] - 2026-10-14

### Changed

- Pages load much faster in the desktop app: PHP's code cache (OPcache) is now switched on, and pull request conversations and diffs are saved, so they open instantly until something changes on GitHub. Use "Refresh from GitHub" on the Conversation tab to fetch the latest straight away.
- The app's identifier is now `io.github.rbaskam.prism`. macOS may ask you to allow PRism's notifications again after updating.

### Fixed

- The Apple Silicon (arm64) Mac app opens instead of quitting straight away.
- Desktop notifications for review requests and mentions stopped after the first check.

## [0.1.1] - 2026-10-13

### Fixed

- Updating the app keeps your saved GitHub and Claude connections. Each installation now has its own encryption key that survives updates. If you're coming from 0.1.0, you'll be asked to reconnect GitHub once.

## [0.1.0] - 2026-10-13

### Added

- Desktop app for macOS and Windows built with NativePHP.
- Onboarding: connect GitHub (GitHub CLI login, personal access token or "Sign in with GitHub"), choose repositories, and optionally connect Claude Code.
- "My pull requests" dashboard across every tracked repository, coloured by where each pull request stands for you: red when it awaits your review, green once you approved it.
- Review requests sent to your GitHub teams (for example tech leads) are recognised and labelled "via @org/team".
- Filters, sorting and grouping for pull request lists, plus a page per repository.
- AI reviews with Claude Code using a customisable prompt. Findings are saved, can be marked read, not useful or not a blocker, and re-runs re-check earlier findings instead of repeating them.
- Approve, request changes or comment on pull requests, optionally attaching findings as inline comments.
- Read and reply to review threads and conversation comments, resolve threads, and let Claude draft replies.
- Files changed view with "Viewed" checkboxes kept in sync with github.com.
- Highlight lines in a diff and ask Claude about them, with follow-up questions; answers are saved.
- Settings controlling what the app may write to GitHub (posting findings, replying to comments).
- Inbox of GitHub notifications and desktop alerts for review requests and mentions.
- Organisation repositories are listed even when GitHub leaves them out of your own repository list, with a warning and a direct link when an organisation needs single sign-on authorisation. Warnings can be hidden.
- Point PRism at the folder where you keep your clones to link local checkouts. Reviews and questions then read the code at the pull request's exact commit.
- "Fix with Claude": Claude makes fixes in a separate worktree, you review the diff, then push to the branch or open a new pull request.
- App lock with a PIN, Touch ID on Macs, auto-lock after inactivity and locking on launch.
- Update check against GitHub releases, an About page with the changelog, and a "What's new" note after updating.
- New PRism name and logo.
