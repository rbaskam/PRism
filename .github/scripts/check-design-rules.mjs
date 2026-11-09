#!/usr/bin/env node
/**
 * Checks the frontend against PRism's design rules (see "Themes" and "Design conventions" in AGENTS.md), so every
 * colour follows the light/dark mode and the accent the user picked.
 *
 *   node .github/scripts/check-design-rules.mjs        (or: npm run lint:design)
 *
 * It reads every class string in resources/js (each quoted string in .vue and .ts files) and reports:
 *   1. Neutral palette colours (gray, neutral, zinc, slate, stone). Use the semantic tokens instead: bg-background,
 *      bg-muted, text-muted-foreground, border-border, ...
 *   2. Black or white on anything but text: bg-white, border-black, ring-white, ...
 *   3. text-white without a fixed-colour background in the same class string. White text belongs only on a fill
 *      whose colour carries meaning (approve, request changes, alert badges); elsewhere use text-primary-foreground.
 *   4. bg-foreground with text-background, used as a "selected" state. Use bg-primary/text-primary-foreground.
 *   5. A pale tint (bg-amber-50, border-red-200, ...) or dark text (text-amber-900, ...) without a dark: variant in
 *      the same class string: it disappears or glares in dark mode. Saturated fills (bg-red-500 dots, bg-emerald-600
 *      buttons) work in both modes and don't need one.
 *   6. outline-none with no other focus style in the same class string, which hides keyboard focus. focus:outline-none
 *      on an element only focused from code (tabindex="-1") is fine.
 *
 * Vendored shadcn-vue components (components/ui) and the accent swatches are exempt, as are the class strings listed
 * in ALLOWED, each with the reason it is fine.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = new URL('../..', import.meta.url).pathname;
const SOURCE = join(ROOT, 'resources/js');

const EXEMPT = [
    /^resources\/js\/components\/ui\//, // shadcn-vue components, kept as upstream ships them
    /^resources\/js\/components\/AccentPicker\.vue$/, // the swatches show each accent's fixed colour
];

/**
 * Class strings that break a rule on purpose. Keep the reason short and specific.
 */
const ALLOWED = [
    {
        file: 'resources/js/components/QuickSwitcher.vue',
        classes: 'h-12 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground',
        reason: 'The search box is always focused while the switcher is open.',
    },
];

const VARIANTS = String.raw`(?:[a-z0-9-]+(?:\[[^\]]*\])?(?:\/[a-z0-9-]+)?:)*`;
const HUES = 'red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
const NEUTRALS = 'gray|neutral|zinc|slate|stone';
const COLOUR_UTILITIES = 'bg|text|border|ring|ring-offset|outline|divide|fill|stroke|from|via|to|placeholder|decoration|shadow|caret|accent';

const rules = [
    {
        message: 'Neutral palette colour: use a semantic token (bg-muted, text-muted-foreground, border-border, ...)',
        test: (classes) => matches(classes, new RegExp(String.raw`(?:^|\s)${VARIANTS}(?:${COLOUR_UTILITIES})-(?:${NEUTRALS})-\d`)),
    },
    {
        message: 'Hard-coded black or white: use a semantic token (bg-background, text-foreground, border-border, ...)',
        test: (classes) =>
            matches(classes, new RegExp(String.raw`(?:^|\s)${VARIANTS}(?:bg|border|ring|ring-offset|outline|divide|fill|stroke|decoration)-(?:black|white)(?:\/\d+)?(?=\s|$)`)) ||
            matches(classes, new RegExp(String.raw`(?:^|\s)${VARIANTS}text-black(?:\/\d+)?(?=\s|$)`)),
    },
    {
        message: 'text-white without a fixed-colour background: use text-primary-foreground, or put it on a meaningful fill',
        test: (classes) =>
            matches(classes, new RegExp(String.raw`(?:^|\s)${VARIANTS}text-white(?:\/\d+)?(?=\s|$)`)) &&
            !matches(classes, new RegExp(String.raw`(?:^|\s)${VARIANTS}bg-(?:${HUES})-\d`)),
    },
    {
        message: 'bg-foreground with text-background: selected states use bg-primary and text-primary-foreground',
        test: (classes) => /(?:^|\s)bg-foreground(?=\s|$)/.test(classes) && /(?:^|\s)text-background(?=\s|$)/.test(classes),
    },
    {
        message: 'Pale tint or dark text without a dark: variant: add one in the existing style (e.g. dark:bg-amber-500/10 dark:text-amber-200)',
        test: (classes) =>
            matches(
                classes,
                new RegExp(String.raw`(?:^|\s)(?:(?:bg|border|ring)-(?:${HUES})-(?:50|100|200|300)|text-(?:${HUES})-(?:600|700|800|900|950))(?:\/\d+)?(?=\s|$)`),
            ) && !/(?:^|\s|:)dark:/.test(classes),
    },
    {
        message: 'outline-none hides keyboard focus: add focus-visible:ring-2 focus-visible:ring-ring (or another focus-visible: style)',
        test: (classes) =>
            /(?:^|\s)(?:focus-visible:)?outline-none(?=\s|$)/.test(classes) && !/(?:^|\s)(?:[a-z-]+:)*(?:focus-visible|focus-within|group-focus-visible):/.test(classes),
    },
];

function matches(classes, pattern) {
    return pattern.test(classes);
}

function* files(directory) {
    for (const entry of readdirSync(directory)) {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            yield* files(path);
        } else if (/\.(vue|ts)$/.test(entry) && !entry.endsWith('.d.ts')) {
            yield path;
        }
    }
}

/**
 * Every single-line quoted string in the file that looks like a list of utility classes, with its line number.
 */
function* classStrings(source) {
    const lines = source.split('\n');

    for (const [index, line] of lines.entries()) {
        for (const match of line.matchAll(/(["'`])((?:(?!\1).)*)\1/g)) {
            const value = match[2];

            if (/(?:^|\s)[a-z-]+(?::[a-z-]+)*-[a-z0-9/[\]]/.test(value)) {
                yield { line: index + 1, classes: value };
            }
        }
    }
}

const problems = [];

for (const path of files(SOURCE)) {
    const file = relative(ROOT, path);

    if (EXEMPT.some((pattern) => pattern.test(file))) {
        continue;
    }

    for (const { line, classes } of classStrings(readFileSync(path, 'utf8'))) {
        if (ALLOWED.some((allowed) => allowed.file === file && allowed.classes === classes)) {
            continue;
        }

        for (const rule of rules) {
            if (rule.test(classes)) {
                problems.push({ file, line, message: rule.message });
            }
        }
    }
}

for (const { file, line, message } of problems) {
    // GitHub Actions shows these as annotations on the pull request's changed files.
    console.log(process.env.GITHUB_ACTIONS ? `::error file=${file},line=${line}::${message}` : `${file}:${line}  ${message}`);
}

if (problems.length > 0) {
    console.log(`\n${problems.length} design rule problem${problems.length === 1 ? '' : 's'}. See "Themes" and "Design conventions" in AGENTS.md.`);
    process.exit(1);
}

console.log('Design rules: no problems found.');
