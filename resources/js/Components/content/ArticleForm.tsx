import { useForm } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import TextField from '@/Components/form/TextField';
import TextArea from '@/Components/form/TextArea';
import SelectField from '@/Components/form/SelectField';
import ImageField from '@/Components/form/ImageField';
import RichTextEditor from '@/Components/content/RichTextEditor';
import type { TipTapDoc } from '@/lib/tiptap';
import { EMPTY_DOC } from '@/lib/tiptap';
import { sanitizeTipTap } from '@/lib/sanitizeTipTap';

/**
 * Shared create/edit article form (slice 002, NEWS-01/02). One form serves store
 * and update — mirroring the single `ArticleData` DTO. Driven by Inertia's
 * useForm so server validation surfaces as 302 + session errors (NEVER 422),
 * shown inline per field via the form primitives' `error` slots.
 *
 * The submit payload is snake_case and matches App\Domain\Content\Data\ArticleData
 * exactly: { title, slug, subtitle, content, category_id, signature, meta_title,
 * meta_description, featured_image }. `content` is the TipTap doc JSON — sanitized
 * client-side before submit (defense-in-depth; the server sanitizer is
 * authoritative). The featured image rides as a File (multipart) under
 * `featured_image`; useForm auto-switches to FormData when a File is present.
 *
 * Method spoofing: Edit submits as POST with `_method: 'put'` so the file upload
 * survives (PHP can't parse multipart on a true PUT body) — the controller's PUT
 * route still resolves.
 */
export interface CategoryOption {
    id: number;
    name: string;
}

export interface ArticleFormInitial {
    title: string;
    slug: string;
    subtitle: string;
    content: TipTapDoc;
    category_id: number | '';
    signature: string;
    meta_title: string;
    meta_description: string;
    /** Existing stored image URL (Edit only). */
    featured_image_url: string | null;
}

interface ArticleFormState {
    title: string;
    slug: string;
    subtitle: string;
    content: TipTapDoc;
    category_id: number | '';
    signature: string;
    meta_title: string;
    meta_description: string;
    featured_image: File | null;
}

interface ArticleFormProps {
    mode: 'create' | 'edit';
    /** Resolved submit URL. */
    action: string;
    categories: CategoryOption[];
    initial: ArticleFormInitial;
}

export const EMPTY_ARTICLE: ArticleFormInitial = {
    title: '',
    slug: '',
    subtitle: '',
    content: EMPTY_DOC,
    category_id: '',
    signature: '',
    meta_title: '',
    meta_description: '',
    featured_image_url: null,
};

export default function ArticleForm({ mode, action, categories, initial }: ArticleFormProps) {
    const { t } = useLocale();

    const { data, setData, post, processing, errors, transform } = useForm<ArticleFormState>({
        title: initial.title,
        slug: initial.slug,
        subtitle: initial.subtitle,
        content: initial.content,
        category_id: initial.category_id,
        signature: initial.signature,
        meta_title: initial.meta_title,
        meta_description: initial.meta_description,
        featured_image: null,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        // Sanitize the doc one more time at the boundary, and spoof PUT on edit so
        // the multipart body survives.
        transform((payload) => ({
            ...payload,
            content: sanitizeTipTap(payload.content),
            ...(mode === 'edit' ? { _method: 'put' } : {}),
        }));
        post(action, { preserveScroll: true, forceFormData: true });
    };

    const categoryOptions = categories.map((category) => ({
        value: category.id,
        label: category.name,
    }));

    return (
        <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-3">
            <div className="flex flex-col gap-6 lg:col-span-2">
                <TextField
                    label={t('articles.field.title')}
                    name="title"
                    value={data.title}
                    onChange={(value) => setData('title', value)}
                    error={errors.title}
                    required
                    maxLength={150}
                    autoFocus
                />

                <TextField
                    label={t('articles.field.subtitle')}
                    name="subtitle"
                    value={data.subtitle}
                    onChange={(value) => setData('subtitle', value)}
                    error={errors.subtitle}
                    maxLength={200}
                />

                <RichTextEditor
                    label={t('articles.field.content')}
                    value={data.content}
                    onChange={(doc) => setData('content', doc)}
                    error={errors.content}
                    required
                    hint={t('articles.field.content_hint')}
                />
            </div>

            <aside className="flex flex-col gap-6">
                <SelectField
                    label={t('articles.field.category')}
                    value={data.category_id}
                    onChange={(value) => setData('category_id', value === '' ? '' : Number(value))}
                    options={categoryOptions}
                    placeholder={t('articles.field.category_placeholder')}
                    error={errors.category_id}
                    required
                />

                <ImageField
                    label={t('articles.field.featured_image')}
                    value={data.featured_image}
                    onChange={(file) => setData('featured_image', file)}
                    currentUrl={initial.featured_image_url}
                    previewAlt={t('articles.field.featured_image_alt')}
                    removeLabel={t('articles.field.featured_image_remove')}
                    error={errors.featured_image}
                    hint={t('articles.field.featured_image_hint')}
                    required={mode === 'create' ? false : false}
                />

                <TextField
                    label={t('articles.field.slug')}
                    name="slug"
                    value={data.slug}
                    onChange={(value) => setData('slug', value)}
                    error={errors.slug}
                    maxLength={180}
                    hint={t('articles.field.slug_hint')}
                />

                <TextField
                    label={t('articles.field.signature')}
                    name="signature"
                    value={data.signature}
                    onChange={(value) => setData('signature', value)}
                    error={errors.signature}
                    maxLength={200}
                />

                <details className="rounded-lg border border-neutral-200 p-3 dark:border-border-dark">
                    <summary className="cursor-pointer text-sm font-medium text-neutral-700 dark:text-slate-300">
                        {t('articles.field.seo')}
                    </summary>
                    <div className="mt-3 flex flex-col gap-4">
                        <TextField
                            label={t('articles.field.meta_title')}
                            name="meta_title"
                            value={data.meta_title}
                            onChange={(value) => setData('meta_title', value)}
                            error={errors.meta_title}
                            maxLength={200}
                        />
                        <TextArea
                            label={t('articles.field.meta_description')}
                            value={data.meta_description}
                            onChange={(value) => setData('meta_description', value)}
                            error={errors.meta_description}
                            rows={2}
                        />
                    </div>
                </details>

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex h-11 items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                >
                    {mode === 'create' ? t('articles.action.create') : t('articles.action.save')}
                </button>
            </aside>
        </form>
    );
}
