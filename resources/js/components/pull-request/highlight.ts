import type { HighlighterCore, LanguageRegistration } from 'shiki/core';
import type { DiffLine, WordSegment } from './diff';

/**
 * Syntax highlighting for diffs with Shiki. Shiki returns tokens (text plus colours) rather than HTML, so the diff
 * renders them as plain text in Vue: nothing from a pull request is ever inserted as HTML. It runs the grammars with
 * Shiki's JavaScript regex engine, and each language is a separate chunk loaded the first time a file needs it.
 */

/** A piece of highlighted code. `style` carries the light and dark theme colours as CSS variables. */
export interface HighlightToken {
    text: string;
    style: Record<string, string> | null;
}

/** A piece of a diff line as it's drawn: its colours, and whether it's a word that changed. */
export interface RenderPiece extends HighlightToken {
    changed: boolean;
}

type LanguageLoader = () => Promise<{ default: LanguageRegistration[] }>;

const loaders: Record<string, LanguageLoader> = {
    blade: () => import('shiki/langs/blade.mjs'),
    c: () => import('shiki/langs/c.mjs'),
    cpp: () => import('shiki/langs/cpp.mjs'),
    csharp: () => import('shiki/langs/csharp.mjs'),
    css: () => import('shiki/langs/css.mjs'),
    dart: () => import('shiki/langs/dart.mjs'),
    dockerfile: () => import('shiki/langs/dockerfile.mjs'),
    dotenv: () => import('shiki/langs/dotenv.mjs'),
    elixir: () => import('shiki/langs/elixir.mjs'),
    go: () => import('shiki/langs/go.mjs'),
    graphql: () => import('shiki/langs/graphql.mjs'),
    hcl: () => import('shiki/langs/hcl.mjs'),
    html: () => import('shiki/langs/html.mjs'),
    ini: () => import('shiki/langs/ini.mjs'),
    java: () => import('shiki/langs/java.mjs'),
    javascript: () => import('shiki/langs/javascript.mjs'),
    json: () => import('shiki/langs/json.mjs'),
    jsonc: () => import('shiki/langs/jsonc.mjs'),
    jsx: () => import('shiki/langs/jsx.mjs'),
    kotlin: () => import('shiki/langs/kotlin.mjs'),
    less: () => import('shiki/langs/less.mjs'),
    lua: () => import('shiki/langs/lua.mjs'),
    makefile: () => import('shiki/langs/makefile.mjs'),
    markdown: () => import('shiki/langs/markdown.mjs'),
    perl: () => import('shiki/langs/perl.mjs'),
    php: () => import('shiki/langs/php.mjs'),
    powershell: () => import('shiki/langs/powershell.mjs'),
    proto: () => import('shiki/langs/proto.mjs'),
    python: () => import('shiki/langs/python.mjs'),
    r: () => import('shiki/langs/r.mjs'),
    ruby: () => import('shiki/langs/ruby.mjs'),
    rust: () => import('shiki/langs/rust.mjs'),
    scala: () => import('shiki/langs/scala.mjs'),
    scss: () => import('shiki/langs/scss.mjs'),
    shellscript: () => import('shiki/langs/shellscript.mjs'),
    sql: () => import('shiki/langs/sql.mjs'),
    svelte: () => import('shiki/langs/svelte.mjs'),
    swift: () => import('shiki/langs/swift.mjs'),
    toml: () => import('shiki/langs/toml.mjs'),
    tsx: () => import('shiki/langs/tsx.mjs'),
    twig: () => import('shiki/langs/twig.mjs'),
    typescript: () => import('shiki/langs/typescript.mjs'),
    vue: () => import('shiki/langs/vue.mjs'),
    xml: () => import('shiki/langs/xml.mjs'),
    yaml: () => import('shiki/langs/yaml.mjs'),
};

const languagesByExtension: Record<string, string> = {
    bash: 'shellscript',
    c: 'c',
    cc: 'cpp',
    cjs: 'javascript',
    cpp: 'cpp',
    cs: 'csharp',
    css: 'css',
    dart: 'dart',
    env: 'dotenv',
    ex: 'elixir',
    exs: 'elixir',
    go: 'go',
    gql: 'graphql',
    graphql: 'graphql',
    h: 'c',
    hpp: 'cpp',
    htm: 'html',
    html: 'html',
    ini: 'ini',
    java: 'java',
    js: 'javascript',
    json: 'json',
    jsonc: 'jsonc',
    jsx: 'jsx',
    kt: 'kotlin',
    kts: 'kotlin',
    less: 'less',
    lua: 'lua',
    markdown: 'markdown',
    md: 'markdown',
    mjs: 'javascript',
    mts: 'typescript',
    neon: 'yaml',
    php: 'php',
    pl: 'perl',
    proto: 'proto',
    ps1: 'powershell',
    py: 'python',
    r: 'r',
    rb: 'ruby',
    rs: 'rust',
    scala: 'scala',
    scss: 'scss',
    sh: 'shellscript',
    sql: 'sql',
    svelte: 'svelte',
    svg: 'xml',
    swift: 'swift',
    tf: 'hcl',
    toml: 'toml',
    ts: 'typescript',
    tsx: 'tsx',
    twig: 'twig',
    vue: 'vue',
    xml: 'xml',
    yaml: 'yaml',
    yml: 'yaml',
    zsh: 'shellscript',
};

const languagesByFileName: Record<string, string> = {
    dockerfile: 'dockerfile',
    gemfile: 'ruby',
    makefile: 'makefile',
    rakefile: 'ruby',
};

/** Files bigger than this are left plain: highlighting them would hold up the window for too long. */
const MAX_CHARACTERS = 200_000;

/** Lines longer than this (usually minified code) make the regex engine slow, so their file is left plain. */
const MAX_LINE_LENGTH = 1_000;

const THEMES = { light: 'github-light', dark: 'github-dark' } as const;

/** The themes' own text colours. Tokens in them are left uncoloured, so plain code uses the app's text colour. */
const DEFAULT_COLOURS = { '--shiki-light': '#24292E', '--shiki-dark': '#E1E4E8' };

let highlighter: Promise<HighlighterCore> | null = null;
const loading = new Map<string, Promise<void>>();

/** The Shiki language for a file, or null when there's no grammar for it. */
export function languageFor(path: string): string | null {
    const fileName = path.slice(path.lastIndexOf('/') + 1).toLowerCase();

    if (fileName.endsWith('.blade.php')) {
        return 'blade';
    }

    if (languagesByFileName[fileName]) {
        return languagesByFileName[fileName];
    }

    if (fileName.startsWith('.env')) {
        return 'dotenv';
    }

    const dot = fileName.lastIndexOf('.');

    return dot === -1 ? null : (languagesByExtension[fileName.slice(dot + 1)] ?? null);
}

function getHighlighter(): Promise<HighlighterCore> {
    highlighter ??= Promise.all([import('shiki/core'), import('shiki/engine/javascript')]).then(
        ([{ createHighlighterCore }, { createJavaScriptRegexEngine }]) =>
            createHighlighterCore({
                themes: [import('shiki/themes/github-light.mjs'), import('shiki/themes/github-dark.mjs')],
                langs: [],
                engine: createJavaScriptRegexEngine(),
            }),
    );

    return highlighter;
}

async function loadLanguage(core: HighlighterCore, language: string): Promise<void> {
    if (!loading.has(language)) {
        loading.set(
            language,
            loaders[language]().then((module) => core.loadLanguage(module.default)),
        );
    }

    await loading.get(language);
}

function tokenStyle(style: Record<string, string> | undefined): Record<string, string> | null {
    if (!style || (style['--shiki-light'] === DEFAULT_COLOURS['--shiki-light'] && style['--shiki-dark'] === DEFAULT_COLOURS['--shiki-dark'])) {
        return null;
    }

    return { '--shiki-light': style['--shiki-light'], '--shiki-dark': style['--shiki-dark'] };
}

/** Highlights one version of the code, one entry per line. Null when the code is too big or the language unknown. */
async function highlightCode(code: string[], language: string): Promise<HighlightToken[][] | null> {
    const core = await getHighlighter();
    await loadLanguage(core, language);

    const { tokens } = core.codeToTokens(code.join('\n'), { lang: language, themes: THEMES, defaultColor: false });

    return tokens.map((line) => line.map((token) => ({ text: token.content, style: tokenStyle(token.htmlStyle as Record<string, string>) })));
}

/**
 * Highlights a diff, keyed by row index. The old version (context and deleted lines) and the new version (context
 * and added lines) are highlighted separately, so strings and comments that span lines are coloured correctly on
 * each side. Returns null when the file isn't highlighted (unknown language, too big or minified).
 */
export async function highlightDiff(path: string, lines: DiffLine[]): Promise<Map<number, HighlightToken[]> | null> {
    const language = languageFor(path);
    const codeLines = lines.filter((line) => line.type === 'context' || line.type === 'add' || line.type === 'del');
    const characters = codeLines.reduce((total, line) => total + line.content.length + 1, 0);

    if (!language || !loaders[language] || characters > MAX_CHARACTERS || codeLines.some((line) => line.content.length > MAX_LINE_LENGTH)) {
        return null;
    }

    const oldLines = codeLines.filter((line) => line.type !== 'add');
    const newLines = codeLines.filter((line) => line.type !== 'del');

    try {
        const [oldTokens, newTokens] = await Promise.all([
            highlightCode(
                oldLines.map((line) => line.content),
                language,
            ),
            highlightCode(
                newLines.map((line) => line.content),
                language,
            ),
        ]);

        if (!oldTokens || !newTokens) {
            return null;
        }

        const result = new Map<number, HighlightToken[]>();

        oldLines.forEach((line, position) => line.type === 'del' && result.set(line.index, oldTokens[position] ?? []));
        newLines.forEach((line, position) => result.set(line.index, newTokens[position] ?? []));

        return result;
    } catch {
        // A grammar the engine can't run leaves the diff plain rather than broken.
        return null;
    }
}

/**
 * Splits a line into the pieces it's drawn with, combining its syntax colours with the words that changed.
 * Either may be missing: a line without colours is one plain piece per changed or unchanged run.
 */
export function renderPieces(content: string, tokens: HighlightToken[] | undefined, words: WordSegment[] | undefined): RenderPiece[] {
    const colours: HighlightToken[] = tokens && tokens.length > 0 ? tokens : [{ text: content, style: null }];
    const changes: WordSegment[] = words ?? [{ text: content, changed: false }];
    const pieces: RenderPiece[] = [];
    let colourIndex = 0;
    let colourOffset = 0;
    let changeIndex = 0;
    let changeOffset = 0;
    let consumed = 0;

    while (colourIndex < colours.length && changeIndex < changes.length) {
        const colour = colours[colourIndex];
        const change = changes[changeIndex];
        const length = Math.min(colour.text.length - colourOffset, change.text.length - changeOffset);

        if (length > 0) {
            const text = colour.text.slice(colourOffset, colourOffset + length);
            const last = pieces[pieces.length - 1];

            if (last && last.style === colour.style && last.changed === change.changed) {
                last.text += text;
            } else {
                pieces.push({ text, style: colour.style, changed: change.changed });
            }
        }

        colourOffset += length;
        changeOffset += length;
        consumed += Math.max(length, 0);

        if (colourOffset >= colour.text.length) {
            colourIndex++;
            colourOffset = 0;
        }

        if (changeOffset >= change.text.length) {
            changeIndex++;
            changeOffset = 0;
        }
    }

    // Never lose text, even if the colours and the changes disagree about the line's length.
    if (consumed < content.length) {
        pieces.push({ text: content.slice(consumed), style: null, changed: false });
    }

    return pieces;
}
