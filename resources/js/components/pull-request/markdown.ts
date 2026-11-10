/**
 * A deliberately small markdown renderer for text Claude writes. Everything is HTML-escaped first, then a
 * handful of constructs are turned into markup: fenced code, inline code, bold, italics, http(s) links,
 * headings, bullet and numbered lists, block quotes, rules and paragraphs.
 *
 * Claude reads pull requests anyone can write, so its text may be crafted to be slow to render. Every pattern
 * here runs in linear time on a line, lines longer than MAX_LINE_LENGTH are shown as plain text, and block
 * quotes nest at most MAX_QUOTE_DEPTH deep, so no text can freeze the window or overflow the stack.
 */

const MAX_LINE_LENGTH = 2000;
const MAX_QUOTE_DEPTH = 8;

export function escapeHtml(text: string): string {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatEmphasis(escaped: string): string {
    return escaped
        .replace(/\[([^[\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2">$1</a>')
        .replace(/\*\*(?=\S)(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/__(?=\S)(.+?)__/g, '<strong>$1</strong>')
        .replace(/(^|[^\w*])\*(?=\S)([^*\n]+?)\*(?!\w)/g, '$1<em>$2</em>')
        .replace(/~~(?=\S)(.+?)~~/g, '<del>$1</del>');
}

/** Inline code spans are kept verbatim; everything else gets emphasis and links. */
function renderInline(text: string): string {
    return text
        .split(/(`[^`\n]+`)/)
        .map((part, index) => (index % 2 === 1 ? `<code>${escapeHtml(part.slice(1, -1))}</code>` : formatEmphasis(escapeHtml(part))))
        .join('');
}

/** Tested against the trimmed line. */
const FENCE = /^(`{3,}|~{3,})[ \t]*([\w+#.-]*)$/;
const HEADING = /^(#{1,6})[ \t]+(.*)$/;
const BULLET = /^\s*[-*+]\s+(.*)$/;
const NUMBERED = /^\s*(\d+)[.)]\s+(.*)$/;
const QUOTE = /^\s*>\s?(.*)$/;
const RULE = /^\s*([-*_])(\s*\1){2,}\s*$/;

function isTooLong(line: string): boolean {
    return line.length > MAX_LINE_LENGTH;
}

function startsBlock(line: string): boolean {
    return (
        isTooLong(line) ||
        FENCE.test(line.trim()) ||
        HEADING.test(line) ||
        BULLET.test(line) ||
        NUMBERED.test(line) ||
        QUOTE.test(line) ||
        RULE.test(line)
    );
}

/**
 * A heading's text without the optional closing #s ("## Title ##"), trimmed by hand rather than with a regex.
 */
function headingText(text: string): string {
    let end = text.length;

    while (end > 0 && (text[end - 1] === ' ' || text[end - 1] === '\t')) {
        end--;
    }

    let hashes = end;

    while (hashes > 0 && text[hashes - 1] === '#') {
        hashes--;
    }

    if (hashes < end && (hashes === 0 || text[hashes - 1] === ' ' || text[hashes - 1] === '\t')) {
        end = hashes;
    }

    return text.slice(0, end).trim();
}

export function renderMarkdown(markdown: string): string {
    return renderBlocks(markdown, 0);
}

function renderBlocks(markdown: string, quoteDepth: number): string {
    const lines = markdown.replace(/\r\n?/g, '\n').split('\n');
    const html: string[] = [];
    let index = 0;

    while (index < lines.length) {
        const line = lines[index];

        if (line.trim() === '') {
            index++;
            continue;
        }

        if (isTooLong(line)) {
            html.push(`<p>${escapeHtml(line.trim())}</p>`);
            index++;
            continue;
        }

        const fence = FENCE.exec(line.trim());

        if (fence) {
            const code: string[] = [];
            index++;

            while (index < lines.length && !lines[index].trim().startsWith(fence[1])) {
                code.push(lines[index]);
                index++;
            }

            index++;
            html.push(`<pre><code>${escapeHtml(code.join('\n'))}</code></pre>`);
            continue;
        }

        const heading = HEADING.exec(line);

        if (heading) {
            const level = heading[1].length <= 2 ? 3 : 4;
            html.push(`<h${level}>${renderInline(headingText(heading[2]))}</h${level}>`);
            index++;
            continue;
        }

        if (RULE.test(line)) {
            html.push('<hr>');
            index++;
            continue;
        }

        if (QUOTE.test(line)) {
            const quoted: string[] = [];

            while (index < lines.length && !isTooLong(lines[index]) && QUOTE.test(lines[index])) {
                quoted.push((QUOTE.exec(lines[index]) as RegExpExecArray)[1]);
                index++;
            }

            const inner =
                quoteDepth < MAX_QUOTE_DEPTH
                    ? renderBlocks(quoted.join('\n'), quoteDepth + 1)
                    : quoted.map((text) => `<p>${escapeHtml(text)}</p>`).join('');
            html.push(`<blockquote>${inner}</blockquote>`);
            continue;
        }

        const listPattern = BULLET.test(line) ? BULLET : NUMBERED.test(line) ? NUMBERED : null;

        if (listPattern) {
            const isNumbered = listPattern === NUMBERED;
            const items: string[] = [];
            const start = isNumbered ? Number((NUMBERED.exec(line) as RegExpExecArray)[1]) : 1;

            while (index < lines.length && !isTooLong(lines[index])) {
                const match = listPattern.exec(lines[index]);

                if (match) {
                    items.push(isNumbered ? match[2] : match[1]);
                } else if (lines[index].trim() !== '' && /^\s{2,}/.test(lines[index]) && items.length > 0) {
                    items[items.length - 1] += `\n${lines[index].trim()}`;
                } else {
                    break;
                }

                index++;
            }

            const tag = isNumbered ? 'ol' : 'ul';
            const startAttribute = isNumbered && start !== 1 ? ` start="${start}"` : '';
            html.push(`<${tag}${startAttribute}>${items.map((item) => `<li>${renderInline(item).replace(/\n/g, '<br>')}</li>`).join('')}</${tag}>`);
            continue;
        }

        const paragraph: string[] = [];

        while (index < lines.length && lines[index].trim() !== '' && (paragraph.length === 0 || !startsBlock(lines[index]))) {
            paragraph.push(lines[index]);
            index++;
        }

        html.push(`<p>${paragraph.map((text) => renderInline(text.trim())).join('<br>')}</p>`);
    }

    return html.join('\n');
}
