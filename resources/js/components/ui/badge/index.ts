import { cva, type VariantProps } from 'class-variance-authority';

export { default as Badge } from './Badge.vue';

export const badgeVariants = cva(
    'inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-medium transition-colors [&_svg]:size-3 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                outline: 'text-foreground',
                destructive: 'border-transparent bg-destructive text-destructive-foreground',
                success: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
                danger: 'border-transparent bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
                warning: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
                info: 'border-transparent bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
                /** A merged pull request: purple, like on GitHub. */
                merged: 'border-transparent bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
                muted: 'border-transparent bg-muted text-muted-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export type BadgeVariants = VariantProps<typeof badgeVariants>;
