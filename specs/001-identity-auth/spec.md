# Spec 001 — Identity Domain: Auth Foundation (the auth spine)

> **Phase:** Specify (the WHAT and WHY, not the HOW).
> **SSOT:** `SPEC.md` (§3.1 Identity AUTH-01..05, §5.4 directory tree, §1.4/§1.5
> branding/Tailwind magenta theme, §6 users table, §7.1/§7.2 routes, §10.1–10.3
> auth flow + RBAC matrix + middleware, §11.2 arch rules).
> **Reference impl (same stack):** `omnibus-uniges` Identity domain — mirror its
> patterns (UserRole enum, AuthenticateUserAction no-enumeration, RoleLandingRoute,
> EnsureRole middleware, the Auth/Login page, the Pest auth/role-gating tests).
> **Status:** DRAFT — awaiting human review gate before `/sdd-plan` / build.

## 1. Problem & why

The CMS foundation is a scaffold: an empty `app/Domain/Identity/` tree, a default
Laravel `users` table (name/email/password, **no role, no username**), a landing
page, and the green arch/Sail/PHPStan baseline. There is **no way to authenticate,
no role model, and no protected surface**. Every later slice (article/category/org
CRUD, analytics, member PII) is gated by who you are and what level you hold.

This slice builds the **auth spine** end-to-end: a 4-level RBAC enum, the `users`
schema that backs it, username+password login with session regeneration and a
role-aware landing, logout, and a **level-based** route gate — plus a login page and
a minimal authed dashboard shell so login has a destination and the gate is testable.
It is the foundation the whole admin surface hangs off.

## 2. Scope

### In scope (the auth spine)

1. **`UserRole` backed enum** (`app/Domain/Identity/Enums/UserRole.php`,
   `#[TypeScript]`) — the 4-level **strict ladder**:
   `super_admin (4) > administrator (3) > manager (2) > editor (1)`.
   Methods: `level()`, `labelKey()`, `color()`, and the ladder gate
   `hasAtLeast(self $minimum): bool` (a higher level satisfies a lower-level
   requirement). No magic strings anywhere downstream.

2. **`users` schema** — `role`, `username` (unique), and the deferred-slice columns
   `organization_id` (nullable FK, **deferred** to the Organization domain) and
   `is_demo` (boolean, **deferred** to the demo slice). The default `users` table is
   fresh/unseeded, so **edit the create migration** (single source) rather than
   stacking an add-column migration. Reversible by construction (the table drop in
   `down()` already reverses it).

3. **`User` model** (stays at `app/Models/User.php` — Eloquent base model, referenced
   cross-domain; SPEC §5.2 Rule 3 allows model cross-reference) — cast `role` →
   `UserRole`, add `username` + `role` + `is_demo` to `$fillable`, keep `password`
   `hashed` cast (bcrypt), `@property`/`@property-read` PHPDoc for PHPStan L9. The
   factory gains `role`/`username` defaults + per-role states.

4. **`LoginData` DTO** (`app/Domain/Identity/Data/LoginData.php`, `#[TypeScript]`,
   `final`, extends `Spatie\LaravelData\Data`) — `username` (required, max 60) +
   `password` (required, max 255) + `remember` (bool, default false). Spatie Data is
   the single validation SSOT. **No FormRequest, no `$request->validate()`.**

5. **`AuthenticateUserAction`** (`app/Domain/Identity/Actions/`, `final`) — one
   operation: normalize the username (trim + lowercase to match the stored, lowercased
   column), attempt the session guard, and on failure throw a `ValidationException`
   keyed on `username` carrying the **single generic** `auth.failed` message (identical
   for wrong-password vs unknown-user → no user enumeration). Returns the authenticated
   `User`. **Session regeneration + redirect live in the controller**, not the Action
   (the domain never imports `Illuminate\Http`).

6. **`RoleLandingRoute`** support class (`app/Http/Support/`) — the single source of
   truth mapping a `UserRole` → its post-login route name, used by BOTH the real-login
   path now and the deferred demo-login path later (never duplicated). Exhaustive
   `match` over the enum (adding a role is a compile-time obligation).

7. **`EnsureRole` middleware** (`app/Http/Middleware/EnsureRole.php`, alias `role`) —
   gates a route by **minimum level** (not exact-role membership): the route declares
   `role:editor` (or `manager`/`administrator`/`super_admin`) and the gate passes when
   `user.role.hasAtLeast(required)`. A guest → redirect to `login` (302). An
   authenticated user below the level → 403.

8. **Anemic auth controller** (`app/Http/Controllers/Auth/LoginController.php`, ≤15
   lines/method) — `create()` renders `Auth/Login`; `store(LoginData, AuthenticateUserAction)`
   calls the Action, regenerates the session, redirects to `RoleLandingRoute::for(role)`;
   `destroy()` logs out, invalidates the session, regenerates the token, redirects to
   `login`. **`DashboardController`** (`app/Http/Controllers/Admin/`) — `index()` renders
   the shell `Admin/Dashboard` page.

9. **Routes** (`routes/web.php`, all `->name()`, no closures) — `GET/POST /login`,
   `POST /logout`, and `GET /admin/dashboard` behind `['auth','role:editor']`. The
   `role` alias registered in `bootstrap/app.php`. Login throttle (`throttle:login`) is
   **optional** this slice (see §2 deferred).

10. **Inertia 2 + React 19 + TS pages** — `Auth/Login` (username+password+remember,
    bound to the generated `LoginData` type via `useForm`, magenta theme per §1.4/§1.5,
    dark/light + ES/EN, generic credential error surfaced on `username`) and
    `Admin/Dashboard` (minimal authed shell: greeting + role label + logout). Snake_case
    props matching the controller payloads exactly.

11. **i18n keys** — `auth.*` + `role.*` keys added to BOTH `lang/es.json` and
    `lang/en.json` (the dir does not exist yet — create it), specifically every `__()`
    key the Action/controller flashes (`auth.failed`) plus the role labels the enum's
    `labelKey()` resolves.

12. **Tests (Pest, PostgreSQL 18 — never SQLite for DB tests; Feature not Unit for
    app-bound tests):**
    - Unit (enum, no DB): the 4-level ladder + `hasAtLeast` matrix, `level()`/`color()`.
    - Feature: login happy path per role → correct landing; wrong-password and
      unknown-user → identical generic 302 + session error on `username` (no
      enumeration); malformed/short input → 302 + session error (never 422); logout;
      the **4×4 level-gate matrix** (each role vs each required level → 200/403) + guest
      → 302 to login; login + dashboard Inertia prop-contract tests; the `auth.failed` /
      `role.*` lang-key resolution test.
    - Vitest: the `Auth/Login` page renders the form + surfaces an injected `username`
      error inline.
    - Arch: extend `tests/Arch/ArchitectureTest.php` with **Identity-only-uses-Shared**
      (per SPEC §11.2) — strict types, final domain classes, anemic controllers, no
      FormRequest already covered by the baseline suite.

### Out of scope — DEFERRED (explicitly noted, not silently dropped)

- **AUTH-02 demo login** (3-role chooser, 30-min TTL, IP rate-limit 10/hr, `is_demo`
  session flag) → a later **demo-mode slice**. The `is_demo` column lands now (cheap,
  unblocks the schema) but is otherwise inert.
- **AUTH-04 user CRUD** (create/update/delete + role assignment, super-admin-single-at-a-time
  rule, super-admin-edits-own-record-only) → a later **user-management slice**
  (`CreateUserData`, `CreateUserAction`, `UserPolicy`, `Admin/UserController`).
- **Manager / editor ORG-SCOPING** (`EnsureOrganizationScopeMiddleware`,
  `user.organization_id == resource.organization_id`) → needs the **Organization
  domain**. This slice's gate enforces **LEVEL only**; `organization_id` lands as a
  nullable column now (no FK target yet) and org-scoping is a follow-up.
- **Login brute-force throttle** (SPEC §10.4: 5 attempts / 15 min, key IP+username;
  UNIGES-style `LoginThrottle` + `login_attempts` table) → **OPTIONAL** this slice.
  Recommend deferring the persisted-ledger service to the user-management/security slice
  and, if any guard is wanted now, applying Laravel's built-in `throttle:login` rate
  limiter to `POST /login` (cheap, no schema). Default recommendation: **defer**, gate
  decision below.
- **`SecurityHeadersMiddleware`, `DemoSessionMiddleware`, `TrackPageViewMiddleware`,
  Valkey session driver, `corporate_session` cookie name, 120-min lifetime** → these are
  config/middleware that belong to later security/demo/analytics slices. The 120-min
  lifetime + cookie name is a **config note** here (SPEC §10.1), not necessarily code
  this slice.

### Deviation from SPEC, flagged for the gate

- **Login key is `username`, not `email`.** SPEC §3.1 AUTH-01 + §7.1 + §10.1 specify
  username+password (the legacy system authenticates by username). UNIGES authenticates
  by email — we deliberately diverge from the reference here and key the DTO, Action,
  throttle, and generic-error field on `username`. → **Gate decision A.**
- **Gate is level-based (ladder), not exact-role membership.** UNIGES's `EnsureRole`
  matches an explicit allow-list of role strings; the CMS RBAC is a strict ladder
  (§10.2 matrix: a higher role implies every lower capability), so the CMS gate takes a
  single **minimum level** and passes any role at or above it. → **Gate decision B.**

## 3. Acceptance scenarios (When… Then)

1. **Login happy path (per role).** *When* a user with role R and a correct
   username+password posts `/login`, *then* the session guard authenticates them, the
   session id is regenerated, and they are redirected to `RoleLandingRoute::for(R)`
   (super_admin/administrator/manager → `admin.dashboard`; editor → `admin.dashboard`
   for this slice's shell — all four land on the dashboard now), and `assertAuthenticatedAs`.
2. **Wrong password → generic 302, no enumeration.** *When* an existing user posts a
   wrong password, *then* the response is a 302 redirect-back with a session error on
   `username` carrying `__('auth.failed')`, and the user stays a guest.
3. **Unknown user → identical generic 302.** *When* a username that does not exist is
   posted, *then* the response is the **same** 302 + session error on `username` with the
   **same** `__('auth.failed')` message — the response never reveals whether the account
   exists.
4. **Malformed/short input → 302, never 422.** *When* the DTO's rules fail (missing
   username, over-length, missing password), *then* Spatie Data surfaces a 302
   redirect-back with session errors, never a 422 JSON response, and the user stays a
   guest.
5. **Logout.** *When* an authenticated user posts `/logout`, *then* the web guard logs
   out, the session is invalidated and the CSRF token regenerated, and they are
   redirected to `login`; a subsequent protected request is a guest 302.
6. **Level-gate matrix.** *For each* role R against *each* required minimum level L:
   *when* a user with role R requests a route gated `role:L`, *then* the response is
   **200** iff `R.level >= L.level`, else **403**. A guest hitting any gated route is a
   **302 to login** (never 403).
7. **Ladder semantics.** *When* `super_admin` hits a `role:editor` route, *then* 200
   (higher satisfies lower); *when* `editor` hits a `role:administrator` route, *then* 403.
8. **Prop contract.** *When* `GET /login` renders, *then* the Inertia component is
   `Auth/Login` with no leaked server props; *when* `GET /admin/dashboard` renders for an
   authed user, *then* the component is `Admin/Dashboard` and its props match the page's
   prop interface exactly (snake_case), carrying the user's `username` and `role` value.
9. **Lang keys resolve.** *When* `__('auth.failed')` and each `role.{value}` label key
   are resolved under `es` and `en`, *then* a non-empty, non-key string is returned in
   both locales (no missing-key fallthrough).

## 4. Success criteria (definition of done for this slice)

- `composer format` (Pint) clean; `composer analyse` (Larastan **level 9**) green;
  `composer test` (Pest, incl. the extended arch suite + the new unit/feature tests on
  PostgreSQL 18) green.
- `php artisan typescript:transform` emits `UserRole` + `LoginData` into
  `resources/js/types/generated.d.ts`; `tsc --noEmit` and the Vitest suite pass.
- `php artisan migrate:fresh` applies cleanly on PostgreSQL 18; `username` unique,
  `role` non-null with a default, `organization_id` + `is_demo` nullable; `down()`
  reverses cleanly.
- The login page renders the magenta-themed form with dark/light + ES/EN; login →
  dashboard → logout is a working round trip; the level gate is enforced.
- No real PII anywhere; demo seed users are fictional; `.gitignore` still blocks
  db/secrets.

## 5. Constitution (§No-negociables) this slice must satisfy

`declare(strict_types=1)` everywhere · explicit return types · `final` domain classes ·
`readonly` + constructor promotion on the DTO · backed enum with `level()/labelKey()/color()/hasAtLeast()` ·
Spatie Data DTOs only (no FormRequest, no `$request->validate()`) · Action one-operation
(no multi-table write here, so no `DB::transaction` required) · anemic controllers (≤15
lines, DTO→Action→response) · domain never imports `Illuminate\Http` · web validation =
302 + session errors, never 422 · generic credential error (no enumeration) · Pest with
arch tests · PostgreSQL for DB tests · dark/light + bilingual UI.

## 6. Open questions for the gate

- **A. Username (not email) as the login key — confirm.** SPEC says username; the
  reference impl uses email. Recommend username per SPEC. Confirm.
- **B. Level-based gate (ladder) vs exact-role allow-list — confirm.** Recommend the
  ladder (`hasAtLeast(min)`) per §10.2. Confirm.
- **C. Brute-force throttle now or deferred?** Recommend deferring the persisted
  `LoginThrottle`/`login_attempts` ledger to a later security slice; optionally apply the
  built-in `throttle:login` limiter now (no schema). Confirm defer vs. include-built-in.
- **D. Editor landing route.** SPEC §7.2 puts editors on `/admin/dashboard` too (a
  role-aware dashboard). This slice lands all four roles on `admin.dashboard` (the shell).
  Confirm, or split editor → a content route later when Content lands.
- **E. `organization_id` column now (nullable, no FK) vs. add it with the Organization
  slice.** Recommend land it nullable now so the users schema is stable and the demo/CRUD
  slices don't re-migrate. Confirm.
