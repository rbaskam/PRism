# Security policy

PRism holds a GitHub token and Claude credentials, reads code from repositories you may not control, and can write to GitHub on your behalf. Security reports are very welcome.

## Reporting a vulnerability

**Please don't open a public issue.** Report it privately instead:

1. Go to [Security → Report a vulnerability](https://github.com/rbaskam/PRism/security/advisories/new).
2. Describe the problem, how to reproduce it, and what an attacker could do with it.
3. Include the PRism version (Settings → About) and your platform.

Use made-up data in your report: no real tokens, private repository names or other people's details.

You'll get a reply as soon as possible, usually within a week. Once a fix is released, the advisory is published and you're credited, unless you'd rather not be.

## Supported versions

Only the latest release gets security fixes. PRism checks for updates itself and shows them in Settings → About.

## What's in scope

For example:

- A pull request, comment or repository file that makes PRism (or the Claude Code it runs) execute code, read files outside the repository, leak a secret, or write to GitHub without the user clicking a button for it.
- A website or another program on the same computer that can read PRism's data or use it to act on GitHub.
- Getting past the app lock, or reading secrets PRism stores.
- Fixes pushed to the wrong repository or branch.

Problems in GitHub, Claude Code, NativePHP or Electron themselves should be reported to those projects, though a heads-up here is welcome if PRism is affected.
