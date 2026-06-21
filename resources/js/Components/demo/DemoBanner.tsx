import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Persistent demo-mode banner (CMS slice 008, AUTH-02). Renders ONLY when the
 * server flags the current session as a demo via the shared `demo` prop
 * (HandleInertiaRequests::share) — `{ is_demo: true, expires_at: <unix-seconds> }`.
 * For a real (non-demo) session the shared prop is `null` and this renders nothing,
 * so every authed page can mount it unconditionally.
 *
 * It surfaces the live remaining TTL (the server slides `demo_expires_at` forward
 * on each request; the DemoSessionMiddleware enforces the hard cap and logs the
 * user out at expiry). The countdown here is purely informational — a client tick
 * down from `expires_at` so the user always sees an honest "expires in mm:ss". When
 * the cushion runs out the next navigation hits the middleware and is redirected.
 *
 * The banner NEVER carries the demo session token (server-only). role="status"
 * (polite) so it announces once without hijacking focus. Themed magenta,
 * dark/light aware, WCAG 2.2 AA contrast.
 */
interface DemoSharedProp {
    is_demo: boolean;
    expires_at: number | null;
}

interface DemoPageProps extends PageProps {
    demo?: DemoSharedProp | null;
}

/** Format a non-negative second count as mm:ss. */
function formatRemaining(totalSeconds: number): string {
    const safe = Math.max(0, totalSeconds);
    const minutes = Math.floor(safe / 60);
    const seconds = safe % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

export default function DemoBanner() {
    const { t } = useLocale();
    const demo = usePage<DemoPageProps>().props.demo;

    const expiresAt = demo?.expires_at ?? null;
    const [remaining, setRemaining] = useState<number>(() =>
        expiresAt ? expiresAt - Math.floor(Date.now() / 1000) : 0,
    );

    useEffect(() => {
        if (!expiresAt) {
            return;
        }
        const tick = () => setRemaining(expiresAt - Math.floor(Date.now() / 1000));
        tick();
        const handle = window.setInterval(tick, 1000);
        return () => window.clearInterval(handle);
    }, [expiresAt]);

    if (!demo?.is_demo) {
        return null;
    }

    const expiresLabel = expiresAt
        ? t('demo.banner.expires_in').replace('{time}', formatRemaining(remaining))
        : null;

    return (
        <div
            role="status"
            className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-primary/30 bg-primary-50 px-4 py-2 text-center text-sm font-medium text-primary-dark dark:bg-primary/10 dark:text-primary-light"
        >
            <span className="inline-flex items-center gap-2">
                <span
                    aria-hidden="true"
                    className="grid h-5 w-5 place-items-center rounded-full bg-primary/15 text-xs font-bold text-primary"
                >
                    ◉
                </span>
                {t('demo.banner.active')}
            </span>
            {expiresLabel ? (
                <span className="tabular-nums opacity-90">{expiresLabel}</span>
            ) : null}
        </div>
    );
}
