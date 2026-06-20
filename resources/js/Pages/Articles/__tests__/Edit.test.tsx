import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LocaleProvider } from '@/Contexts/LocaleContext';
import { ThemeProvider } from '@/Contexts/ThemeContext';

/*
 * Unit test for the Articles/Edit page (CONTRACT §8/§11, Vitest row). The editor is
 * driven by Inertia's useForm; we mock @inertiajs/react so it renders in isolation
 * (no Inertia runtime, no TipTap network), and so we can inject server validation
 * errors to prove they surface inline. ArticleData (Spatie Data) is the validation
 * SSOT, so a failed store/update returns the field errors on `title` / `status`,
 * which the page must render via the shared FormError (role="alert"). The form
 * data/errors are controllable per test.
 *
 * NOTE: the props/shape are typed off the generated ambient App.Domain.Content.Data
 * types TYPE-ONLY; this test exercises runtime error-surfacing, not the type layer.
 */

type FormState = {
    data: Record<string, unknown>;
    errors: Record<string, string>;
};

const initialData = {
    title: 'Comunicado',
    slug: 'comunicado',
    subtitle: '',
    content: { type: 'doc', content: [] },
    signature: '',
    category_id: 1,
    meta_title: '',
    meta_description: '',
    featured_image: null,
};

const formState: FormState = { data: { ...initialData }, errors: {} };

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }) => <title>{title}</title>,
    Link: ({ children, href }: { children?: ReactNode; href?: string }) => (
        <a href={href}>{children}</a>
    ),
    // AdminShell reads usePage().props.flash — provide an empty, well-formed shape.
    usePage: () => ({ props: { flash: {}, errors: {} } }),
    useForm: () => ({
        data: formState.data,
        setData: (key: string, value: unknown) => {
            formState.data[key] = value;
        },
        errors: formState.errors,
        processing: false,
        post: vi.fn(),
        put: vi.fn(),
        transform: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
    router: { post: vi.fn(), put: vi.fn() },
}));

// Imported after the mocks so the page picks up the mocked modules. The
// dependency-free contentEditable rich-text editor mounts under jsdom as-is (no
// @tiptap runtime), so we render it for real to satisfy "renders the editor".
import Edit from '@/Pages/Articles/Edit';

const article = {
    id: 1,
    title: 'Comunicado',
    slug: 'comunicado',
    subtitle: null as string | null,
    content: { type: 'doc', content: [] } as Record<string, unknown>,
    signature: null as string | null,
    featured_image_url: null as string | null,
    status: 'draft',
    meta_title: null as string | null,
    meta_description: null as string | null,
    category_id: 1,
    published_at: null as string | null,
};

const categories = [
    { id: 1, name: 'Comunicados' },
    { id: 2, name: 'Eventos' },
];

function renderEdit() {
    return render(
        <ThemeProvider>
            <LocaleProvider>
                <Edit article={article} categories={categories} />
            </LocaleProvider>
        </ThemeProvider>,
    );
}

describe('Articles/Edit page', () => {
    afterEach(() => {
        cleanup();
        formState.data = { ...initialData };
        formState.errors = {};
    });

    it('renders the article editor form with a rich-text surface and a submit control', () => {
        const { container } = renderEdit();

        expect(container.querySelector('form')).toBeInTheDocument();
        expect(container.querySelector('[type="submit"]')).toBeInTheDocument();
        // The dependency-free rich-text editor mounts a contentEditable textbox.
        expect(container.querySelector('[contenteditable="true"]')).toBeInTheDocument();
        // The category select is present and bound.
        expect(container.querySelector('select')).toBeInTheDocument();
    });

    it('surfaces an injected server title error inline', () => {
        formState.errors = { title: 'The title field is required.' };

        renderEdit();

        const alerts = screen.getAllByRole('alert');
        expect(
            alerts.some((node) =>
                /title|título|required|obligatorio/i.test(node.textContent ?? ''),
            ),
        ).toBe(true);
    });

    it('surfaces an injected server content error inline (a malformed/empty body)', () => {
        // The editor's body field is `content`; a failed store/update flashes its
        // error there (the editable fields the Edit form owns are title / subtitle
        // / content / category / slug / signature / SEO — NOT `status`, which is a
        // publish-precondition error surfaced on the Articles/Index transition POST
        // and covered by the backend ArticlePublishTest).
        formState.errors = { content: 'The content field is required.' };

        renderEdit();

        const alerts = screen.getAllByRole('alert');
        expect(
            alerts.some((node) =>
                /content|contenido|required|obligatorio/i.test(node.textContent ?? ''),
            ),
        ).toBe(true);
    });
});
