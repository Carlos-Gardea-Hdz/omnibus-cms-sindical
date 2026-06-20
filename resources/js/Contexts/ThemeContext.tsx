import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';

export type ThemePreference = 'light' | 'dark' | 'system';

interface ThemeContextValue {
    /** The user's explicit preference (or 'system'). */
    preference: ThemePreference;
    /** The theme actually applied to <html> right now. */
    resolved: 'light' | 'dark';
    setPreference: (preference: ThemePreference) => void;
}

const STORAGE_KEY = 'cms.theme';

const ThemeContext = createContext<ThemeContextValue | null>(null);

function systemPrefersDark(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function readStoredPreference(): ThemePreference {
    if (typeof window === 'undefined') {
        return 'system';
    }
    const stored = window.localStorage.getItem(STORAGE_KEY);
    return stored === 'light' || stored === 'dark' ? stored : 'system';
}

function applyTheme(resolved: 'light' | 'dark'): void {
    if (typeof document === 'undefined') {
        return;
    }
    // Dark mode is a class on <html> (SPEC §4.5, class strategy).
    document.documentElement.classList.toggle('dark', resolved === 'dark');
}

export function ThemeProvider({ children }: { children: ReactNode }) {
    const [preference, setPreferenceState] = useState<ThemePreference>(readStoredPreference);
    const [systemDark, setSystemDark] = useState<boolean>(systemPrefersDark);

    // Track live OS changes so `system` stays accurate.
    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = (event: MediaQueryListEvent) => setSystemDark(event.matches);
        media.addEventListener('change', onChange);
        return () => media.removeEventListener('change', onChange);
    }, []);

    const resolved: 'light' | 'dark' =
        preference === 'system' ? (systemDark ? 'dark' : 'light') : preference;

    useEffect(() => {
        applyTheme(resolved);
    }, [resolved]);

    const setPreference = useCallback((next: ThemePreference) => {
        setPreferenceState(next);
        if (next === 'system') {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, next);
        }
    }, []);

    const value = useMemo<ThemeContextValue>(
        () => ({ preference, resolved, setPreference }),
        [preference, resolved, setPreference],
    );

    return <ThemeContext value={value}>{children}</ThemeContext>;
}

export function useTheme(): ThemeContextValue {
    const context = useContext(ThemeContext);
    if (!context) {
        throw new Error('useTheme must be used within a ThemeProvider');
    }
    return context;
}
