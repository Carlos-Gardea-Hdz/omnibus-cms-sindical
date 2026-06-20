import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import StatusBadge from '@/Components/content/StatusBadge';
import ArticleForm, { type CategoryOption } from '@/Components/content/ArticleForm';
import type { TipTapDoc } from '@/lib/tiptap';
import { sanitizeTipTap } from '@/lib/sanitizeTipTap';

/**
 * Edit article screen (SPEC §3.3 NEWS-02). role:editor-gated. Image replacement
 * is optional (only a freshly-picked file replaces the stored one).
 *
 * Props are snake_case, matching Admin\ArticleController::edit EXACTLY:
 *   { article: { id, title, slug, subtitle, content, signature,
 *     featured_image_url, status, meta_title, meta_description, category_id,
 *     published_at }, categories: { id, name }[] }.
 *
 * The incoming `content` is sanitized client-side before it ever populates the
 * editor (defense-in-depth — the persisted row is already server-sanitized, but
 * we never trust JSONB on the way back into the DOM). The form submits as
 * POST + _method=put so the multipart image upload survives.
 */
interface ArticleEditModel {
    id: number;
    title: string;
    slug: string;
    subtitle: string | null;
    content: Record<string, unknown>;
    signature: string | null;
    featured_image_url: string | null;
    status: string;
    meta_title: string | null;
    meta_description: string | null;
    category_id: number;
    published_at: string | null;
}

interface EditProps {
    article: ArticleEditModel;
    categories: CategoryOption[];
}

export default function ArticlesEdit({ article, categories }: EditProps) {
    const { t } = useLocale();

    const content: TipTapDoc = sanitizeTipTap(article.content);

    return (
        <AdminShell
            title={t('articles.edit.title')}
            backHref="/admin/articles"
            backLabel={t('articles.back_to_list')}
            toolbar={<StatusBadge status={article.status} />}
        >
            <ArticleForm
                mode="edit"
                action={`/admin/articles/${article.id}`}
                categories={categories}
                initial={{
                    title: article.title,
                    slug: article.slug,
                    subtitle: article.subtitle ?? '',
                    content,
                    category_id: article.category_id,
                    signature: article.signature ?? '',
                    meta_title: article.meta_title ?? '',
                    meta_description: article.meta_description ?? '',
                    featured_image_url: article.featured_image_url,
                }}
            />
        </AdminShell>
    );
}
