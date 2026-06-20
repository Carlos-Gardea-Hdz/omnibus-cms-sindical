import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import DarkModeToggle from '@/Components/DarkModeToggle';
import RichTextRenderer from '@/Components/content/RichTextRenderer';

/**
 * Public article detail page (SPEC §3.3 NEWS-04). The content render boundary:
 * the JSONB `content` (delivered as an untrusted `Record<string, unknown>`) is
 * handed to RichTextRenderer, which sanitizes it (client-side mirror of the
 * server whitelist) and builds React elements DIRECTLY from the whitelisted tree
 * — NO dangerouslySetInnerHTML on raw user HTML anywhere. A stored-XSS payload
 * therefore cannot become live markup.
 *
 * Props are snake_case, matching Public\ArticleController::show EXACTLY:
 *   { article: { title, subtitle, content, signature, featured_image_url,
 *     category_name, author_name, published_at } }.
 *
 * Public chrome: branded header with the LanguageSwitcher + DarkModeToggle (no
 * auth controls), one #main, one <h1>. Themed magenta, dark/light aware.
 * `views_count` tracking is DEFERRED to the Analytics slice (the page renders;
 * it does not increment).
 */
interface ArticleShowModel {
    title: string;
    subtitle: string | null;
    content: Record<string, unknown>;
    signature: string | null;
    featured_image_url: string | null;
    category_name: string;
    author_name: string;
    published_at: string | null;
}

interface ShowProps {
    article: ArticleShowModel;
}

export default function ArticlesShow({ article }: ShowProps) {
    const { t, locale } = useLocale();

    const publishedLabel = article.published_at
        ? new Date(article.published_at).toLocaleDateString(locale === 'es' ? 'es-MX' : 'en-US', {
              year: 'numeric',
              month: 'long',
              day: 'numeric',
          })
        : null;

    return (
        <>
            <Head title={article.title} />
            <div className="min-h-dvh bg-white text-neutral-900 dark:bg-bg-dark dark:text-slate-100">
                <header className="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-md dark:border-border-dark dark:bg-bg-dark/80">
                    <div className="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                        <Link
                            href="/"
                            className="flex items-center gap-2 text-lg font-extrabold tracking-tight"
                        >
                            <span className="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-primary to-primary-dark text-white">
                                C
                            </span>
                            <span className="text-gradient-primary">{t('app.name')}</span>
                        </Link>
                        <nav className="flex items-center gap-2" aria-label={t('nav.utilities')}>
                            <LanguageSwitcher />
                            <DarkModeToggle />
                        </nav>
                    </div>
                </header>

                <main id="main" className="mx-auto max-w-3xl px-6 py-12">
                    <Link
                        href="/"
                        className="inline-flex items-center gap-1 text-sm font-medium text-primary transition-colors hover:text-primary-dark focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                    >
                        <span aria-hidden="true">&larr;</span>
                        {t('articles.public.back_home')}
                    </Link>

                    <article className="mt-6">
                        <span className="inline-flex items-center rounded-full border border-primary/30 bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-dark dark:bg-primary/10 dark:text-primary-light">
                            {article.category_name}
                        </span>

                        <h1 className="mt-4 text-4xl font-extrabold tracking-tight">
                            {article.title}
                        </h1>

                        {article.subtitle ? (
                            <p className="mt-3 text-lg text-neutral-600 dark:text-slate-300">
                                {article.subtitle}
                            </p>
                        ) : null}

                        <div className="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-500 dark:text-slate-400">
                            <span>{article.author_name}</span>
                            {publishedLabel ? (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <time
                                        dateTime={article.published_at ?? undefined}
                                        className="tabular-nums"
                                    >
                                        {publishedLabel}
                                    </time>
                                </>
                            ) : null}
                        </div>

                        {article.featured_image_url ? (
                            <img
                                src={article.featured_image_url}
                                alt={article.title}
                                className="mt-8 w-full rounded-2xl border border-neutral-200 object-cover dark:border-border-dark"
                            />
                        ) : null}

                        <RichTextRenderer
                            content={article.content}
                            className="mt-8 text-base leading-relaxed"
                        />

                        {article.signature ? (
                            <p className="mt-8 border-t border-neutral-200 pt-4 text-sm font-medium text-neutral-600 italic dark:border-border-dark dark:text-slate-300">
                                {article.signature}
                            </p>
                        ) : null}
                    </article>
                </main>
            </div>
        </>
    );
}
