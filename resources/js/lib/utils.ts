import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/** A rounded filter chip that takes the accent colour while it's on. Used by every filter row, so they look the same. */
export function filterChipClass(isActive: boolean): string {
    return cn(
        'inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40 [&_svg]:size-3.5',
        isActive ? 'border-primary bg-primary text-primary-foreground hover:bg-primary/90' : 'bg-background text-foreground hover:bg-accent',
    );
}
