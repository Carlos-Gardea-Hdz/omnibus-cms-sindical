import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import en from '@/../locales/en.json';
import es from '@/../locales/es.json';

export type Locale = 'es' | 'en';

type Messages = Record<string, string>;

const DICTIONARIES: Record<Locale, Messages> = {
    es: es as Messages,
    en: en as Messages,
};

const STORAGE_KEY = 'cms.locale';

interface LocaleContextValue {
    locale: Locale;
    setLocale: (locale: Locale) => void;
    /** Translate a dot.key; falls back to ES, then the key itself, if missing. */
    t: (key: string) => string;
}

const LocaleContext = createContext<LocaleContextValue | null>(null);

function readStoredLocale(): Locale {
    if (typeof window === 'undefined') {
        return 'es';
    }
    const stored = window.localStorage.getItem(STORAGE_KEY);
    return stored === 'en' ? 'en' : 'es';
}

export function LocaleProvider({ children }: { children: ReactNode }) {
    const [locale, setLocaleState] = useState<Locale>(readStoredLocale);

    const setLocale = useCallback((next: Locale) => {
        setLocaleState(next);
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(STORAGE_KEY, next);
            document.documentElement.lang = next;
        }
    }, []);

    const t = useCallback(
        (key: string): string => DICTIONARIES[locale][key] ?? DICTIONARIES.es[key] ?? key,
        [locale],
    );

    const value = useMemo<LocaleContextValue>(
        () => ({ locale, setLocale, t }),
        [locale, setLocale, t],
    );

    return <LocaleContext value={value}>{children}</LocaleContext>;
}

export function useLocale(): LocaleContextValue {
    const context = useContext(LocaleContext);
    if (!context) {
        throw new Error('useLocale must be used within a LocaleProvider');
    }
    return context;
}
