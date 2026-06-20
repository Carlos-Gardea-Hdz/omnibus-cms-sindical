import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LocaleProvider } from '@/Contexts/LocaleContext';
import { ThemeProvider } from '@/Contexts/ThemeContext';
import ShiftBadge from '@/Components/organization/ShiftBadge';

/*
 * Unit test for ShiftBadge (CONTRACT §5/§13). The badge takes the raw `shift`
 * backing string + the server-resolved `shift_label_key`
 * (`representative_shift.<value>`) and renders the client-i18n label tinted with
 * the magenta palette. TYPE-ONLY enum contract: no value-import of the generated
 * enum. An unknown shift falls back to the raw value + the primary accent.
 */

function renderBadge(shift: string, labelKey: string) {
    return render(
        <ThemeProvider>
            <LocaleProvider>
                <ShiftBadge shift={shift} labelKey={labelKey} />
            </LocaleProvider>
        </ThemeProvider>,
    );
}

describe('ShiftBadge', () => {
    afterEach(cleanup);

    it('resolves each known shift to its localized label', () => {
        renderBadge('morning', 'representative_shift.morning');
        expect(screen.getByText('Matutino')).toBeInTheDocument();
        cleanup();

        renderBadge('evening', 'representative_shift.evening');
        expect(screen.getByText('Vespertino')).toBeInTheDocument();
        cleanup();

        renderBadge('night', 'representative_shift.night');
        expect(screen.getByText('Nocturno')).toBeInTheDocument();
    });

    it('falls back to the raw value when the label key does not resolve', () => {
        renderBadge('weekend', 'representative_shift.weekend');
        expect(screen.getByText('weekend')).toBeInTheDocument();
    });

    it('tints the pill with an inline magenta accent', () => {
        const { container } = renderBadge('morning', 'representative_shift.morning');
        const pill = container.querySelector('span[style]');
        expect(pill).toHaveStyle({ backgroundColor: '#DD00FF' });
    });
});
