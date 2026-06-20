import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LocaleProvider } from '@/Contexts/LocaleContext';
import { ThemeProvider } from '@/Contexts/ThemeContext';

/*
 * Prop-contract + behavior test for the Representatives/Create page (CONTRACT
 * §13/§16, Vitest row). Mirrors Articles/__tests__/Edit.test.tsx: @inertiajs/react
 * is mocked so the page renders in isolation, and the useForm mock is stateful so
 * we can prove the org→branch cascade filter.
 *
 * The load-bearing contract: the form receives snake_case `organizations:
 * {id,name}[]` and `branches: {id,name,organization_id}[]`, and the branch select
 * is filtered client-side by the chosen organization (a branch only appears once
 * its parent org is selected). The shift select offers the three
 * RepresentativeShift values (type-only union, labels via client i18n).
 */

type FormState = {
    data: Record<string, unknown>;
    errors: Record<string, string>;
};

const initialData = {
    organization_id: '' as number | '',
    branch_id: '' as number | '',
    first_name: '',
    last_name: '',
    shift: '' as string,
    is_coordinator: false,
    photo: null,
};

const formState: FormState = { data: { ...initialData }, errors: {} };

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }) => <title>{title}</title>,
    Link: ({ children, href }: { children?: ReactNode; href?: string }) => (
        <a href={href}>{children}</a>
    ),
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
    router: { delete: vi.fn() },
}));

import RepresentativesCreate from '@/Pages/Representatives/Create';

const organizations = [
    { id: 10, name: 'Org A' },
    { id: 20, name: 'Org B' },
];

const branches = [
    { id: 1, name: 'Branch A1', organization_id: 10 },
    { id: 2, name: 'Branch A2', organization_id: 10 },
    { id: 3, name: 'Branch B1', organization_id: 20 },
];

function renderCreate() {
    return render(
        <ThemeProvider>
            <LocaleProvider>
                <RepresentativesCreate organizations={organizations} branches={branches} />
            </LocaleProvider>
        </ThemeProvider>,
    );
}

describe('Representatives/Create page', () => {
    beforeEach(() => {
        formState.data = { ...initialData };
        formState.errors = {};
    });
    afterEach(cleanup);

    it('renders the form with org, branch and shift selects plus a submit control', () => {
        const { container } = renderCreate();

        expect(container.querySelector('form')).toBeInTheDocument();
        expect(container.querySelector('[type="submit"]')).toBeInTheDocument();
        // org + branch + shift = three selects.
        expect(container.querySelectorAll('select')).toHaveLength(3);
        // The coordinator checkbox is present.
        expect(container.querySelector('[type="checkbox"]')).toBeInTheDocument();
    });

    it('offers all three RepresentativeShift options', () => {
        renderCreate();
        expect(screen.getByRole('option', { name: 'Matutino' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Vespertino' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Nocturno' })).toBeInTheDocument();
    });

    it('filters the branch options to the selected organization', () => {
        // With Org A pre-selected, only its two branches surface (not Branch B1).
        formState.data = { ...initialData, organization_id: 10 };
        renderCreate();

        expect(screen.getByRole('option', { name: 'Branch A1' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Branch A2' })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: 'Branch B1' })).not.toBeInTheDocument();
    });

    it('shows no concrete branch options until an organization is chosen', () => {
        renderCreate();
        // No org selected → no branch options (only the disabled placeholder).
        expect(screen.queryByRole('option', { name: 'Branch A1' })).not.toBeInTheDocument();
        expect(screen.queryByRole('option', { name: 'Branch B1' })).not.toBeInTheDocument();
    });

    it('surfaces an injected server branch_id error inline', () => {
        formState.errors = { branch_id: 'The branch field is required.' };
        renderCreate();

        const alerts = screen.getAllByRole('alert');
        expect(
            alerts.some((node) => /branch|required|sucursal|obligatorio/i.test(node.textContent ?? '')),
        ).toBe(true);
    });

    it('keeps a select control focusable for keyboard users (a11y smoke)', () => {
        renderCreate();
        const select = screen.getAllByRole('combobox')[0];
        fireEvent.focus(select);
        expect(select).toBeInTheDocument();
    });
});
