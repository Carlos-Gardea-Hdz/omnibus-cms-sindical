/**
 * Locally-declared shared-prop shape for the CMS Inertia bridge (slice 002).
 *
 * `flash` and `auth` are injected server-side by HandleInertiaRequests::share().
 * Both are typed optional here so a page never crashes when a given response
 * omits them (e.g. a fresh GET with no flash, or a public page with no auth).
 * Mutations redirect with `back()->with('success'|'error', ...)`, surfaced as
 * `usePage().props.flash.success` / `.error`.
 */
export interface Flash {
    success?: string;
    error?: string;
}

export interface AuthUser {
    id: number;
    username: string;
    role: string;
}

export interface Auth {
    user: AuthUser | null;
}

/**
 * Shared props the server MAY inject on every Inertia response. The index
 * signature makes this assignable to Inertia's `PageProps` constraint
 * (`{ [key: string]: unknown }`) so it can parametrize `usePage<…>()` directly —
 * without taking a hard dependency on the non-hoisted `@inertiajs/core` types.
 */
export interface PageProps {
    flash?: Flash;
    auth?: Auth;
    [key: string]: unknown;
}
