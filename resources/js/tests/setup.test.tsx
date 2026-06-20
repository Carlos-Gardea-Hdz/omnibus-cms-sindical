import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

/**
 * Foundation smoke test: proves the Vitest + React Testing Library + jsdom
 * toolchain (and the window.matchMedia mock in setup.ts) is wired correctly.
 * Replace/extend with real component tests as the UI is built (SPEC §11.3).
 */
describe('frontend test toolchain', () => {
    it('renders a React component into jsdom', () => {
        render(<h1>Corporate CMS</h1>);

        expect(screen.getByRole('heading', { name: 'Corporate CMS' })).toBeInTheDocument();
    });

    it('exposes the matchMedia mock from setup.ts', () => {
        expect(window.matchMedia('(prefers-color-scheme: dark)').matches).toBe(false);
    });
});
