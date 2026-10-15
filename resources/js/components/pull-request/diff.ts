export type DiffSide = 'RIGHT' | 'LEFT';

export type DiffLineType = 'hunk' | 'context' | 'add' | 'del' | 'meta';

export interface DiffLine {
    /** Position in the file's patch, used as a stable key and for selections. */
    index: number;
    type: DiffLineType;
    /** The line as it appears in the patch, including its +, - or space prefix. */
    raw: string;
    /** The code without its prefix (for hunk headers, the whole header). */
    content: string;
    oldLine: number | null;
    newLine: number | null;
}

export interface DiffSelection {
    side: DiffSide;
    startLine: number;
    endLine: number;
    /** The selected rows' raw text (with their +/-/space prefix), joined by newlines. */
    selectedCode: string;
    /** First and last selected row indexes. */
    startIndex: number;
    endIndex: number;
}

const HUNK_HEADER = /^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/;

/**
 * Parses a unified diff patch as GitHub returns it for one file (hunks only, no file headers).
 */
export function parsePatch(patch: string): DiffLine[] {
    const rows = patch.replace(/\r\n/g, '\n').split('\n');
    const lines: DiffLine[] = [];
    let oldLine = 0;
    let newLine = 0;

    if (rows.length > 0 && rows[rows.length - 1] === '') {
        rows.pop();
    }

    for (const raw of rows) {
        const index = lines.length;
        const header = HUNK_HEADER.exec(raw);

        if (header) {
            oldLine = Number(header[1]);
            newLine = Number(header[2]);
            lines.push({ index, type: 'hunk', raw, content: raw, oldLine: null, newLine: null });
        } else if (raw.startsWith('+')) {
            lines.push({ index, type: 'add', raw, content: raw.slice(1), oldLine: null, newLine: newLine++ });
        } else if (raw.startsWith('-')) {
            lines.push({ index, type: 'del', raw, content: raw.slice(1), oldLine: oldLine++, newLine: null });
        } else if (raw.startsWith('\\')) {
            lines.push({ index, type: 'meta', raw, content: raw.slice(1).trim(), oldLine: null, newLine: null });
        } else {
            lines.push({ index, type: 'context', raw, content: raw.slice(1), oldLine: oldLine++, newLine: newLine++ });
        }
    }

    return lines;
}

/** Number of rows in a patch, without parsing it. */
export function countPatchLines(patch: string | null): number {
    if (!patch) {
        return 0;
    }

    let count = 1;

    for (let position = patch.indexOf('\n'); position !== -1; position = patch.indexOf('\n', position + 1)) {
        count++;
    }

    return patch.endsWith('\n') ? count - 1 : count;
}

export function isCodeLine(line: DiffLine): boolean {
    return line.type === 'context' || line.type === 'add' || line.type === 'del';
}

/** Deleted lines belong to the old version (LEFT); context and added lines to the new one (RIGHT). */
export function lineSide(line: DiffLine): DiffSide {
    return line.type === 'del' ? 'LEFT' : 'RIGHT';
}

export function lineNumber(line: DiffLine): number | null {
    return line.type === 'del' ? line.oldLine : line.newLine;
}

/** "RIGHT:42", the key questions are anchored by. Null for hunk headers and "no newline" markers. */
export function lineKey(line: DiffLine): string | null {
    const number = lineNumber(line);

    return isCodeLine(line) && number !== null ? `${lineSide(line)}:${number}` : null;
}

/**
 * Turns a range of rows into what a question is asked about. Uses the new version's line numbers when any
 * selected row is on it, and the old version's only when every selected row is a deletion.
 */
export function selectionFromRange(lines: DiffLine[], fromIndex: number, toIndex: number): DiffSelection | null {
    const start = Math.max(0, Math.min(fromIndex, toIndex));
    const end = Math.min(lines.length - 1, Math.max(fromIndex, toIndex));
    const rows = lines.slice(start, end + 1).filter(isCodeLine);

    if (rows.length === 0) {
        return null;
    }

    const rightRows = rows.filter((row) => row.type !== 'del');
    const side: DiffSide = rightRows.length > 0 ? 'RIGHT' : 'LEFT';
    const numbers = (side === 'RIGHT' ? rightRows.map((row) => row.newLine) : rows.map((row) => row.oldLine)).filter(
        (number): number is number => number !== null,
    );

    return {
        side,
        startLine: Math.min(...numbers),
        endLine: Math.max(...numbers),
        selectedCode: rows.map((row) => row.raw).join('\n'),
        startIndex: rows[0].index,
        endIndex: rows[rows.length - 1].index,
    };
}

/** "line 12" or "lines 12–18" */
export function formatLineRange(startLine: number, endLine: number): string {
    return startLine === endLine ? `line ${startLine}` : `lines ${startLine}–${endLine}`;
}

/** One file of a multi-file `git diff`. */
export interface UnifiedDiffFile {
    path: string;
    previousPath: string | null;
    status: 'added' | 'removed' | 'renamed' | 'modified';
    /** The hunks, in the same format GitHub returns per file. Null for binary files. */
    patch: string | null;
    additions: number;
    deletions: number;
}

const TRUNCATION_MARKER = '… diff truncated …';

function stripPrefix(path: string): string {
    return path.replace(/^"?[ab]\//, '').replace(/"$/, '');
}

/**
 * Splits the output of `git diff base..head` into files. A diff cut short when it was stored ends with a marker,
 * which is removed and reported as `truncated`.
 */
export function splitUnifiedDiff(diff: string): { files: UnifiedDiffFile[]; truncated: boolean } {
    let text = diff.replace(/\r\n/g, '\n');
    const truncated = text.trimEnd().endsWith(TRUNCATION_MARKER);

    if (truncated) {
        // Drop the marker and the line it cut through.
        text = text
            .slice(0, text.lastIndexOf(TRUNCATION_MARKER))
            .replace(/\n$/, '')
            .replace(/\n[^\n]*$/, '');
    }

    const files: UnifiedDiffFile[] = [];
    const chunks = text.split(/^(?=diff --git )/m).filter((chunk) => chunk.startsWith('diff --git '));

    for (const chunk of chunks) {
        const lines = chunk.split('\n');
        const header = /^diff --git ("?a\/.+?"?) ("?b\/.+?"?)$/.exec(lines[0]);
        let oldPath = header ? stripPrefix(header[1]) : null;
        let newPath = header ? stripPrefix(header[2]) : null;
        let status: UnifiedDiffFile['status'] = 'modified';
        let isBinary = false;
        let hunkStart = -1;

        for (let index = 1; index < lines.length; index++) {
            const line = lines[index];

            if (line.startsWith('@@')) {
                hunkStart = index;
                break;
            }

            if (line.startsWith('new file mode')) {
                status = 'added';
            } else if (line.startsWith('deleted file mode')) {
                status = 'removed';
            } else if (line.startsWith('rename from ')) {
                status = 'renamed';
                oldPath = line.slice('rename from '.length);
            } else if (line.startsWith('rename to ')) {
                newPath = line.slice('rename to '.length);
            } else if (line.startsWith('--- ') && line !== '--- /dev/null') {
                oldPath = stripPrefix(line.slice(4));
            } else if (line.startsWith('+++ ') && line !== '+++ /dev/null') {
                newPath = stripPrefix(line.slice(4));
            } else if (line.startsWith('Binary files ') || line === 'GIT binary patch') {
                isBinary = true;
            }
        }

        const hunks = hunkStart === -1 ? [] : lines.slice(hunkStart);
        const patch = isBinary || hunks.length === 0 ? null : hunks.join('\n');
        let additions = 0;
        let deletions = 0;

        for (const line of hunks) {
            if (line.startsWith('+')) {
                additions++;
            } else if (line.startsWith('-')) {
                deletions++;
            }
        }

        const path = (status === 'removed' ? oldPath : newPath) ?? oldPath ?? 'unknown';

        files.push({
            path,
            previousPath: status === 'renamed' ? oldPath : null,
            status,
            patch,
            additions,
            deletions,
        });
    }

    return { files, truncated };
}
