import { onMounted, ref } from 'vue';

type Appearance = 'light' | 'dark' | 'system';

export type Accent = 'neutral' | 'blue' | 'green' | 'violet' | 'rose' | 'orange';

export function updateTheme(value: Appearance) {
    if (value === 'system') {
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.classList.toggle('dark', systemTheme === 'dark');
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

/** Tints buttons, switches, focus rings and the sidebar with the accent's colours from app.css. */
export function updateAccent(value: Accent) {
    if (value === 'neutral') {
        delete document.documentElement.dataset.accent;
    } else {
        document.documentElement.dataset.accent = value;
    }
}

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance | null;
    updateTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    // Initialize theme from saved preference or default to system...
    const savedAppearance = localStorage.getItem('appearance') as Appearance | null;
    updateTheme(savedAppearance || 'system');
    updateAccent((localStorage.getItem('accent') as Accent | null) || 'neutral');

    // Set up system theme change listener...
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const appearance = ref<Appearance>('system');
    const accent = ref<Accent>('neutral');

    onMounted(() => {
        initializeTheme();

        const savedAppearance = localStorage.getItem('appearance') as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }

        const savedAccent = localStorage.getItem('accent') as Accent | null;

        if (savedAccent) {
            accent.value = savedAccent;
        }
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;
        localStorage.setItem('appearance', value);
        updateTheme(value);
    }

    function setAccent(value: Accent) {
        accent.value = value;
        localStorage.setItem('accent', value);
        updateAccent(value);
    }

    return {
        appearance,
        updateAppearance,
        accent,
        updateAccent: setAccent,
    };
}
