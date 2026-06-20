import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import ArticleForm, { EMPTY_ARTICLE, type CategoryOption } from '@/Components/content/ArticleForm';

/**
 * Create article screen (SPEC §3.3 NEWS-01). role:editor-gated. New articles are
 * always created as draft (status is server-set; publishing is a separate,
 * manager-gated step).
 *
 * Props are snake_case, matching Admin\ArticleController::create EXACTLY:
 *   { categories: { id, name }[] }.
 * The form POSTs (multipart) to /admin/articles with the ArticleData shape.
 */
interface CreateProps {
    categories: CategoryOption[];
}

export default function ArticlesCreate({ categories }: CreateProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('articles.create.title')}
            subtitle={t('articles.create.subtitle')}
            backHref="/admin/articles"
            backLabel={t('articles.back_to_list')}
        >
            <ArticleForm
                mode="create"
                action="/admin/articles"
                categories={categories}
                initial={EMPTY_ARTICLE}
            />
        </AdminShell>
    );
}
