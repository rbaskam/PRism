import type { SubmitReviewEvent } from '@/types';

/** What the user just did: submitted a review with one of GitHub's verdicts, or started "Fix with Claude". */
export type MemeMoment = SubmitReviewEvent | 'FIX_WITH_CLAUDE';

export interface Meme {
    /** The GIF's id on Giphy (https://giphy.com/gifs/{id}). GIFs are loaded from Giphy, never stored in this repository. */
    giphyId: string;
    /** Describes the GIF for screen readers. */
    alt: string;
    /** The funny line shown under the GIF. */
    caption: string;
}

/** Reactions for each moment; one is picked at random each time. */
export const memes: Record<MemeMoment, Meme[]> = {
    APPROVE: [
        {
            giphyId: 'm2Q7FEc0bEr4I',
            alt: 'A kid at a 1990s computer turns to the camera and gives a thumbs up',
            caption: 'Brent Rambo approves. Ship it.',
        },
        {
            giphyId: 'f8pT7bphqES4M',
            alt: 'Michael Scott from The Office bites his lip and nods',
            caption: '*bites lip* Now that is a clean diff.',
        },
        {
            giphyId: 'GpryXedaw2njq',
            alt: 'Michael Scott raises his eyebrows at the camera with a knowing grin',
            caption: "Approved. We both know I didn't read the lockfile.",
        },
        {
            giphyId: 'O3eltvqy7SIQU',
            alt: 'Leonardo DiCaprio as Gatsby raises a glass of champagne',
            caption: 'A toast: to the author, and to a green CI.',
        },
    ],
    REQUEST_CHANGES: [
        {
            giphyId: '1T96TRBBGYThC',
            alt: 'Michael Scott shouts "No, God, please, no!"',
            caption: 'Changes requested. No, God, please, no.',
        },
        {
            giphyId: 'WPRJfpqxa9RF6',
            alt: 'Michael Scott facepalms',
            caption: "Changes requested. I've seen things in that diff.",
        },
        {
            giphyId: 'AoBgxayGMHlIs',
            alt: 'A talent show judge says "Yeah, it\'s a no from me, dawg"',
            caption: 'The judges have spoken. Back to the branch.',
        },
    ],
    COMMENT: [
        {
            giphyId: 'AVpE6BqnOvbzi',
            alt: 'Kermit the Frog sips a cup of tea',
            caption: "Left a comment. But that's none of my business.",
        },
        {
            giphyId: 'kd9BlRovbPOykLBMqX',
            alt: 'Leonardo DiCaprio leans forward in his chair and points at the screen',
            caption: 'Comment posted. I recognised that bug from my own code.',
        },
    ],
    FIX_WITH_CLAUDE: [
        {
            giphyId: '0NTG9ffrXT870Wt9qv',
            alt: 'Thanos puts on the gauntlet and says "Fine, I\'ll do it myself"',
            caption: 'Fine. Claude will do it itself.',
        },
        {
            giphyId: '3knKct3fGqxhK',
            alt: 'Hackerman, arms crossed, under a neon "HACKERMAN" title',
            caption: 'Claude is on it. Hackerman mode engaged.',
        },
    ],
};

/** Every moment, e.g. to preview a random reaction. */
export const memeMoments = Object.keys(memes) as MemeMoment[];

/** Giphy's animated WebP of the GIF: a fraction of the size of the GIF itself. */
export function memeImageUrl(meme: Meme): string {
    return `https://media.giphy.com/media/${meme.giphyId}/giphy.webp`;
}

/** A random reaction for the moment. `random` returns a number in [0, 1), like `Math.random`. */
export function pickMeme(moment: MemeMoment, random: () => number = Math.random): Meme {
    const options = memes[moment];

    return options[Math.min(options.length - 1, Math.floor(random() * options.length))];
}
