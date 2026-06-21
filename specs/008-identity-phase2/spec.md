# Spec 008 — Identity Phase 2 (Demo Login AUTH-02 + User CRUD AUTH-04)

> **Phase:** Specify (the WHAT and WHY, not the HOW). The two deferred Identity items that round out the
> CMS as a recruiter-explorable, admin-manageable product. Closes slice 001's two parked stories.
> **SSOT:** `SPEC.md` (§3.1 **AUTH-02** [demo login with role selection — 30-min TTL, IP rate limit
> 10/hour, session flag `is_demo=true`; 3 roles: administrator, manager, editor] + **AUTH-04** [user CRUD
> admin-only — create/update/delete with role assignment; **super admin: only one at a time, assigning a
> new one unsets the previous**; **super admins can only edit their own record**] + **AUTH-03** [the
> 4-level gate, REUSED] + **AUTH-05** [logout — demo sessions cleared properly]; §6.3.6 `users` table
> [`is_demo` + `organization_id` already migrated in slice 001/003; `name`, `avatar_path`, `last_login_at`,
> `last_login_ip`, `deleted_at`/SoftDeletes per the SPEC schema]; §7.1 `POST /demo-login` →
> `Auth\DemoLoginController@store` + §7.4 `Resource /admin/users` → `Admin\UserController`; §10.2 RBAC
> [**Users CRUD | super_admin All | administrator Own org | manager — | editor —**]; §10.3
> `DemoSessionMiddleware` [check TTL, restrict destructive ops] + `EnsureRoleMiddleware`; §10.4 rate limits
> [**`POST /demo-login` 10/hour by IP**; `POST /login` 5/15min — login throttle is NOT this slice]; §10.1
> session [Valkey, 120-min lifetime, HttpOnly/Secure/SameSite=Lax]; §11.2 arch [`App\Domain\Identity`
> toOnlyUse Shared/Illuminate/Spatie]; §1.4/§1.5 magenta theme [`#DD00FF` primary]).
> **Reference impl (same stack — built + reviewed): the UNIGES DEMO MODE (slice 006).** MIRROR it 1:1
> where the patterns transfer: `omnibus-uniges/app/Support/DemoContext.php` (per-request demo tag holder),
> `…/app/Http/Middleware/DemoSessionMiddleware.php` (TTL expiry + `DESTRUCTIVE_ROUTE_NAMES` block + TTL
> slide + priority placement BEFORE `SubstituteBindings`), `…/app/Domain/Identity/Enums/DemoPreset.php`
> (preset→role map, NEVER an admin-tier the demo must not reach), `…/app/Domain/Identity/Data/DemoLoginData.php`
> (enum-cast Spatie DTO), `…/app/Domain/Identity/Actions/ProvisionDemoSessionAction.php` (mint a tagged
> demo user in ONE `DB::transaction`, return a VO, stay free of `Illuminate\Http`),
> `…/app/Domain/Identity/ValueObjects/DemoSessionResult.php` (the server-only transport VO),
> `…/app/Domain/Identity/Actions/DemoCleanupAction.php` (force-delete tagged rows past TTL, every delete
> guarded `whereNotNull('demo_session_id')`), `…/app/Console/Commands/DemoCleanupCommand.php`
> (`demo:cleanup`), `…/app/Http/Controllers/Auth/DemoLoginController.php` (HTTP-layer login + session
> flags + `RoleLandingRoute`), the `demo-login` `RateLimiter` in `…/app/Providers/AppServiceProvider.php`
> (`Limit::perHour(10)->by(ip)`, exceed → `back()->withErrors(['preset' => …])`), the
> `Schedule::command('demo:cleanup')->everyFifteenMinutes()->withoutOverlapping()` entry, and the
> `Pages/Auth/DemoChooser.tsx` chooser.
> **The org-scoping spine (REUSED for demo isolation):** `App\Support\OrganizationContext` +
> `App\Support\OrganizationScope` + `EnsureOrganizationScope` (`org.scope`, ordered BEFORE
> `SubstituteBindings`). The CMS already org-scopes Content/Organization/Jobs/Membership/Engagement, so a
> demo user (administrator/manager/editor) is auto-confined to its org by the EXISTING spine — UNIGES had
> to invent a whole `DemoScope`; the CMS reuses what slice 003 built. **The slice-001 Identity:** `UserRole`
> ladder (`level()`/`hasAtLeast()`/`labelKey()`/`color()`), `EnsureRole` (`role:<level>`),
> `AuthenticateUserAction`, `LoginData`, `LoginController` (the `create`/`store`/`destroy` shape this
> slice's demo controller and user controller echo), `RoleLandingRoute` (the SSOT role→route map BOTH the
> real-login and demo-login paths reuse), `User` model (`is_demo` boolean cast, `organization()` BelongsTo,
> `$fillable`, `casts()['password' => 'hashed']`, NO OrganizationScope — Deviation A), `UserFactory`
> (`editor()`/`manager()`/`administrator()`/`superAdmin()`/`forOrganization()` states).
> **Built on (DO NOT break — slices 001–007 suites are green):** the arch suite (`App\Domain\Identity`
> boundary), the magenta theme + `lang/{es,en}.json` (server `__()`) + `resources/locales/{es,en}.json`
> (frontend `t()`), `bootstrap/app.php` middleware priority spine (`EnsureRole` then
> `EnsureOrganizationScope` prepended before `SubstituteBindings`).
> **Status:** DRAFT — awaiting human review gate before `/sdd-plan` / build.

---

## 1. Problem & why

Slice 001 shipped the Identity **core** (the 4-level ladder, real login, the gate, the org-scoping spine)
and **deliberately parked two AUTH stories** because they depend on later domains: **AUTH-02 demo login**
needs an org to confine a demo user to (slice 003) and the destructive route names to block (slices 002–007),
and **AUTH-04 user CRUD** needs the org-scoping spine to confine an administrator's user management. Both
their columns (`is_demo`, `organization_id`) were migrated up-front in slice 001/003. With all seven domains
built, this slice wires the two parked stories — turning the CMS into a **recruiter-explorable** product
(anyone can click "try a demo" and walk the admin shell as an administrator/manager/editor) AND an
**admin-manageable** one (an administrator manages users in its org; a super_admin manages users cross-org).

### 1.1 AUTH-02 — Demo login (the recruiter sandbox)

A visitor on the public login screen picks one of **three** demo personas — **administrator, manager,
editor** (NEVER super_admin) — and is dropped into a throwaway, time-boxed, org-confined sandbox session so
they can explore the admin shell without credentials. The whole point — **get these right the FIRST time:**

- **The preset set is structurally super_admin-proof (AUTH-02 ∩ AUTH-04).** `DemoPreset` exposes ONLY
  administrator/manager/editor. There is **no super_admin preset and there can never be one** — that is the
  load-bearing isolation invariant: because no demo persona is super_admin, a demo session can NEVER reach
  the super_admin-only surfaces (Organizations CRUD, Analytics-cross-org, the cross-org User CRUD,
  super_admin assignment). The `role:super_admin` gate already 403s them; the missing preset means the
  request is never even mintable.

- **The demo session is org-confined by the EXISTING spine (demo isolation, the CMS way).** A demo user is
  stamped with a real (seeded, shared) demo `organization_id`. The slice-003 `EnsureOrganizationScope`
  middleware then auto-confines an administrator? — **NO:** administrator runs UNCONFINED (level 3 > Manager).
  So demo isolation is **two-layered**: (a) a **demo administrator** is unconfined BUT is blocked from every
  cross-org/destructive route by the demo middleware + the missing super_admin preset, and is read-confined
  to demo+real shared data; (b) a **demo manager/editor** is auto-confined to its demo org by the existing
  `org.scope` spine (it sees ONLY its org's articles/jobs/members/contacts). The demo user mutates nothing
  shared because every destructive route is blocked (next bullet).

- **The destructive-mutation block (the sandbox guarantee).** A `DemoSessionMiddleware` (mirroring UNIGES)
  blocks the irreversible / shared-data-escaping routes for a demo session with a graceful **302 + flash**
  (never a mutation, never a 500). The blocked set is the **destroy / super-admin / catalog-write** route
  names: every `*.destroy`, every super_admin-only write (organizations, user create/update/delete,
  super_admin assignment), and the shared-catalog writes (categories, municipalities) — see §3.1 for the
  exact list. Read GETs and own-org create/update of demo-scoped content are allowed (so the demo feels
  alive). This is **defence in depth** atop the missing super_admin preset + the org-scope.

- **The 30-min TTL + IP rate-limit (AUTH-02, §10.4).** The session carries `is_demo=true` +
  `demo_expires_at`; the middleware expires the sandbox once 30 minutes elapse (logout + invalidate + token
  rotate + redirect to login with a flash) and slides the TTL forward on each active request. `POST
  /demo-login` is rate-limited **10 per hour by IP** (a SEPARATE limiter from `POST /login`), so the
  endpoint can't be spammed to mint unbounded demo rows.

- **The scheduled janitor (`demo:cleanup`).** A command force-deletes demo-tagged users (and any tagged
  rows) past the TTL, every 15 minutes, `withoutOverlapping()`, every delete guarded so it can NEVER touch a
  real row. Idempotent.

### 1.2 AUTH-04 — User CRUD (admin-manageable users)

An **administrator** manages users **within its own org** (org-confined); a **super_admin** manages users
**cross-org**. Full index/create/store/edit/update/destroy. The point — **get these right the FIRST time:**

- **The role gate + the org confinement (the §10.2 matrix).** Users CRUD is **administrator+** only
  (manager/editor → 403, the existing `role:administrator` rung). An **administrator is org-confined**: it
  lists/creates/edits/deletes ONLY users whose `organization_id` matches its own; a created user's
  `organization_id` is **stamped server-side from context** (never the payload — the slice-003/004
  tenant-write rule). A **super_admin is cross-org**: it lists all users and may set any `organization_id`.
  **Caveat (Deviation A):** the `User` model carries NO `OrganizationScope` (the login lookup runs before
  context), so user-list confinement is an **explicit** `where('organization_id', …)` in the read path
  driven by the acting user's role — NOT the global scope. This is the one place demo/org isolation is
  hand-rolled, exactly as the slice-001 User-model docblock predicted.

- **The super_admin SINGLETON swap (AUTH-04, the crown).** At most ONE super_admin exists at a time.
  Assigning super_admin to a user **unsets the previous super_admin** (demotes it to administrator) in **ONE
  `DB::transaction`** (a multi-row write → transaction by law). Falsifiable: after promoting user B to
  super_admin, the formerly-super_admin user A is now an administrator, and exactly one super_admin row
  exists.

- **A super_admin may edit only its OWN record (AUTH-04).** A super_admin updating a user OTHER than itself
  is forbidden (a 403 / graceful guard) — a super_admin manages others' lower roles via create/role-assign,
  but its own super_admin record is self-sovereign. Equivalently: nobody but the super_admin edits the
  super_admin row, and the super_admin edits no other super_admin (there is only one).

- **No self-elevation by an administrator (privilege-escalation firewall).** An administrator **cannot mint
  or promote to super_admin** (the role field is gated: an administrator may assign editor/manager/
  administrator within its org, NEVER super_admin). An administrator also cannot elevate ITS OWN role. A
  user cannot self-elevate. The role field on store/update is validated against the **roles the actor is
  allowed to assign** (server-side, in the Action/DTO context).

- **Graceful delete of a user who authored content (never a 500).** A user is referenced by
  `articles.author_id`, `job_postings.author_id`/`created_by`, etc. (RESTRICT FKs). Deleting such a user
  must NOT 500. Per SPEC §6.3.6 the users table has SoftDeletes (`deleted_at`); a **soft-delete** preserves
  the FK references (the author row still exists, just trashed) — so the destroy is always safe (no restrict
  violation). **DECISION (lock in plan.md, Decision G):** SoftDeletes on users vs. a reassign-then-delete.
  The user CANNOT delete its own record, and CANNOT delete the (sole) super_admin.

- **Password handled by the cast (never logged/exposed).** Create requires a confirmed password (validated,
  `confirmed`); the `password` cast (`hashed`) hashes it; it is in `$hidden`; it is NEVER returned in a prop
  or logged. Update leaves the password unchanged when the field is blank (optional on update).

---

## 2. In scope / out of scope

**In scope**
- `DemoPreset` enum (administrator/manager/editor only) + `DemoLoginData` DTO + `DemoSessionResult` VO.
- `App\Support\DemoContext` (per-request demo tag) — minimal; the CMS reuses `OrganizationScope` for data
  confinement, so `DemoContext` only carries the demo session-id for the cleanup-tagging path (Decision D).
- `DemoSessionMiddleware` (`demo` alias): TTL expiry + slide + destructive-route block + (optional) demo tag
  publish; prepended BEFORE `SubstituteBindings`, AFTER `EnsureRole`/`org.scope`.
- `ProvisionDemoSessionAction` + `DemoCleanupAction` + `DemoCleanupCommand` (`demo:cleanup`) + the schedule.
- `Auth\DemoLoginController` (`create` shows the chooser ON the existing login page OR a `DemoChooser` page —
  Decision E; `store` provisions + logs in + lands).
- The `demo-login` `RateLimiter` (10/hr by IP) in `AppServiceProvider`.
- `UserData` (create) + `UpdateUserData` (update) Spatie DTOs.
- `CreateUserAction` + `UpdateUserAction` (the singleton swap, no-self-elevation, org-stamp) +
  `DeleteUserAction` (soft-delete, self/last-super_admin guards), all transactional where multi-row.
- `Admin\UserController` (index/create/store/edit/update/destroy) + `/admin/users` resource route
  (`role:administrator`, `org.scope`).
- Migration: add to `users` the SPEC §6.3.6 columns missing from the slice-001 table — `name` nullable,
  `avatar_path`, `email_verified_at` (exists), `last_login_at`, `last_login_ip`, **SoftDeletes
  (`deleted_at`)**, and the demo-tag column **`demo_session_id`** (nullable, indexed) used by cleanup.
  Reversible. (`is_demo`, `organization_id`, indexes already exist.)
- The Inertia pages: demo chooser (or chooser block on Login), `Admin/Users/Index`, `Admin/Users/Create`,
  `Admin/Users/Edit`; a shared **demo banner** prop (`is_demo` + `demo_expires_at`).
- Lang keys (server `lang/{es,en}.json` + frontend `resources/locales/{es,en}.json`), bilingual.
- Wire `email` nullable + `username` unique into the DTO (SPEC §6.3.6: email UNIQUE NULLABLE).

**Out of scope / DEFERRED (note in plan.md)**
- **Login brute-force throttle** (`POST /login` 5/15min, §10.4) — slice-001 gate decision C, STILL deferred;
  this slice adds ONLY the demo-login limiter.
- **Avatar upload** — the `avatar_path` column ships for schema-completeness; the upload UI/Action is
  DEFERRED (Decision F). The user form does not upload an avatar this slice.
- **`last_login_at`/`last_login_ip` population** — columns ship; wiring the stamp into `AuthenticateUserAction`
  / `LoginController@store` is a SMALL add (Decision H: do it here as a 2-line stamp, or DEFER). Lock in plan.
- **Password reset / email verification flows** — not in SPEC scope for this slice.
- **A separate demo user-record per session vs. a shared demo persona** — Decision A (locked in plan.md):
  per-session **ephemeral demo users** (mirroring UNIGES `User::factory()->demo($tag)`), pruned by cleanup.

---

## 3. Acceptance scenarios (When… Then…)

### 3.1 Demo login (AUTH-02)

1. **Lands on the right dashboard.** *When* a visitor `POST /demo-login` with `preset=administrator` (or
   `manager` / `editor`), *then* a demo user is provisioned, logged in, the session carries `is_demo=true` +
   `demo_expires_at` ≈ now+30min, and they are redirected to `admin.dashboard` (via `RoleLandingRoute`) →
   **302**, authenticated.
2. **`is_demo` flag set.** *When* a demo session is active, *then* `session('is_demo') === true` and the
   shared Inertia `demo` prop exposes `{ is_demo: true, expires_at: <ts> }` (snake_case) — the banner shows.
3. **TTL expiry.** *When* a demo session makes a request after `demo_expires_at` has passed, *then* the demo
   middleware logs it out + invalidates + redirects to `login` with a flash — **302**, no longer authed.
4. **TTL slide.** *When* a demo session makes a request BEFORE expiry, *then* `demo_expires_at` is pushed
   forward to now+30min (the sandbox stays alive while in use).
5. **Rate-limit 10/hr → graceful.** *When* the 11th `POST /demo-login` from the same IP within an hour
   arrives, *then* it is rejected with a **302 back + a `preset` session error** (the web convention, NOT a
   429 JSON body), no demo user minted.
6. **No super_admin preset (structural).** *When* any `POST /demo-login` is sent with `preset=super_admin`
   (or any value not in {administrator, manager, editor}), *then* the Spatie enum-cast rejects it →
   **302 + a `preset` error**, no session — the persona is unmintable.
7. **Demo CANNOT reach a super_admin route.** *When* a demo administrator (the highest preset) requests
   `GET /admin/organizations` or `GET /admin/analytics` (super_admin-only) — wait: analytics is
   administrator+. **Precise:** *when* a demo administrator requests `GET /admin/organizations` (super_admin
   gate), *then* **403** (the `role:super_admin` rung; no demo preset can satisfy it).
8. **Demo BLOCKED from a destructive mutation.** *When* a demo session hits ANY destructive route in
   `DemoSessionMiddleware::DESTRUCTIVE_ROUTE_NAMES` (e.g. `DELETE /admin/articles/{a}`,
   `POST /admin/users`, `DELETE /admin/users/{u}`, `DELETE /admin/categories/{c}`,
   `DELETE /admin/municipalities/{m}`, `POST /admin/articles/{a}/publish` if listed), *then* a graceful
   **302 back + `demo.blocked` flash** and **NO row is mutated** (assert the target row is unchanged).
9. **Demo allowed a non-destructive read.** *When* a demo session hits `GET /admin/dashboard` or
   `GET /admin/articles`, *then* **200** (the sandbox is explorable).
10. **Cleanup prunes expired demo rows.** *When* `demo:cleanup` runs and a demo user's `created_at` (or
    `demo_expires_at`) is older than the 30-min TTL, *then* that demo user (and any demo-tagged rows) are
    force-deleted; a non-expired demo user and ALL real users/rows are untouched. Idempotent (a 2nd run
    deletes 0). Every delete is guarded `whereNotNull('demo_session_id')`.
11. **Logout clears demo (AUTH-05).** *When* a demo session `POST /logout`, *then* the session is
    invalidated and the demo flags are gone (the existing `LoginController@destroy` already invalidates).

### 3.2 User CRUD (AUTH-04)

12. **Admin creates a user (own-org happy path).** *When* an **administrator** `POST /admin/users` with a
    valid `{ username, name, email?, password, password_confirmation, role: editor|manager|administrator }`,
    *then* the user is created with `organization_id` **stamped from the actor's context** (NOT the payload),
    the password is hashed (never returned), → **302** + `users.created` flash.
13. **Super_admin creates cross-org.** *When* a **super_admin** `POST /admin/users` with an explicit
    `organization_id`, *then* the user is created in that org (cross-org allowed).
14. **Validation 302 (not 422).** *When* a `POST /admin/users` is missing a required field or password
    confirmation mismatches, *then* **302 + session errors** (web convention), no user created.
15. **Unique username/email.** *When* a `POST /admin/users` reuses an existing `username` (or non-null
    `email`), *then* **302 + a `username`/`email` error**, no duplicate (the §6.3.6 unique indexes).
16. **Role gate (manager/editor → 403).** *When* a **manager** or **editor** requests ANY `/admin/users*`
    route, *then* **403** (the `role:administrator` rung).
17. **The super_admin SINGLETON swap.** *When* a super_admin updates user B's role to `super_admin`, *then*
    in ONE transaction the previous super_admin (user A) is demoted to `administrator`, B becomes
    super_admin, and EXACTLY ONE super_admin row exists afterward.
18. **No self-elevation by an administrator.** *When* an **administrator** `POST`/`PUT`s a user with
    `role: super_admin`, *then* the role assignment is rejected → **302 + a `role` error** (an administrator
    may assign editor/manager/administrator only; super_admin is unassignable by a non-super_admin), no
    super_admin minted.
19. **Administrator org-confinement (READ).** *When* an **administrator** of org A requests
    `GET /admin/users`, *then* the list contains ONLY users with `organization_id = A` (no org-B user
    leaks). A super_admin sees all orgs' users.
20. **Administrator org-confinement (WRITE).** *When* an **administrator** of org A requests
    `GET /admin/users/{u}/edit` (or `PUT`/`DELETE`) for a user `u` of org B, *then* **404** (the user is
    out of the actor's org — unresolvable / not-found, never a 200 with another org's user, never a 500).
21. **Super_admin edits only its own record.** *When* a super_admin `PUT /admin/users/{u}` where `u` is a
    DIFFERENT super_admin — impossible (singleton) — OR where the update would change the super_admin's
    OWN-record rule: *when* a super_admin attempts to edit another user's record in a way reserved to
    self-sovereignty (precisely: a super_admin editing the super_admin role on a record that is not itself),
    *then* it is governed by the singleton swap (17) for promotion; **a super_admin may always edit lower-role
    users** (that is normal admin CRUD). The narrow guard: **a super_admin cannot demote/edit ITSELF out of
    super_admin via a normal update** except through reassignment — lock the exact phrasing in plan.md
    (Decision C). Test: a super_admin updating its OWN username/name/email succeeds (302); the singleton row
    count stays 1.
22. **Cannot delete self.** *When* an administrator or super_admin `DELETE`s its OWN user record, *then* a
    graceful guard → **302 + a flash error**, no deletion.
23. **Cannot delete the sole super_admin.** *When* a `DELETE` targets the only super_admin, *then* a
    graceful guard → **302 + flash**, no deletion (the system always has a super_admin).
24. **Graceful delete of a content author (never 500).** *When* a user who authored articles/jobs is
    deleted, *then* it is **soft-deleted** (`deleted_at` set; the author FK references stay valid) → **302 +
    `users.deleted`**, the authored content's `author_id` still resolves (no restrict-FK 500). Assert the
    article still exists and its author row is soft-deleted, not gone.

### 3.3 Cross-cutting

25. **Prop contracts.** Each Inertia page (`Auth/DemoChooser` or the Login chooser block; `Admin/Users/Index`,
    `Admin/Users/Create`, `Admin/Users/Edit`) ships a prop-contract test asserting the EXACT snake_case prop
    shape (§4). The shared `demo` prop is asserted for both a demo and a non-demo session.
26. **Lang resolution.** EVERY server `__()` key this slice introduces resolves in BOTH `lang/es.json` AND
    `lang/en.json` (a resolution test); every frontend `t()` key exists in BOTH `resources/locales/{es,en}.json`.
27. **Arch suite stays green.** `App\Domain\Identity` still `toOnlyUse` Shared/Illuminate/Spatie; all domain
    classes `final`; strict types everywhere; controllers anemic (no `Illuminate\Http\Request`, no `DB`
    facade); DTOs the only validation (no FormRequest). The new Identity classes (DemoPreset, DemoLoginData,
    DemoSessionResult, UserData, the Actions) conform.

---

## 4. Inertia prop contracts (snake_case — the falsifiable shapes)

> All keys snake_case (the project convention). Enum values are the backed string values; the generated TS
> enum is TYPES-ONLY (type-only import). NO password ever appears in any prop.

### 4.1 Demo chooser — `Auth/DemoChooser` (or a `demo` block injected into `Auth/Login`)
```
presets: Array<{
  value: string,            // DemoPreset->value: 'administrator' | 'manager' | 'editor'
  role: string,             // UserRole->value (same as value here)
  title_key: string,        // i18n key: 'demo.preset.<value>.title'
  description_key: string,  // i18n key: 'demo.preset.<value>.desc'
}>
```
- `value` is NEVER `super_admin` (the enum has no such case).
- NO `demo_session_id`, NO server token — server-only.

### 4.2 Shared `demo` prop (HandleInertiaRequests::share — every page)
```
demo: {
  is_demo: boolean,         // session('is_demo') === true
  expires_at: number|null,  // session('demo_expires_at') unix ts, or null for a real session
} | null                     // null when not a demo session (Decision: object-always vs null — lock in plan)
```
- Drives the demo banner ("You are exploring a demo — expires in N min"). Bilingual copy via `t()`.

### 4.3 `Admin/Users/Index`
```
users: Array<{
  id: number,
  username: string,
  name: string|null,
  email: string|null,
  role: string,                 // UserRole->value
  role_label_key: string,       // 'role.<value>'
  organization_id: number|null,
  organization_name: string|null,
  is_demo: boolean,
  last_login_at: string|null,   // ISO8601 or null (column ships; may be null if Decision H defers stamping)
  created_at: string,           // ISO8601
}>
can: {
  create: boolean,              // actor is administrator+ (always true on this gated route)
  assign_super_admin: boolean,  // actor is super_admin (drives whether the role select offers super_admin)
  manage_cross_org: boolean,    // actor is super_admin (drives the org column / org picker visibility)
}
filters: { organization_id: number|null }   // super_admin-only narrowing; null/absent for an administrator
```
- An administrator's `users` array is org-confined (only its org). NO password, NO remember_token.

### 4.4 `Admin/Users/Create` and `Admin/Users/Edit`
```
// Create
assignable_roles: Array<{ value: string, label_key: string }>   // actor-dependent: administrator → [editor,manager,administrator]; super_admin → [..,super_admin]
organizations: Array<{ id: number, name: string }> | null       // super_admin only (cross-org picker); null/absent for administrator (org is stamped from context)

// Edit (adds)
user: {
  id: number, username: string, name: string|null, email: string|null,
  role: string, organization_id: number|null, is_demo: boolean,
}
is_self: boolean            // the user being edited is the actor (drives the self-edit / no-self-demote rules)
is_only_super_admin: boolean // the edited user is the sole super_admin (drives the delete/demote guard UI)
```
- The role `<select>` offers ONLY `assignable_roles` (an administrator's select has NO super_admin option —
  the no-self-elevation rule is enforced server-side AND reflected in the prop, defence in depth).

---

## 5. Lang keys (server `lang/{es,en}.json` + frontend `resources/locales/{es,en}.json`)

> Every key in BOTH languages. **Server** keys (used in `__()` from Actions/Controllers/Middleware) go in
> `lang/`; **frontend** keys (used in `t()` from .tsx) go in `resources/locales/`. A resolution test covers both.

**Server (`lang/{es,en}.json`)**
- `demo.throttled` — demo-login rate-limit exceeded (shown as a `preset` error).
- `demo.expired` — demo session timed out (logout flash).
- `demo.blocked` — destructive action blocked in demo mode (flash).
- `users.created`, `users.updated`, `users.deleted` — success flashes.
- `users.error.cannot_delete_self` — self-delete guard.
- `users.error.cannot_delete_last_super_admin` — sole-super_admin delete guard.
- `users.error.cannot_assign_super_admin` — an administrator tried to assign super_admin (the `role` error).
- `users.error.cannot_edit_other_super_admin` / `users.error.super_admin_self_only` — the super_admin
  own-record rule (final key name locked in plan.md, Decision C).

**Frontend (`resources/locales/{es,en}.json`)**
- `demo.banner.active` — "You are exploring a demo." / "Estás explorando una demo."
- `demo.banner.expires_in` — "Expires in {minutes} min." (interpolated).
- `demo.cta.try` — the "Try a demo" button on the login page.
- `demo.preset.administrator.title` / `.desc`, `demo.preset.manager.title` / `.desc`,
  `demo.preset.editor.title` / `.desc` — the three chooser cards.
- `admin.users.title`, `admin.users.new`, `admin.users.edit_title`, the form field labels
  (`admin.users.field.username` / `.name` / `.email` / `.password` / `.password_confirmation` / `.role` /
  `.organization`), `admin.users.submit`, `admin.users.delete`, `admin.users.confirm_delete`,
  the table headers, and `admin.users.badge.demo`.

---

## 6. Decisions to lock in plan.md

- **Decision A — demo user lifecycle:** per-session **ephemeral demo users** (mirror UNIGES `factory->demo($tag)`),
  each tagged `demo_session_id` + `is_demo=true`, pruned by `demo:cleanup`. (Shared-persona rejected: a shared
  demo user mutating shared state breaks isolation across concurrent visitors.)
- **Decision B — demo isolation mechanism:** REUSE the existing `OrganizationScope` for data confinement (a
  demo manager/editor auto-confines to its demo org); the demo administrator is unconfined but blocked by the
  destructive-route list + the missing super_admin preset. Do NOT invent a CMS `DemoScope` over every model
  (UNIGES needed one; the CMS's org-scoping spine already does the work). `DemoContext` is minimal — it holds
  the `demo_session_id` only for the cleanup-tagging convenience (the demo user's OWN rows are found by the
  `demo_session_id` column, no global scope needed since users aren't org-scoped).
- **Decision C — the super_admin own-record / singleton wording:** finalize the exact rule + the lang key for
  "a super_admin edits only its own record" vs. "the singleton swap demotes the previous". Resolve the
  apparent tension (a super_admin DOES edit other users — they're lower roles; the rule restricts editing the
  super_admin ROLE/record itself).
- **Decision D — `DemoContext` + `demo_session_id`:** add the `demo_session_id` nullable indexed column to
  `users` so `DemoCleanupAction` can guard `whereNotNull('demo_session_id')` (mirroring UNIGES). Confirm no
  other table needs the tag this slice (only demo USERS are minted — no demo articles/jobs; the demo explores
  the shared seeded org's content read-only).
- **Decision E — demo entry UI:** a dedicated `Auth/DemoChooser` page (UNIGES shape) vs. a "Try a demo"
  block on the existing `Auth/Login` page. Lock the route shape: `GET /demo` (chooser) is OPTIONAL if the
  presets are injected into the Login page; `POST /demo-login` is required either way.
- **Decision F — avatar upload:** SHIP the `avatar_path` column, DEFER the upload UI/Action. Confirm.
- **Decision G — user delete strategy:** SoftDeletes on `users` (the `deleted_at` column) → destroy is always
  a soft-delete (FK-safe by construction). Confirm vs. a reassign-author-then-hard-delete. (SoftDeletes is the
  SPEC §6.3.6 schema + the simplest FK-safe path.)
- **Decision H — last_login stamping:** stamp `last_login_at`/`last_login_ip` in `LoginController@store` /
  `AuthenticateUserAction` now (2-line add) vs. DEFER (columns ship empty). Lock it.
- **Decision I — middleware ordering:** the `demo` alias is prepended BEFORE `SubstituteBindings` and runs
  AFTER `EnsureRole` + `EnsureOrganizationScope` (so a wrong-role demo user 403s before the demo block, and
  the org context is set). Confirm the exact prepend order against the slice-001 spine.

---

## 7. Test list (Pest — falsifiable; Feature for anything touching `__()`/DB, Unit for pure enum logic)

> Tests touching `__()` or the DB go in **Feature**, NEVER Unit (the lesson). The `DemoPreset` enum's pure
> maps (role(), isStudentPreset-equivalent) can be Unit.

**Demo login (Feature — `tests/Feature/Auth/DemoLoginTest.php`)**
- demo administrator/manager/editor each lands on `admin.dashboard` (302, authed, `is_demo` session true).
- the shared `demo` prop is `{is_demo:true, expires_at:…}` for a demo session, null/false for a real one.
- TTL expiry → after `demo_expires_at`, next request logs out + redirects to login (302, not authed).
- TTL slide → a request before expiry pushes `demo_expires_at` forward.
- rate-limit → the 11th `POST /demo-login`/IP/hour → 302 + `preset` error, no user minted (assert count).
- `preset=super_admin` (and any non-{administrator,manager,editor}) → 302 + `preset` error, no session.
- demo administrator → `GET /admin/organizations` (super_admin gate) → 403.
- demo session → each destructive route in `DESTRUCTIVE_ROUTE_NAMES` → 302 + `demo.blocked`, target unchanged
  (parametrized: article destroy, user store/destroy, category destroy, municipality destroy, publish).
- demo session → `GET /admin/dashboard` + `GET /admin/articles` → 200 (explorable).
- logout from a demo session clears `is_demo` (302, not authed).

**Demo cleanup (Feature — `tests/Feature/Auth/DemoCleanupTest.php`)**
- `demo:cleanup` force-deletes a demo user older than the TTL; a fresh demo user + ALL real users survive.
- idempotent: a 2nd run deletes 0.
- a real (non-demo) user with `demo_session_id IS NULL` is NEVER touched (the guard is falsifiable).

**DemoPreset enum (Unit — `tests/Unit/Identity/DemoPresetTest.php`)**
- `cases()` is exactly {administrator, manager, editor} — NO super_admin (the structural invariant).
- `role()` maps each preset to the matching UserRole; every preset's role level < SuperAdmin level.

**User CRUD (Feature — `tests/Feature/Identity/UserCrudTest.php`)**
- administrator creates an own-org user → 302 + `users.created`; `organization_id` == actor's org (stamped),
  password hashed (login with it works), no password in any prop/response.
- super_admin creates a cross-org user with an explicit `organization_id`.
- store validation: missing field / password-confirmation mismatch → 302 + errors, no user.
- duplicate username (and non-null email) → 302 + `username`/`email` error, no duplicate.
- manager and editor → every `/admin/users*` route → 403.
- the super_admin SINGLETON swap: promoting B to super_admin demotes A to administrator; exactly one
  super_admin row remains (assert count == 1).
- an administrator assigning `role: super_admin` → 302 + `role` error (`users.error.cannot_assign_super_admin`),
  no super_admin minted.
- an administrator cannot elevate its OWN role (302 + error).
- administrator org-confinement READ: `GET /admin/users` lists only own-org users; super_admin sees all.
- administrator org-confinement WRITE: edit/update/delete of an org-B user by an org-A administrator → 404.
- super_admin edits its OWN record (username/name) → 302; singleton count stays 1.
- cannot delete self → 302 + `users.error.cannot_delete_self`, user still present.
- cannot delete the sole super_admin → 302 + `users.error.cannot_delete_last_super_admin`.
- graceful delete of a content author → soft-delete (302 + `users.deleted`); the authored article still
  exists and resolves its (trashed) author — no 500.

**Prop contracts (Feature — `tests/Feature/Identity/UserPropContractTest.php` + `Auth/DemoPropContractTest.php`)**
- `Admin/Users/Index` props match §4.3 (keys present, snake_case, `can.*` reflect the actor's role,
  no password/remember_token leaks).
- `Admin/Users/Create` + `Edit` props match §4.4 (`assignable_roles` excludes super_admin for an
  administrator; `is_self`/`is_only_super_admin` present on Edit).
- demo chooser props match §4.1; the shared `demo` prop asserted for demo + non-demo.

**Lang resolution (Feature — `tests/Feature/Identity/IdentityPhase2LangTest.php`)**
- every server key in §5 resolves in es AND en (`__()` returns a non-key string in both locales).
- every frontend key in §5 exists in BOTH `resources/locales/{es,en}.json` (file-presence assertion).

**Arch (extend `tests/Architecture/…`)**
- the new Identity classes keep `App\Domain\Identity` within Shared/Illuminate/Spatie; all final; strict
  types; the `Admin\UserController` + `Auth\DemoLoginController` stay anemic (no `Request`/`DB` import);
  no FormRequest.
