import type { SubmitReviewEvent } from '@/types';

/** What the user just did: submitted a review with one of GitHub's verdicts, or started "Fix with Claude". */
export type MemeMoment = SubmitReviewEvent | 'FIX_WITH_CLAUDE';

export interface Meme {
    /** The GIF's id on Giphy (https://giphy.com/gifs/{id}). GIFs are loaded from Giphy, never stored in this repository. */
    giphyId: string;
    /** Describes the GIF for screen readers. */
    alt: string;
    /** Lines that only make sense with this GIF. */
    captions: string[];
}

/** A GIF and the funny line shown under it. */
export interface MemeReaction {
    meme: Meme;
    caption: string;
}

/** GIFs for each moment; one is picked at random each time. */
export const memes: Record<MemeMoment, Meme[]> = {
    APPROVE: [
        {
            giphyId: 'm2Q7FEc0bEr4I',
            alt: 'A kid at a 1990s computer turns to the camera and gives a thumbs up',
            captions: ['Brent Rambo approves. Ship it.', 'Brent Rambo has reviewed your diff. Brent Rambo is pleased.'],
        },
        {
            giphyId: 'f8pT7bphqES4M',
            alt: 'Michael Scott from The Office bites his lip and nods',
            captions: ['*bites lip* Now that is a clean diff.', '*bites lip* Look at those passing tests.'],
        },
        {
            giphyId: 'GpryXedaw2njq',
            alt: 'Michael Scott raises his eyebrows at the camera with a knowing grin',
            captions: ["Approved. We both know I didn't read the lockfile.", 'Approved. Nobody needs to know I skimmed the tests.'],
        },
        {
            giphyId: 'O3eltvqy7SIQU',
            alt: 'Leonardo DiCaprio as Gatsby raises a glass of champagne',
            captions: ['A toast: to the author, and to a green CI.', 'Here’s to you, and to zero merge conflicts.'],
        },
        {
            giphyId: 'xKy2w6LehxxHa',
            alt: 'Robert Redford, bearded in a fur coat, turns and nods slowly',
            captions: ['A slow, respectful nod for these tests.', 'Approved. The nod of a senior engineer.'],
        },
        {
            giphyId: 'S3Ot3hZ5bcy8o',
            alt: 'Jack Nicholson grins and nods enthusiastically',
            captions: ['Yes. Yes. YES. Merge it.', 'Me, reading a PR that deletes more code than it adds.'],
        },
        {
            giphyId: 'gFwZfXIqD0eNW',
            alt: 'The Terminator gives a thumbs up as he sinks into molten metal',
            captions: ["Approved. I'll be back… for the next one.", 'Hasta la vista, bugs.'],
        },
        {
            giphyId: '6brH8dM3zeMyA',
            alt: 'A toddler on a beach clenches a fist of sand in triumph',
            captions: ['First review, zero comments. Success.', 'Merged without a single rebase. Success.'],
        },
    ],
    REQUEST_CHANGES: [
        {
            giphyId: '1T96TRBBGYThC',
            alt: 'Michael Scott shouts "No, God, please, no!"',
            captions: ['Changes requested. No, God, please, no.', 'Me, finding a force push on a shared branch.'],
        },
        {
            giphyId: 'WPRJfpqxa9RF6',
            alt: 'Michael Scott facepalms',
            captions: ["Changes requested. I've seen things in that diff.", 'Me, finding another empty catch block.'],
        },
        {
            giphyId: 'AoBgxayGMHlIs',
            alt: 'A talent show judge says "Yeah, it\'s a no from me, dawg"',
            captions: ['The judges have spoken. Back to the branch.'],
        },
        {
            giphyId: 'njYrp176NQsHS',
            alt: 'Gandalf raises his staff and bellows "You shall not pass"',
            captions: ['YOU SHALL NOT MERGE.', 'This branch shall not pass. Not until the tests do.'],
        },
        {
            giphyId: 'NTur7XlVDUdqM',
            alt: 'A cartoon dog sips coffee in a burning room and says "This is fine"',
            captions: ['The tests are red. The build is red. This is fine.', 'Production after this merges, probably.'],
        },
        {
            giphyId: 'w8g5zUCbH215kUjycc',
            alt: 'Gordon Ramsay holds up a steak and shouts "It\'s raw!"',
            captions: ['This code is RAW. Back in the oven it goes.', "It's raw! Did you even run the tests?"],
        },
        {
            giphyId: '3tEFVAbfzzcwo',
            alt: 'A raccoon works an abacus with its tiny hands',
            captions: ['Me, counting the nested ifs. I ran out of beads.', 'Calculating the cyclomatic complexity by hand…'],
        },
    ],
    COMMENT: [
        {
            giphyId: 'AVpE6BqnOvbzi',
            alt: 'Kermit the Frog sips a cup of tea',
            captions: ["Left a comment. But that's none of my business."],
        },
        {
            giphyId: 'kd9BlRovbPOykLBMqX',
            alt: 'Leonardo DiCaprio leans forward in his chair and points at the screen',
            captions: ['Comment posted. I recognised that bug from my own code.', 'When you spot your pet peeve in someone else’s diff.'],
        },
        {
            giphyId: '13jghlUIB6FHZm',
            alt: 'Jim Halpert from The Office looks straight at the camera',
            captions: ['Left a comment. *looks at camera*', 'Me, reading "small refactor" on a 2,000-line diff.'],
        },
        {
            giphyId: 'd3mlE7uhX8KFgEmY',
            alt: 'A man taps his temple knowingly',
            captions: ["Can't block the merge if you only leave comments.", "Can't get blamed for a bug if you commented on it first."],
        },
        {
            giphyId: '98maV70oAqIZtEYqB4',
            alt: 'Dwight Schrute from The Office says "False"',
            captions: ['"This is a tiny change." False.', '"No need for tests here." False.'],
        },
        {
            giphyId: '6nWhy3ulBL7GSCvKw6',
            alt: 'Pikachu looks shocked with its mouth wide open',
            captions: ['Me, when the PR description actually explains the change.', 'Me, finding tests in a hotfix.'],
        },
    ],
    FIX_WITH_CLAUDE: [
        {
            giphyId: '0NTG9ffrXT870Wt9qv',
            alt: 'Thanos puts on the gauntlet and says "Fine, I\'ll do it myself"',
            captions: ['Fine. Claude will do it itself.'],
        },
        {
            giphyId: '3knKct3fGqxhK',
            alt: 'Hackerman, arms crossed, under a neon "HACKERMAN" title',
            captions: ['Claude is on it. Hackerman mode engaged.'],
        },
        {
            giphyId: '7NoNw4pMNTvgc',
            alt: 'A cat types furiously on a laptop',
            captions: ['Claude, typing furiously in a worktree near you.', 'Senior engineer cat is on the case.'],
        },
        {
            giphyId: 'nhKW2pvXwI8mc',
            alt: 'A kid in Jurassic Park browses a 3D file system and says "It\'s a Unix system, I know this"',
            captions: ["It's a Unix system. Claude knows this."],
        },
        {
            giphyId: 'UxNTT095uL0go',
            alt: 'Bob the Builder puts on his hard hat',
            captions: ['Can we fix it? Claude says yes, we can.'],
        },
        {
            giphyId: '9PTaAhwri56V2',
            alt: 'Roy from The IT Crowd asks on the phone "Have you tried turning it off and on again?"',
            captions: ['Step one: have you tried turning it off and on again?', 'Claude, about to try turning it off and on again.'],
        },
    ],
};

/** Coding and pull request jokes that work with any GIF for the moment. */
export const phrases: Record<MemeMoment, string[]> = {
    APPROVE: [
        "LGTM: Let's Get This Merged.",
        'Approved. May your merge be conflict-free.',
        'Works on my machine. Approved.',
        'No notes. Literally none. Suspicious, but approved.',
        'Approved faster than CI could finish. Bold.',
        'This PR sparks joy.',
        'Ship it. Just maybe not on a Friday.',
        'Cleaner than my commit history.',
        'Approved. The linter and I are both at peace.',
        'Merge it before someone adds a "quick follow-up".',
        'Approved. No semicolons were harmed.',
        'Ten out of ten. Would review again.',
        'Approved. Your future self says thanks.',
        'Green checks all round.',
        'Approved. That is some beautiful variable naming.',
        'Ship it. Then go and touch grass.',
    ],
    REQUEST_CHANGES: [
        "It works on your machine. Let's get it working on everyone else's.",
        'Changes requested. The bugs have unionised.',
        'Changes requested. Even the linter looked away.',
        "Please remove the console.log('here'). And the other one.",
        "I'm not saying it's wrong. I'm saying the tests are crying.",
        '"Quick fix" was doing a lot of heavy lifting there.',
        'Close, but the edge cases have entered the chat.',
        'Changes requested. Have you tried fewer nested ifs?',
        'This diff needs a hug. And a refactor.',
        'Changes requested. git blame will remember this.',
        "It's not you, it's your null checks.",
        'Back to the IDE you go.',
        'TODO: everything in this file.',
        'Changes requested. The PR description said "trivial". It lied.',
        '99 little bugs in the code. Take one down, patch it around: 127 little bugs in the code.',
        'Changes requested. It compiles, which is a great start.',
    ],
    COMMENT: [
        'Just a small nit. Well, twelve small nits.',
        'Comment posted. Not a blocker. Probably.',
        'Nit: everything. Kidding. Mostly.',
        "Left a comment. Feel free to ignore it, but I'll know.",
        'Comment posted. Have you considered… a different approach?',
        'Just asking questions. Respectfully.',
        'Comment posted. This thread will now grow to forty replies.',
        'Not approving, not blocking. Just vibing in the comments.',
        'Commented. Bikeshed paint colour: to be decided.',
        '"Why is this here?" Asked with love.',
        'Optional suggestion, mandatory guilt.',
        'Comment posted. Somewhere, a developer sighs deeply.',
        'Comment posted. Let the bikeshedding begin.',
        'Have you considered tabs? Just kidding. Unless…',
        'Comment posted. Should this be a constant? Asking for a friend.',
    ],
    FIX_WITH_CLAUDE: [
        'Claude is fixing it. You may now pretend you were about to.',
        "Hold my coffee. Claude's got this.",
        'Claude is on it. Grab a snack.',
        "Outsourcing the bug fix to someone who doesn't need sleep.",
        'Claude writes the code, you review it. Balance is restored.',
        'Nothing is pushed until you approve it. Claude knows its place.',
        'Fixing bugs at the speed of autocomplete.',
        'Claude has entered the worktree.',
        'Rubber duck debugging, but the duck types back.',
        'Claude is fixing it. Please keep your hands inside the IDE.',
        'Bug fixes, made to order.',
        'Claude is on it. No Stack Overflow tabs were opened.',
        'Somewhere, a worktree is getting a glow-up.',
        "Claude is fixing it. Don't tell the bugs.",
    ],
};

/** Every moment, e.g. to preview a random reaction. */
export const memeMoments = Object.keys(memes) as MemeMoment[];

/** Giphy's 200px-wide animated WebP of the GIF: plenty for the small reaction, and quick to load. */
export function memeImageUrl(meme: Meme): string {
    return `https://media.giphy.com/media/${meme.giphyId}/200w.webp`;
}

function pickFrom<T>(options: T[], random: () => number): T {
    return options[Math.min(options.length - 1, Math.floor(random() * options.length))];
}

/**
 * A random GIF for the moment and a funny line: half the time one written for that GIF, otherwise one of the
 * moment's phrases. `random` returns a number in [0, 1), like `Math.random`.
 */
export function pickReaction(moment: MemeMoment, random: () => number = Math.random): MemeReaction {
    const meme = pickFrom(memes[moment], random);
    const caption = random() < 0.5 ? pickFrom(meme.captions, random) : pickFrom(phrases[moment], random);

    return { meme, caption };
}
