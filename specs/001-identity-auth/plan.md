# Plan 001 — Identity Domain: Auth Foundation (the HOW)

> **Phase:** Plan. Turns `spec.md` into architecture + the exact files to touch.
> **Gated on:** CLAUDE.md §No-negociables + SPEC §5.2/§11.2 arch rules.
> **Build order:** schema/enum/model → DTO/Action/support → controllers/routes/middleware
> → pages/i18n → tests. Backend before frontend; tests last (or alongside).

## 1. Architecture overview

```
HTTP (Inertia)                         Domain (Illuminate\Http-free)
─────────────                          ─────────────────────────────
Auth/LoginController                   Domain\Identity\Data\LoginData (Spatie Data DTO)
  create()  → Inertia 'Auth/Login'     Domain\Identity\Actions\AuthenticateUserAction
  store(LoginData, Action)             Domain\Identity\Enums\UserRole (#[TypeScript], ladder)
    → Action->handle() → User
    → session()->regenerate()          app/Models/User (Eloquent base; cross-domain-referenced)
    → redirect(RoleLandingRoute::for)
  destroy() → logout+invalidate
Admin/DashboardController
  index() → Inertia 'Admin/Dashboard'

Http\Support\RoleLandingRoute          (UserRole → route name, SSOT shared real+demo)
Http\Middleware\EnsureRole (alias role) (level-based gate: user.role.hasAtLeast(min))
```

**Rule compliance:** the Action returns a `User`, throws `ValidationException` keyed on
`username`; it never touches `Illuminate\Http`. The controller is anemic (DTO→Action→
response). The DTO is the only validation mechanism. The enum owns all role logic. The
gate lives in HTTP middleware, never the domain.

## 2. Data model & migration (reversible)

The default `users` table is fresh/unseeded → **edit the create migration**
(`database/migrations/0001_01_01_000000_create_users_table.php`), single source of truth.
Add to the `users` blueprint (keep `email` — Laravel auth scaffolding + later notifications
use it; the *login key* is `username`):

```php
$table->id();
$table->string('username', 60)->unique();          // NEW — the login key
$table->string('name');
$table->string('email')->unique();
$table->timestamp('email_verified_at')->nullable();
$table->string('password');
$table->string('role', 20)->default(UserRole::Editor->value);  // NEW — 4-level RBAC
$table->foreignId('organization_id')->nullable();  // NEW — DEFERRED (Organization domain; no FK target yet)
$table->boolean('is_demo')->default(false);         // NEW — DEFERRED (demo slice)
$table->rememberToken();
$table->timestamps();
```

Notes:
- Import `use App\Domain\Identity\Enums\UserRole;` at the top of the migration for the
  default; or hardcode `'editor'` with a `// UserRole::Editor` comment if you prefer the
  migration import-free. **Decision: use the enum** for a single source of the default.
- `role` stored as `string(20)` (the enum's backing values are ≤13 chars). A DB CHECK
  constraint on the allowed values is **optional**; the Eloquent cast + arch already
  guard it. Skip the CHECK this slice to keep the migration portable.
- `organization_id` is nullable with **no** `->constrained()` yet (the `organizations`
  table does not exist) — the FK + cascade rule lands with the Organization slice.
- `down()` already does `Schema::dropIfExists('users')` → fully reversible, no change
  needed. (Verify the migration still runs after editing.)

## 3. Files to create / touch

### Domain — Identity

| Path | Action | Notes |
|------|--------|-------|
| `app/Domain/Identity/Enums/UserRole.php` | create | backed enum, `#[TypeScript]`, ladder |
| `app/Domain/Identity/Data/LoginData.php` | create | Spatie Data, `#[TypeScript]`, `final` |
| `app/Domain/Identity/Actions/AuthenticateUserAction.php` | create | `final`, no `Illuminate\Http` |

### Models / factories / migration

| Path | Action | Notes |
|------|--------|-------|
| `app/Models/User.php` | edit | cast `role`, `$fillable` += username/role/is_demo, PHPDoc |
| `database/factories/UserFactory.php` | edit | username/role defaults + per-role states |
| `database/migrations/0001_01_01_000000_create_users_table.php` | edit | add columns (§2) |

### HTTP

| Path | Action | Notes |
|------|--------|-------|
| `app/Http/Support/RoleLandingRoute.php` | create | `for(UserRole): string` SSOT |
| `app/Http/Middleware/EnsureRole.php` | create | alias `role`, level-based |
| `app/Http/Controllers/Auth/LoginController.php` | create | anemic, create/store/destroy |
| `app/Http/Controllers/Admin/DashboardController.php` | create | anemic, index() shell |
| `bootstrap/app.php` | edit | register `role` alias; optional `throttle:login` |
| `routes/web.php` | edit | login/logout/dashboard routes |

### Frontend / i18n

| Path | Action | Notes |
|------|--------|-------|
| `resources/js/Pages/Auth/Login.tsx` | create | username+password+remember, magenta theme |
| `resources/js/Pages/Admin/Dashboard.tsx` | create | authed shell (greeting+role+logout) |
| `lang/es.json` | create | `auth.*` + `role.*` keys |
| `lang/en.json` | create | `auth.*` + `role.*` keys |
| `resources/js/types/generated.d.ts` | regenerate | via `typescript:transform` (do NOT hand-edit) |

### Tests

| Path | Action | Notes |
|------|--------|-------|
| `tests/Unit/Identity/UserRoleTest.php` | create | enum ladder/level/color (no DB) |
| `tests/Feature/Auth/LoginTest.php` | create | happy/wrong/unknown/malformed/logout |
| `tests/Feature/Auth/RoleGateMatrixTest.php` | create | 4×4 matrix + guest 302 |
| `tests/Feature/Auth/AuthPropsTest.php` | create | login + dashboard prop contracts |
| `tests/Feature/Auth/AuthLangKeyTest.php` | create | `auth.failed` + `role.*` es/en |
| `resources/js/Pages/Auth/__tests__/Login.test.tsx` | create | Vitest render + error surface |
| `tests/Arch/ArchitectureTest.php` | edit | add Identity-only-uses-Shared |

## 4. Key design decisions

- **`User` stays in `app/Models`**, not `app/Domain/Identity/Models` — it is the Eloquent
  base model referenced cross-domain (SPEC §5.2 Rule 3: models may be cross-referenced).
  The SPEC §5.4 tree lists `Domain/Identity/Models/User.php` aspirationally; the foundation
  already ships `app/Models/User.php` and the arch suite expects domain classes to be
  final — keeping the framework `User` (extends `Authenticatable`, must stay non-final-ish
  for Eloquent/factory) outside `app/Domain` avoids the `toBeFinal` arch rule. **Decision:
  keep at `app/Models/User.php`.** (Document this as the single deliberate divergence from
  the §5.4 tree.)
- **Action throws on failure** (not returns null) — `ValidationException::withMessages(['username' => __('auth.failed')])`
  is the convention that yields a 302 redirect-back + session error (never 422) and keeps
  the controller anemic.
- **Generic error = no enumeration** — the SAME `auth.failed` message for unknown-username
  and wrong-password. Do not branch on "user exists".
- **Username normalization** — `mb_strtolower(trim($data->username))` in the Action; store
  usernames lowercased (factory + future CRUD) so the guard lookup and any future throttle
  key agree.
- **Level gate, not allow-list** — `EnsureRole` takes ONE minimum-level token and calls
  `user.role.hasAtLeast(UserRole::from($min))`. A higher role passes a lower gate.
- **TS generated file is types-only** — import `UserRole`/`LoginData` **type-only** in the
  pages; never value-import the enum from `generated.d.ts` (breaks the Vite build). Run
  `php artisan typescript:transform` after adding the `#[TypeScript]` enum/DTO.

## 5. Risks & mitigations

| Risk | Mitigation |
|------|-----------|
| Editing the create migration breaks the green baseline | Run `migrate:fresh` in plan-verify; `down()` unchanged |
| `User` finality vs arch `toBeFinal` | Keep `User` out of `app/Domain`; it stays `final` as a framework model but arch targets `App\Domain` only |
| TS value-import of the enum breaks build | type-only import; lint/tsc catches it |
| Lang key drift (flashed key missing in one locale) | `AuthLangKeyTest` asserts both es+en resolve |
| Gate off-by-one on the ladder | `UserRoleTest` asserts the full `hasAtLeast` matrix |
| `organization_id` nullable FK with no target | no `->constrained()` this slice; FK added with Organization domain |

## 6. Verification (read-only steps the build may run; NOT pest/migrate on shared DB)

- `composer analyse` (Larastan L9) — read-only, allowed.
- `tsc --noEmit` / `php artisan typescript:transform` — read-only/codegen, allowed.
- **Do NOT** run `pest` / `migrate` against the shared dev DB from the generating agent;
  the CI / human gate runs the full suite. Tests are written to be green under
  `migrate:fresh` on PostgreSQL 18.
