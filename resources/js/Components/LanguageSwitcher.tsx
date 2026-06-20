import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Toggles ES ↔ EN. Native <button>, labelled for screen readers,
 * keyboard-operable, ≥ 36px target.
 */
export default function LanguageSwitcher() {
    const { locale, setLocale, t } = useLocale();
    const next = locale === 'es' ? 'en' : 'es';

    return (
        <button
            type="button"
            onClick={() => setLocale(next)}
            aria-label={t('locale.toggle')}
            title={t('locale.toggle')}
            className="inline-flex h-9 min-w-9 items-center justify-center rounded-lg border border-neutral-200 px-3 text-sm font-semibold text-neutral-700 uppercase transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:text-slate-200"
        >
            {locale}
        </button>
    );
}
