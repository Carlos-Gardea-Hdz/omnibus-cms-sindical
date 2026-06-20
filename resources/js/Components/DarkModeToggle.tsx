import { useTheme, type ThemePreference } from '@/Contexts/ThemeContext';
import { useLocale } from '@/Contexts/LocaleContext';

const ORDER: ThemePreference[] = ['light', 'dark', 'system'];

const ICON: Record<ThemePreference, string> = {
    light: '☀️',
    dark: '🌙',
    system: '💻',
};

/**
 * Cycles light → dark → system. Native <button>, keyboard-operable,
 * labelled for screen readers, ≥ 36px target (WCAG 2.2 AA).
 */
export default function DarkModeToggle() {
    const { preference, setPreference } = useTheme();
    const { t } = useLocale();

    const next = ORDER[(ORDER.indexOf(preference) + 1) % ORDER.length] ?? 'system';

    return (
        <button
            type="button"
            onClick={() => setPreference(next)}
            aria-label={`${t('theme.toggle')}: ${t(`theme.${preference}`)}`}
            title={t('theme.toggle')}
            className="inline-flex h-9 min-w-9 items-center justify-center rounded-lg border border-neutral-200 px-2 text-neutral-700 transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:text-slate-200"
        >
            <span aria-hidden="true">{ICON[preference]}</span>
            <span className="sr-only">{t(`theme.${preference}`)}</span>
        </button>
    );
}
