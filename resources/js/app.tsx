import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { StrictMode } from 'react';
import { ThemeProvider } from '@/Contexts/ThemeContext';
import { LocaleProvider } from '@/Contexts/LocaleContext';

const appName = import.meta.env.VITE_APP_NAME || 'Corporate CMS';

void createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent<ResolvedComponent>(
            `./Pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>(
                ['./Pages/**/*.tsx', '!./Pages/**/*.test.tsx', '!./Pages/**/__tests__/**'],
                {
                    import: 'default',
                },
            ),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(
            <StrictMode>
                <ThemeProvider>
                    <LocaleProvider>
                        <App {...props} />
                    </LocaleProvider>
                </ThemeProvider>
            </StrictMode>,
        );
    },
    progress: {
        color: '#DD00FF',
    },
});
