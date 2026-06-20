import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LocaleProvider } from '@/Contexts/LocaleContext';
import { ThemeProvider } from '@/Contexts/ThemeContext';

/*
 * Prop-contract + render test for the Organizations/Index page (CONTRACT §13/§16
 * OrgPropsTest, Vitest row). Mirrors Articles/__tests__/Edit.test.tsx: we mock
 * @inertiajs/react so the page renders in isolation (no Inertia runtime). The
 * paginator prop shape is the load-bearing contract — `organizations.data` rows
 * are snake_case { id, name, slug, municipality_name, branch_count,
 * director_name|null, registered_at } exactly as Admin\OrganizationController@index
 * emits — so the page must render those fields and the null-director fallback.
 */

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }) => <title>{title}</title>,
    Link: ({ children, href }: { children?: ReactNode; href?: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props: { flash: {}, errors: {} } }),
    // AdminShell mounts LogoutButton, which calls useForm — provide a stub.
    useForm: () => ({ post: vi.fn(), processing: false }),
    router: { delete: vi.fn() },
}));

import OrganizationsIndex from '@/Pages/Organizations/Index';

const organizations = {
    data: [
        {
            id: 1,
            name: 'Sindicato Demo',
            slug: 'sindicato-demo',
            municipality_name: 'Municipio Norte',
            branch_count: 3,
            director_name: 'Ana Pérez',
            registered_at: '2024-01-15',
        },
        {
            id: 2,
            name: 'Sindicato Sur',
            slug: 'sindicato-sur',
            municipality_name: 'Municipio Sur',
            branch_count: 0,
            director_name: null as string | null,
            registered_at: '2024-03-01',
        },
    ],
    links: [
        { url: null, label: '&laquo; Previous', active: false },
        { url: '/admin/organizations?page=1', label: '1', active: true },
        { url: null, label: 'Next &raquo;', active: false },
    ],
    meta: { from: 1, to: 2, total: 2 },
};

function renderIndex() {
    return render(
        <ThemeProvider>
            <LocaleProvider>
                <OrganizationsIndex organizations={organizations} />
            </LocaleProvider>
        </ThemeProvider>,
    );
}

describe('Organizations/Index page', () => {
    afterEach(cleanup);

    it('renders each org row with its snake_case fields', () => {
        renderIndex();

        expect(screen.getByText('Sindicato Demo')).toBeInTheDocument();
        expect(screen.getByText('sindicato-demo')).toBeInTheDocument();
        expect(screen.getByText('Municipio Norte')).toBeInTheDocument();
        expect(screen.getByText('Ana Pérez')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('falls back to the "unassigned" label when director_name is null', () => {
        const { container } = renderIndex();
        // The es default dictionary resolves organizations.director_none → "Sin asignar".
        expect(container.textContent).toMatch(/sin asignar/i);
    });
});
