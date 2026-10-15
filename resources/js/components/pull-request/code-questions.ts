import type { CodeQuestion } from '@/types';

/** A question about highlighted lines and its follow-ups, oldest first. */
export interface QuestionThread {
    root: CodeQuestion;
    /** The root question followed by its follow-ups, in the order they were asked. */
    entries: CodeQuestion[];
    isActive: boolean;
}

/** Groups questions (oldest first) into threads by their first question. */
export function groupQuestionThreads(questions: CodeQuestion[]): QuestionThread[] {
    const threads = new Map<number, QuestionThread>();
    const ordered = [...questions].sort((a, b) => a.id - b.id);

    for (const question of ordered) {
        const parent = question.parent_id !== null ? threads.get(question.parent_id) : undefined;

        if (parent) {
            parent.entries.push(question);
            parent.isActive ||= question.is_active;
        } else {
            threads.set(question.id, { root: question, entries: [question], isActive: question.is_active });
        }
    }

    return Array.from(threads.values());
}

/** Thread lists keyed by file path. */
export function threadsByPath(threads: QuestionThread[]): Map<string, QuestionThread[]> {
    const byPath = new Map<string, QuestionThread[]>();

    for (const thread of threads) {
        byPath.set(thread.root.path, [...(byPath.get(thread.root.path) ?? []), thread]);
    }

    return byPath;
}

/** The highlighted lines a question is about. */
export interface QuestionTarget {
    path: string;
    side: 'RIGHT' | 'LEFT';
    startLine: number;
    endLine: number;
    /** Raw diff rows, with their +/-/space prefix. */
    selectedCode: string;
}

/** One-click starting points for a question. */
export const questionStarters: string[] = [
    'Is this correct?',
    'Is there a better or simpler way to write this?',
    'Any bugs or edge cases here?',
    'Any security concerns?',
    'Explain what this does',
    'What tests should cover this?',
];

/** Where to show a question in the diff, requested from outside the Files changed tab. */
export interface RevealTarget {
    path: string;
    side: 'RIGHT' | 'LEFT';
    line: number;
    /** Changes on every request, so asking for the same line twice still scrolls. */
    nonce: number;
}
