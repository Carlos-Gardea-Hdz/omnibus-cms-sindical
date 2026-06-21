# Plan 008 — Identity Phase 2 (Demo Login + User CRUD) — the HOW

> **Phase:** Plan. Turns `spec.md` into architecture + the exact files to touch.
> **Gated on:** CLAUDE.md §No-negociables + SPEC §6.3.6/§6.4 (users schema + FK RESTRICT) + §10.2/§10.4
> (RBAC matrix + rate limits) + §11.2 (arch boundary). MIRRORS the UNIGES demo slice 006 1:1.
> **Build order:** migration (users columns) → `DemoPreset` enum → `DemoLoginData`/`UserData`/`UpdateUserData`
> DTOs + `DemoSessionResult` VO → `DemoContext` → `ProvisionDemoSessionAction` + `DemoCleanupAction` +
> `DemoCleanupCommand` + schedule → `CreateUserAction`/`UpdateUserAction`/`DeleteUserAction` →
> `DemoSessionMiddleware` (+ alias + priority) → `demo-login` RateLimiter → `Auth\DemoLoginController` +
> `Admin\UserController` → routes → `User` model + `UserFactory` demo state → shared `demo` prop →
> Inertia pages → lang → tests (the singleton swap, the org-confinement crown, the demo-block, cleanup LAST).
> Backend before frontend. The two Actions families (demo provisioning + user CRUD) are the spine.

## 1. Architecture overview

```
HTTP (Inertia)                                   Domain (Illuminate\Http-free) + App\Support
─────────────                                    ───────────────────────────────────────────
Auth\DemoLoginController@create  ──render──►     (presets = DemoPreset::cases() mapped — server-only token never sent)
Auth\DemoLoginController@store   ──provision─►   Domain\Identity\Actions\ProvisionDemoSessionAction
  (throttle:demo-login; HTTP login;                (DB::transaction: mint a tagged demo User w/ preset role
   session is_demo/expires_at; RoleLandingRoute)    + demo_session_id + a shared seeded demo org → DemoSessionResult VO)
                                                 Domain\Identity\ValueObjects\DemoSessionResult (server-only, NOT #[TypeScript])
Admin\UserController@index/create/edit ─read─►   (org-confined read driven by actor role — EXPLICIT where, NOT a global scope)
Admin\UserController@store       ──create──►     Domain\Identity\Actions\CreateUserAction
Admin\UserController@update      ──update──►     Domain\Identity\Actions\UpdateUserAction  (super_admin singleton swap: DB::transaction)
Admin\UserController@destroy     ──delete──►     Domain\Identity\Actions\DeleteUserAction  (soft-delete; self / last-super_admin guards)
                                                 Domain\Identity\Enums\DemoPreset (administrator/manager/editor — NEVER super_admin)
                                                 Domain\Identity\Data\{DemoLoginData, UserData, UpdateUserData} (#[TypeScript], Spatie SSOT)
                                                 App\Support\DemoContext (per-request demo tag holder — minimal)
                                                 App\Support\OrganizationContext / OrganizationScope (slice 003 — REUSED for demo + user confinement)
                                                 REUSED: UserRole, RoleLandingRoute, AuthenticateUserAction, User model + UserFactory
HTTP middleware: DemoSessionMiddleware ('demo' alias, prepended BEFORE SubstituteBindings, AFTER EnsureRole/org.scope)
Console: DemoCleanupCommand ('demo:cleanup') ──► DemoCleanupAction (force-delete tagged rows past TTL; guarded whereNotNull)
Providers: AppServiceProvider — RateLimiter::for('demo-login') + bind DemoContext singleton
```

**Rule compliance:** Actions return a User / VO / void and NEVER import `Illuminate\Http` (Auth/Session
facades are used ONLY in the controllers/middleware, never the Action — exactly as `AuthenticateUserAction`
does). Multi-row writes (`ProvisionDemoSessionAction`, the super_admin singleton swap in `UpdateUserAction`,
`DemoCleanupAction`) wrap in `DB::transaction`. Controllers anemic (<=15 lines): DTO → Action → redirect/
render; NO `Illuminate\Http\Request` import, NO `DB` facade (arch test). DTOs are the ONLY validation (no
FormRequest). `App\Domain\Identity` stays within Shared/Illuminate/Spatie (the import of `UserRole` and the
`User` model from within the Identity Actions is fine — `App\Models\User` is allowed by the existing slice-001
Identity Actions which already import it). `DemoContext`/`OrganizationScope` live under `App\Support`
(domain-neutral) so models don't import the Identity domain.

### KEY DIFFERENCES vs the UNIGES demo slice (do NOT blindly copy)

- **REUSE `OrganizationScope`, do NOT add a CMS `DemoScope`** (spec Decision B). UNIGES minted a whole
  `DemoScope` over Student/Document/Jury because nothing else isolated demo data. The CMS already org-scopes
  every content model via slice 003 — a demo manager/editor is confined by the EXISTING `org.scope` spine.
  `DemoContext` therefore only holds `demo_session_id` (for cleanup tagging), NOT a scope driver.
- **Demo users are the ONLY demo-tagged rows** (spec Decision D). UNIGES seeded tagged students/documents/
  jury per session; the CMS demo explores the **shared seeded org's** existing content READ-ONLY (every
  write route is blocked), so only the demo USER row is ephemeral. `DemoCleanupAction` prunes only `users`.
- **User-list confinement is an EXPLICIT `where`, not a global scope** (Deviation A). The `User` auth model
  carries no `OrganizationScope` (the login lookup predates context). So `UserController@index` filters by
  `organization_id` explicitly when the actor is an administrator (unconfined query for super_admin). The
  edit/update/delete confinement is an explicit scoped lookup → 404 for a cross-org target.
- **The destructive-route block uses ROUTE NAMES** (mirror UNIGES `DESTRUCTIVE_ROUTE_NAMES`), enumerating
  the CMS's `*.destroy` + super_admin-write + shared-catalog-write names (§3 below).
- **No `confirmed` Spatie attribute pitfall:** Spatie Data's `Confirmed` maps to Laravel's `confirmed` rule
  which expects `password_confirmation` in the payload — ensure the create DTO carries both or uses the rule.

## 2. Data model & migrations (reversible, dependency-safe)

> **ID type:** `users.id` is **bigint** (`$table->id()`, slice-001/003). Demo + user rows are bigint-keyed.

### 2a. `users` column completion — `database/migrations/2026_06_20_0008XX_complete_users_table.php`

The slice-001 `create_users_table` shipped: `id, username(60) unique, name, email unique, email_verified_at,
password, role(20), organization_id (nullable; FK+indexes added slice 003), is_demo (default false),
remember_token, timestamps`. SPEC §6.3.6 additionally requires: `name` NULLABLE (currently NOT NULL), `email`
NULLABLE (currently NOT NULL unique — SPEC says UNIQUE NULLABLE), `avatar_path`, `last_login_at`,
`last_login_ip(45)`, `deleted_at` (SoftDeletes). Plus the demo tag `demo_session_id`.

`up()` (all additive + reversible):
- `name` → make nullable (`->nullable()->change()`).
- `email` → make nullable (`->nullable()->change()`) — keep the unique index (Postgres allows multiple NULLs
  under a UNIQUE index, so a null-email user is fine). Verify the existing unique index survives `change()`;
  if `change()` drops it, re-add explicitly.
- `avatar_path` VARCHAR(255) nullable.
- `last_login_at` TIMESTAMP nullable.
- `last_login_ip` VARCHAR(45) nullable.
- `demo_session_id` VARCHAR(36) nullable + index (the cleanup guard column; UUIDv7 string).
- `deleted_at` SoftDeletes (`$table->softDeletes()`).

`down()`: drop `avatar_path, last_login_at, last_login_ip, demo_session_id` (+ its index), drop softDeletes,
revert `name`/`email` to NOT NULL (guard: only if safe). NEVER touch `is_demo`/`organization_id`/`role`
(prior slices own them).

> **Migration caveat (lock):** `->change()` on Postgres needs `doctrine/dbal` OR Laravel 11+'s native change
> support — confirm the project's Laravel 12 native `change()` works for nullable toggles. If a NOT-NULL→
> nullable `change()` is risky on the shared DB, prefer leaving `name`/`email` NOT NULL and making the DTO
> always supply them (email defaulting is not allowed by SPEC — email is optional, so `name`/`email` SHOULD
> be nullable; resolve in implementation, do not run the migration on the shared DB).

### 2b. No new tables. No other table gets `demo_session_id` (only `users` is demo-tagged — Decision D).

## 3. The destructive-route block — `DemoSessionMiddleware::DESTRUCTIVE_ROUTE_NAMES`

Enumerate (defence in depth — most are already gated above a demo preset's reach, but listed so the block
fires even if a gate ever widened). Confirm each name against `routes/web.php`:

```
// Content (editor+ — a demo editor/manager/admin CAN reach these; MUST be blocked)
'admin.articles.destroy',
'admin.categories.destroy',        // category is a SHARED catalog (all-org) — a demo write escapes the org sandbox
'admin.jobs.destroy',
// Content publish/archive (manager+) — irreversible state change on shared content
'admin.articles.publish',
'admin.articles.archive',
// Organization (manager+/admin+/super_admin) — shared/tenant records
'admin.branches.destroy',
'admin.representatives.destroy',
'admin.directors.destroy',
'admin.municipalities.destroy',    // SHARED catalog
'admin.organizations.destroy',     // super_admin (unreachable by a demo preset, but listed)
'admin.organizations.store', 'admin.organizations.update',
// Membership review (manager+) — mutates real member status
'admin.members.approve', 'admin.members.reject',
// Users (administrator+) — THIS slice; a demo admin must never mint/edit/delete a user
'admin.users.store', 'admin.users.update', 'admin.users.destroy',
```
> **NOT blocked** (the sandbox must feel alive): all GET index/create/edit pages, and own-org create/update
> of demo-scoped content IF the team wants a writeable demo — **Decision (lock):** for v1 keep the demo
> READ-ONLY by ALSO blocking the create/update of articles/jobs/branches/representatives (add their `.store`/
> `.update` names) OR allow own-org create/update and rely on `org.scope` + cleanup. **Recommendation:** start
> READ-ONLY (block all `.store/.update/.destroy/.approve/.reject/.publish/.archive`), simplest + safest; a
> writeable demo is a future enhancement. Lock the exact final list in implementation.

## 4. Files to touch / create

**Migration**
- `database/migrations/2026_06_20_0008XX_complete_users_table.php` (§2a).

**Domain — Identity**
- `app/Domain/Identity/Enums/DemoPreset.php` — `enum DemoPreset: string { Administrator='administrator';
  Manager='manager'; Editor='editor'; }` with `role(): UserRole`, `displayName(): string`,
  `labelKey()`/`descriptionKey()`. NEVER a super_admin case. `#[TypeScript]`.
- `app/Domain/Identity/Data/DemoLoginData.php` — `public DemoPreset $preset;` (enum-cast SSOT). `#[TypeScript]`.
- `app/Domain/Identity/Data/UserData.php` (create) — `username` (Required, Max 60, unique), `name`
  (nullable, Max 100), `email` (nullable, Email, Max 255, unique), `password` (Required, Min 8, Confirmed),
  `role` (UserRole — validated against assignable set in the Action, not the DTO, since it's actor-dependent),
  `organization_id` (nullable int — used ONLY by a super_admin; ignored/overwritten for an administrator).
  `#[TypeScript]`.
- `app/Domain/Identity/Data/UpdateUserData.php` — same minus `password` Required (password optional/nullable
  on update; when present, `confirmed`); username/email unique-ignoring-self. `#[TypeScript]`.
- `app/Domain/Identity/ValueObjects/DemoSessionResult.php` — `final readonly` carrying `User $user`, `string
  $demoSessionId`, `DemoPreset $preset`. NOT `#[TypeScript]` (server-only; the token must never reach the client).
- `app/Domain/Identity/Actions/ProvisionDemoSessionAction.php` — `handle(DemoLoginData): DemoSessionResult`.
  One `DB::transaction`: mint `User::factory()->demo($tag)->state(['role'=>$preset->role(),
  'organization_id'=>$demoOrgId])->create()` where `$demoOrgId` is the shared seeded showcase org (resolve it
  — a seeded "Demo Organization", or the first org; lock in implementation). Sets `DemoContext`. Returns VO.
- `app/Domain/Identity/Actions/DemoCleanupAction.php` — `handle(?CarbonInterface $now=null): int`. Cutoff =
  now−30min. Force-delete `User::query()->whereNotNull('demo_session_id')->where('created_at','<',$cutoff)
  ->forceDelete()` (users have SoftDeletes now → `forceDelete`). Guarded, idempotent, transactional.
- `app/Domain/Identity/Actions/CreateUserAction.php` — `handle(UserData $data, User $actor): User`. Validate
  the `role` against the actor's assignable set (administrator ⇒ {editor,manager,administrator};
  super_admin ⇒ {…,super_admin}) → throw a guarded exception (graceful 302 + `role` error) on violation.
  Stamp `organization_id` from `$actor->organization_id` when the actor is an administrator (NEVER the
  payload); allow the payload org for a super_admin. If the assigned role is super_admin → run the SINGLETON
  swap inside the SAME `DB::transaction` (demote the existing super_admin to administrator, then create).
- `app/Domain/Identity/Actions/UpdateUserAction.php` — `handle(UpdateUserData $data, User $target, User
  $actor): User`. Guards: an administrator may only target an own-org user (the controller already 404s a
  cross-org target via the scoped lookup, but re-assert); no assigning super_admin by a non-super_admin; no
  self-elevation; the super_admin own-record rule (Decision C). The singleton swap when promoting to
  super_admin (DB::transaction). Password updated only when present.
- `app/Domain/Identity/Actions/DeleteUserAction.php` — `handle(User $target, User $actor): void`. Guards:
  `$target->is($actor)` → `CannotDeleteSelfException`; `$target` is the sole super_admin →
  `CannotDeleteLastSuperAdminException`. Otherwise soft-delete (`$target->delete()`). No FK-restrict risk
  because soft-delete keeps the author row.
- `app/Domain/Identity/Exceptions/` — small final exceptions for the user guards
  (`CannotAssignSuperAdminException`, `CannotDeleteSelfException`, `CannotDeleteLastSuperAdminException`,
  `SuperAdminSelfEditException`) — rendered as graceful 302 + field/flash in `bootstrap/app.php` (mirror the
  existing Organization/Content exception renders). **Or** raise `ValidationException::withMessages` for the
  field-keyed ones (role) and a flash-redirect for the delete guards — lock the mechanism in implementation
  (the existing slice pattern is a domain exception + a `bootstrap/app.php` render; follow it).

**App\Support**
- `app/Support/DemoContext.php` — minimal per-request holder: `set(?string $sessionId)` / `sessionId()`
  (mirror UNIGES, but it does NOT drive a scope here — only carried for symmetry/cleanup tagging). Bound
  singleton in `AppServiceProvider`.

**HTTP**
- `app/Http/Middleware/DemoSessionMiddleware.php` — mirror UNIGES: no-op for a real session; for a demo
  session: TTL expiry (logout+invalidate+token+redirect `login` with `demo.expired`), publish the tag into
  `DemoContext`, BLOCK `DESTRUCTIVE_ROUTE_NAMES` (302 back + `demo.blocked`), slide the TTL. Aliased `demo`,
  prepended BEFORE `SubstituteBindings` (after `EnsureRole`/`EnsureOrganizationScope`).
- `app/Http/Controllers/Auth/DemoLoginController.php` — `create()` renders the chooser (Decision E); `store
  (DemoLoginData, ProvisionDemoSessionAction)` calls the Action, `Auth::guard('web')->login($result->user)`,
  puts `is_demo`/`demo_session_id`/`demo_preset`/`demo_expires_at` into the session, `regenerate()`, redirect
  `RoleLandingRoute::for($result->preset->role())`.
- `app/Http/Controllers/Admin/UserController.php` — anemic: `index` (org-confined read driven by actor role
  → `Admin/Users/Index` props §4.3), `create` (`assignable_roles` + `organizations?` → §4.4), `store(UserData,
  CreateUserAction)`, `edit(User)` (scoped-bound → 404 cross-org; props §4.4), `update(UpdateUserData, User,
  UpdateUserAction)`, `destroy(User, DeleteUserAction)`. Each ≤15 lines, no `Request`, no `DB`.

**Providers**
- `app/Providers/AppServiceProvider.php` — `$this->app->singleton(DemoContext::class);` and
  `registerDemoLoginRateLimiter()` (`RateLimiter::for('demo-login', Limit::perHour(10)->by($request->ip())
  ->response(fn () => back()->withErrors(['preset' => __('demo.throttled')])))`). Mirror UNIGES.

**Routes**
- `routes/web.php`:
  - In the `guest` group (or alongside login): `GET /demo` → `DemoLoginController@create` (`demo.create`) IF
    a dedicated chooser page (Decision E).
  - `POST /demo-login` → `DemoLoginController@store`, `->middleware('throttle:demo-login')`, `->name
    ('demo.store')`. Anonymous (NOT in `guest`? — it can be guest-gated so an authed user can't re-demo;
    mirror UNIGES which keeps it open up to the IP cap — lock in implementation).
  - The admin shell groups gain the `'demo'` alias so a demo session's destructive routes are blocked: add
    `'demo'` to the `['auth','role:…','org.scope']` group middleware (or apply `demo` globally to the
    authenticated web group). **Recommendation:** add `'demo'` to EACH admin group's middleware array (after
    `auth`, before/with `role`), so the block + TTL apply to every admin route. Lock placement.
  - `Route::resource('admin/users', UserController::class)->except(['show'])->names('admin.users')` inside a
    `['auth','role:administrator','org.scope','demo']` group (§7.4 — administrator+; super_admin cross-org via
    the explicit read, administrator org-confined). Bind `{user}` as a SCOPED/explicit lookup so a cross-org
    target 404s for an administrator.

**bootstrap/app.php**
- Alias `'demo' => DemoSessionMiddleware::class`. Prepend `DemoSessionMiddleware` before `SubstituteBindings`
  (a 3rd `prependToPriorityList` after the existing `EnsureRole`/`EnsureOrganizationScope` prepends — order:
  EnsureRole, EnsureOrganizationScope, DemoSessionMiddleware all before SubstituteBindings; confirm relative
  order so role 403 fires before the demo block).
- Register the new Identity exception renders (graceful 302 + field error / flash), mirroring the existing
  Organization/Content renders — IF the domain-exception mechanism is chosen over `ValidationException`.

**Console**
- `app/Console/Commands/DemoCleanupCommand.php` (`demo:cleanup`, thin wrapper over `DemoCleanupAction`).
- `routes/console.php` — `Schedule::command('demo:cleanup')->everyFifteenMinutes()->withoutOverlapping();`.

**Models / Factories**
- `app/Models/User.php` — add `SoftDeletes` trait; add `demo_session_id`/`avatar_path`/`last_login_at`/
  `last_login_ip` to `$fillable` (and `@property` PHPDoc); cast `last_login_at` datetime, `deleted_at`
  datetime. Confirm NO OrganizationScope added (Deviation A holds — the login lookup must not be scoped).
- `database/factories/UserFactory.php` — add a `demo(string $sessionId): static` state (`demo_session_id`,
  `is_demo=true`, a deterministic obviously-fake `email` like `demo+{tag}@cms.demo`, a fixed `name`). Mirror
  UNIGES.

**Frontend (Inertia / React / TS)**
- `resources/js/Pages/Auth/DemoChooser.tsx` (or a chooser block in `Auth/Login.tsx`) — the 3 preset cards;
  magenta theme, dark/light, bilingual `t()`. Posts `preset` to `demo.store`.
- `resources/js/Pages/Admin/Users/Index.tsx`, `Create.tsx`, `Edit.tsx` — the list + forms; the role select
  offers ONLY `assignable_roles`; the org picker shows ONLY for a super_admin; the delete button respects
  `is_self`/`is_only_super_admin`.
- `resources/js/Components/DemoBanner.tsx` (or in the admin layout) — reads the shared `demo` prop; shows
  "exploring a demo — expires in N min". Bilingual.
- `app/Http/Middleware/HandleInertiaRequests.php` — add the shared `demo` prop (§4.2) from the session.
- Regenerate TS types (`php artisan typescript:transform`) — DemoPreset, the DTOs become type-only imports.

**Lang**
- `lang/es.json` + `lang/en.json` — the §5 server keys.
- `resources/locales/es.json` + `resources/locales/en.json` — the §5 frontend keys.

**Tests** — per spec §7. Feature for `__()`/DB; Unit for the `DemoPreset` pure maps. Extend the arch suite.

## 5. Decisions — RESOLVED here (the spec's open items)

- **A (demo lifecycle):** per-session ephemeral demo users. ✔
- **B (isolation):** REUSE `OrganizationScope`; no CMS `DemoScope`. ✔
- **C (super_admin own-record):** Rule = "a non-super_admin can never assign super_admin; the super_admin
  role moves only via the singleton swap; a super_admin may edit any LOWER-role user freely, but the
  super_admin ROW (the sole super_admin) is edited only by itself, and a super_admin cannot demote itself via
  a plain update (demotion happens only as a side-effect of promoting someone else)." Lang key:
  `users.error.super_admin_self_only`. Self username/name/email edits by the super_admin succeed.
- **D (`demo_session_id`):** add the column to `users` ONLY; cleanup guards on it. ✔
- **E (demo UI):** dedicated `Auth/DemoChooser` page at `GET /demo` + a "Try a demo" CTA on `Auth/Login`
  linking to it. (Cleaner than overloading the login props.) `POST /demo-login` does the work.
- **F (avatar):** ship `avatar_path` column; DEFER upload UI/Action. ✔
- **G (delete):** SoftDeletes on `users`; destroy = soft-delete (FK-safe). ✔
- **H (last_login):** stamp `last_login_at`/`last_login_ip` in `LoginController@store` (a 2-line add via the
  authenticated user) AND in `DemoLoginController@store` — small, do it here so the Index prop is real.
- **I (ordering):** `demo` prepended before `SubstituteBindings`, sequenced AFTER EnsureRole +
  EnsureOrganizationScope (a wrong-role demo user 403s before the demo block; the org context is set). ✔

## 6. Risks & mitigations

- **`->change()` on the shared Postgres DB** (nullable toggles) — do NOT run migrations on the shared DB
  (the lesson). If native `change()` is unsafe, keep `name`/`email` NOT NULL and require them in the DTO
  (but SPEC says email is optional → prefer nullable; resolve before merge, never by running on shared DB).
- **The singleton swap race** — the demote-previous + create/promote MUST be one `DB::transaction` (already
  specified); a unique partial index `WHERE role='super_admin'` would be belt-and-suspenders but is NOT in
  SPEC §6.3.6 — DEFER the DB-level uniqueness, enforce in the Action (note it).
- **Demo administrator is UNCONFINED** — it can READ cross-org content (it's level 3). That's acceptable for
  a showcase (it sees the demo/showcase data); the WRITE block + the missing super_admin preset keep it from
  mutating or reaching tenant administration. If reading other orgs' data is undesirable, pin the demo
  administrator to the single showcase org and seed only that org's content — lock in implementation.
- **Email-null unique** — Postgres allows multiple NULLs under UNIQUE; confirm the unique index survives the
  nullable `change()` (re-add if dropped).
- **No password leak** — `password` stays in `$hidden`; NO prop/response ever carries it; the Index/Edit
  props (§4.3/§4.4) explicitly exclude it; a prop-contract test asserts its absence.

## 7. Quality gates (read-only here — do NOT run pest/migrate/build on the shared DB)

`php artisan typescript:transform` (regen types) · `composer analyse` (PHPStan L10, read-only) · `tsc`
(read-only). Pest + migrate are run by the human/CI against a non-shared DB. Arch suite must stay green.
