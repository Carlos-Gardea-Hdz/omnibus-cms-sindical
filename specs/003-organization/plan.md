# Plan 003 — Organization Domain + org-scoping retrofit (the HOW)

> **Phase:** Plan. Turns `spec.md` into architecture + the exact files to touch.
> **Gated on:** CLAUDE.md §No-negociables + SPEC §5.2/§6.1/§11.2 arch + FK rules.
> **Build order:** enum/migrations(structure)/models/factories/seeder → the 2 retrofit
> ALTER migrations → scope+context+middleware → DTOs/exceptions/Actions → controllers/
> routes/exception-render → pages/i18n → tests (crown isolation last). Backend before
> frontend; the scope wiring is the spine everything else hangs on.

## 1. Architecture overview

```
HTTP (Inertia)                                Domain (Illuminate\Http-free) + App\Support
─────────────                                 ───────────────────────────────────────────
Admin\OrganizationController (super_admin)    Domain\Organization\Data\{Organization,Branch,
Admin\MunicipalityController (admin+)            Director,Representative,Municipality}Data
Admin\DirectorController     (admin+)         Domain\Organization\Actions\{Create,Update,Delete}*Action
Admin\BranchController       (manager+)       Domain\Organization\Enums\RepresentativeShift (#[TypeScript])
Admin\RepresentativeController (manager+)     Domain\Organization\Exceptions\{OrganizationInUse,
                                                MunicipalityInUse,BranchHasRepresentatives,
EnsureOrganizationScope middleware (org.scope)  DirectorAlreadyAssigned}Exception
  → writes OrganizationContext per request    Domain\Organization\Models\{Organization,Branch,
                                                Director,Representative,Municipality}
bootstrap/app.php  (render the 4 domain       App\Support\OrganizationScope  (global Eloquent Scope)
  exceptions → 302 + field error / 422 JSON)  App\Support\OrganizationContext (per-request singleton)
                                              app\Models\User (organization_id FK; the scope's source)
                                              Domain\Content\Models\Article (gains the global scope
                                                + organization_id/branch_id — retrofit, no rewrite)
```

**Rule compliance:** Actions return models / void, throw domain exceptions or
`ValidationException`; they never import `Illuminate\Http`. The scope + context live in
`App\Support` (domain-neutral, exactly like UNIGES `DemoScope`/`DemoContext`) so the
org-scoped models add the global scope WITHOUT importing `App\Domain\Identity` (the arch
tests forbid that). Controllers are anemic (DTO→Action→response, `Auth::user()` for the
acting user). DTOs are the only validation. The four domain exceptions render to
302+field-error (web) / 422 (JSON) in `bootstrap/app.php`, exactly like slice-002
`CategoryInUseException` + UNIGES `CatalogInUseException`/`InvalidStatusTransitionException`.

## 2. Data model & migrations (reversible, dependency-safe order)

> **ID type:** `$table->id()` **bigint** (NOT ULID) — matches the live `users.id` /
> `articles.id` (slices 001/002). Deviation E.

**Structure migrations (new tables), then the two retrofit ALTERs.** Timestamps chosen so
they run AFTER the slice-001 `0001_*` and slice-002 `2026_06_20_00xx_*` migrations.

Order (the `organizations.director_id` FK is circular with `directors.organization_id`, so
it is added in a **separate** migration after both tables exist):

```
1. create_municipalities_table
2. create_organizations_table          (NO director_id FK yet)
3. create_branches_table
4. create_directors_table
5. create_representatives_table
6. add_director_fk_to_organizations_table   (the circular FK, set null + unique)
7. add_organization_fk_to_users_table       (RETROFIT — constrain the existing column)
8. add_organization_columns_to_articles_table (RETROFIT — add org_id + branch_id)
```

### 1. `create_municipalities_table` (SPEC §6.3.1 — NO SoftDeletes)
```php
$table->id();
$table->string('name', 100);
$table->string('state', 100);
$table->timestamps();
$table->index('name');
// down(): Schema::dropIfExists('municipalities');
```

### 2. `create_organizations_table` (SPEC §6.3.2 — director_id FK deferred to step 6)
```php
$table->id();
$table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
$table->unsignedBigInteger('director_id')->nullable()->unique(); // FK added in step 6 (circular)
$table->string('name', 100);
$table->string('slug', 120)->unique();
$table->string('logo_path', 255)->nullable();
$table->date('registered_at');
$table->timestamps();
$table->softDeletes();
$table->index('municipality_id');
$table->index('registered_at');
// down(): Schema::dropIfExists('organizations');
```

### 3. `create_branches_table` (SPEC §6.3.3)
```php
$table->id();
$table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
$table->string('name', 100);
$table->string('location', 100);
$table->timestamps();
$table->softDeletes();
$table->index('organization_id');
// down(): Schema::dropIfExists('branches');
```

### 4. `create_directors_table` (SPEC §6.3.4 — UNIQUE org = one director/org, ORG-04)
```php
$table->id();
$table->foreignId('organization_id')->unique()->constrained('organizations')->restrictOnDelete();
$table->string('first_name', 100);
$table->string('last_name', 100);
$table->string('photo_path', 255)->nullable();
$table->timestamps();
$table->softDeletes();
// down(): Schema::dropIfExists('directors');
```

### 5. `create_representatives_table` (SPEC §6.3.5 — uses RepresentativeShift)
```php
$table->id();
$table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
$table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
$table->string('first_name', 100);
$table->string('last_name', 100);
$table->string('shift', 20); // RepresentativeShift backing value; cast in the model
$table->boolean('is_coordinator')->default(false);
$table->string('photo_path', 255)->nullable();
$table->timestamps();
$table->softDeletes();
$table->index('organization_id');
$table->index('branch_id');
$table->index(['organization_id', 'branch_id']);
// down(): Schema::dropIfExists('representatives');
```

### 6. `add_director_fk_to_organizations_table` (the circular FK — set null per §6.4)
```php
up():   $table->foreign('director_id')->references('id')->on('directors')->nullOnDelete();
down(): $table->dropForeign(['director_id']);  // column + unique index dropped by step 2's down
```

### 7. `add_organization_fk_to_users_table` (RETROFIT — constrain the slice-001 column)
```php
up():   // organization_id already exists (slice 001, nullable, no FK). Add only the FK.
        $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        $table->index('organization_id');
        $table->index('role'); // §6.3.6 indexes, if not already present
down(): $table->dropForeign(['organization_id']);
        $table->dropIndex(...); // drop ONLY what this migration added — never the column
```
> The column is NOT touched (slice 001 owns it). Nullable stays (Deviation B): super_admin
> may be org-less; a confined null-org user fails closed in the scope.

### 8. `add_organization_columns_to_articles_table` (RETROFIT — §6.3.8 org/branch, §6.4 restrict)
```php
up():   $table->foreignId('organization_id')->nullable()->after('id')
            ->constrained('organizations')->restrictOnDelete();
        $table->foreignId('branch_id')->nullable()->after('organization_id')
            ->constrained('branches')->restrictOnDelete();
        $table->index('organization_id');
        $table->index(['organization_id', 'published_at']); // §6.3.8 composite
down(): $table->dropConstrainedForeignId('organization_id');
        $table->dropConstrainedForeignId('branch_id');
        // (drop the added indexes too)
```
> NULLABLE + restrict FK (Deviation C). Existing rows (if any) stay null; the retrofitted
> `CreateArticleAction`/`UpdateArticleAction` stamp `organization_id` from the author so
> every NEW article is non-null. A future data-migration tightens to NOT NULL once
> backfilled. `branch_id` stamped only when the editor supplies one (UI picker deferred,
> Gate F).

Notes:
- Import `use App\Domain\Organization\Enums\RepresentativeShift;` is NOT needed in the
  representatives migration (string column + model cast); keep it a plain string column for
  portability, exactly like the slice-001 `users.role` / slice-002 `articles.status` columns.
- **No DB `ON DELETE CASCADE` anywhere** (§6.1) — the branch→article cascade is
  application-level in `DeleteBranchAction`. The only program-wide cascade remains
  slice-002 `article_images` → `articles`.

## 3. The scope retrofit (the spine — App\Support, the UNIGES DemoScope mirror)

### `App\Support\OrganizationContext` (per-request singleton)
```php
final class OrganizationContext
{
    private ?int $organizationId = null;
    private bool $unconfined = true;          // default: unconfined (CLI/console/no user)

    public function confineTo(?int $organizationId): void { $this->organizationId = $organizationId; $this->unconfined = false; }
    public function unconfine(): void { $this->organizationId = null; $this->unconfined = true; }
    public function isUnconfined(): bool { return $this->unconfined; }
    public function organizationId(): ?int { return $this->organizationId; }
}
```
- Bound `$this->app->singleton(OrganizationContext::class)` in `AppServiceProvider::register`
  (mirrors UNIGES binding `DemoContext`).
- **Defaults to unconfined** so CLI / queue / seeders / tests-without-a-user see all rows
  (preserves the slice-002 behavior for non-HTTP paths).

### `App\Support\OrganizationScope` (global Eloquent Scope)
```php
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ctx = app(OrganizationContext::class);
        if ($ctx->isUnconfined()) { return; }                 // super_admin / CLI → no-op (sees all)
        $orgId = $ctx->organizationId();
        if ($orgId === null) { $builder->whereRaw('1 = 0'); return; } // confined-but-null → fail CLOSED
        $builder->where($model->qualifyColumn('organization_id'), $orgId);
    }
}
```
- `qualifyColumn` keeps the predicate unambiguous under joins (UNIGES DemoScope lesson).
- Added via `protected static function booted(): void { static::addGlobalScope(new OrganizationScope); }`
  on **Article, Branch, Director, Representative** models. **NOT** on `User` (Deviation A —
  would break login) and **NOT** on `Organization`/`Municipality`/`Category` (catalogs /
  super_admin-gated).
- Bypass for cross-org admin ops: `Model::withoutGlobalScope(OrganizationScope::class)`.

### `App\Http\Middleware\EnsureOrganizationScope` (alias `org.scope`, SPEC §10.3)
```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();
    $ctx = app(OrganizationContext::class);
    $role = $user?->role;
    // manager + editor are confined to their own org; administrator + super_admin are cross/wide.
    if ($role instanceof UserRole && $role->level() <= UserRole::Manager->level()) {
        $ctx->confineTo($user->organization_id);
    } else {
        $ctx->unconfine();
    }
    return $next($request);
}
```
- Registered in `bootstrap/app.php` `->alias(['org.scope' => EnsureOrganizationScope::class])`
  beside the existing `role` alias.
- Applied to the authenticated admin group AFTER `auth` (needs the resolved user). Because
  the slice-002 Content routes run under `['auth','role:editor']`, adding `org.scope` there
  confines managers/editors to their org **without touching the Content controllers** — the
  global scope on `Article` does the filtering. (Deviation D — confirm whether administrator
  is unconfined here or confined like manager; the `<= Manager` test is the manager-confined
  line, flip to `< Administrator` semantics if administrator must also confine.)
- Uses `UserRole` from `App\Domain\Identity\Enums` — legitimate in HTTP middleware (not
  domain code), exactly as `EnsureRole` already does.

## 4. Files to create / touch

### Domain — Organization
| Path | Action | Notes |
|------|--------|-------|
| `app/Domain/Organization/Enums/RepresentativeShift.php` | create | backed enum, `#[TypeScript]`, `labelKey()/color()` |
| `app/Domain/Organization/Models/Municipality.php` | create | `newFactory()`, `organizations()` HasMany, NO SoftDeletes, PHPDoc |
| `app/Domain/Organization/Models/Organization.php` | create | `newFactory()`, SoftDeletes, `municipality()`/`director()` belongsTo, `branches()`/`representatives()`/`users()`/`articles()` HasMany, slug, PHPDoc |
| `app/Domain/Organization/Models/Branch.php` | create | `newFactory()`, SoftDeletes, `organization()` belongsTo, `representatives()`/`articles()` HasMany, **`booted()` adds OrganizationScope**, PHPDoc |
| `app/Domain/Organization/Models/Director.php` | create | `newFactory()`, SoftDeletes, `organization()` belongsTo, **OrganizationScope**, PHPDoc |
| `app/Domain/Organization/Models/Representative.php` | create | `newFactory()`, SoftDeletes, `shift`→enum cast, `organization()`/`branch()` belongsTo, **OrganizationScope**, PHPDoc |
| `app/Domain/Organization/Data/MunicipalityData.php` | create | Spatie Data, `#[TypeScript]`, `final` |
| `app/Domain/Organization/Data/OrganizationData.php` | create | logo `?UploadedFile`, `rules()` logo-required-on-create |
| `app/Domain/Organization/Data/BranchData.php` | create | org_id Exists, name/location |
| `app/Domain/Organization/Data/DirectorData.php` | create | org_id Exists, names, photo |
| `app/Domain/Organization/Data/RepresentativeData.php` | create | org/branch Exists, shift enum, is_coordinator, photo |
| `app/Domain/Organization/Actions/Create/Update/DeleteMunicipalityAction.php` | create | delete = graceful restrict (MunicipalityInUse) |
| `app/Domain/Organization/Actions/Create/Update/DeleteOrganizationAction.php` | create | slug+logo; delete = graceful restrict on branches (OrganizationInUse), `withoutGlobalScope` |
| `app/Domain/Organization/Actions/Create/Update/DeleteBranchAction.php` | create | **the cascade/restrict Action — §11.4 #2/#3** |
| `app/Domain/Organization/Actions/Create/Update/DeleteDirectorAction.php` | create | one-per-org assert + wire `organizations.director_id` |
| `app/Domain/Organization/Actions/Create/Update/DeleteRepresentativeAction.php` | create | plain CRUD |
| `app/Domain/Organization/Exceptions/MunicipalityInUseException.php` | create | `RuntimeException`, message-only |
| `app/Domain/Organization/Exceptions/OrganizationInUseException.php` | create | same |
| `app/Domain/Organization/Exceptions/BranchHasRepresentativesException.php` | create | same |
| `app/Domain/Organization/Exceptions/DirectorAlreadyAssignedException.php` | create | same |

### App\Support (domain-neutral — the scope spine)
| Path | Action | Notes |
|------|--------|-------|
| `app/Support/OrganizationContext.php` | create | per-request singleton (confined/unconfined) |
| `app/Support/OrganizationScope.php` | create | global Scope; no-op unconfined, `=org` confined, `1=0` confined-null |
| `app/Providers/AppServiceProvider.php` | edit | `singleton(OrganizationContext::class)` |

### Retrofit — touch already-built code (NO behavior break)
| Path | Action | Notes |
|------|--------|-------|
| `app/Domain/Content/Models/Article.php` | edit | add `booted()` OrganizationScope; add `organization_id`/`branch_id` to `$fillable`; add `organization()`/`branch()` belongsTo (nullable → `?Organization`/`?Branch` in PHPDoc); add `@property int\|null $organization_id`, `@property int\|null $branch_id` |
| `app/Domain/Content/Actions/CreateArticleAction.php` | edit | stamp `organization_id` = `$author->organization_id`; `branch_id` from data if present |
| `app/Domain/Content/Actions/UpdateArticleAction.php` | edit | preserve/stamp `organization_id` (don't null it); optional branch_id |
| `app/Models/User.php` | edit | add `organization()` belongsTo + `@property-read ?Organization`; (NO global scope — Deviation A) |

### Models / factories / seeder / migrations
| Path | Action | Notes |
|------|--------|-------|
| `database/factories/MunicipalityFactory.php` | create | fictional name/state |
| `database/factories/OrganizationFactory.php` | create | `for(Municipality)`, slug, registered_at, no-director default |
| `database/factories/BranchFactory.php` | create | `for(Organization)` |
| `database/factories/DirectorFactory.php` | create | `for(Organization)` (one per org) |
| `database/factories/RepresentativeFactory.php` | create | `for(Organization)`/`for(Branch)`, shift state |
| `database/factories/ArticleFactory.php` | edit | accept/stamp `organization_id`/`branch_id` (so 002 article tests can pin an org); default null preserved for unconfined tests |
| `database/factories/UserFactory.php` | edit | optional `organization_id` state (`forOrganization()`); existing default unchanged so 001/002 tests stay green |
| `database/seeders/MunicipalitySeeder.php` | create | fictional municipalities (Appendix) |
| `database/seeders/OrganizationSeeder.php` | create | 1–2 demo orgs + branches + a director (fictional) |
| `database/seeders/DatabaseSeeder.php` | edit | call Municipality then Organization seeders BEFORE any user/article that needs an org |
| `database/migrations/..._create_municipalities_table.php` … (8 files) | create | §2 order 1–8 |

### HTTP
| Path | Action | Notes |
|------|--------|-------|
| `app/Http/Controllers/Admin/OrganizationController.php` | create | resource, super_admin |
| `app/Http/Controllers/Admin/MunicipalityController.php` | create | resource/index+store+update+destroy, admin+ |
| `app/Http/Controllers/Admin/DirectorController.php` | create | resource, admin+ |
| `app/Http/Controllers/Admin/BranchController.php` | create | resource, manager+ (index auto-scoped) |
| `app/Http/Controllers/Admin/RepresentativeController.php` | create | resource, manager+ (index auto-scoped) |
| `app/Http/Middleware/EnsureOrganizationScope.php` | create | alias `org.scope` (§3 above) |
| `bootstrap/app.php` | edit | register `org.scope` alias; render the 4 new domain exceptions (mirror the CategoryInUseException block) |
| `routes/web.php` | edit | super_admin organizations; admin+ municipalities/directors; manager+ branches/representatives; apply `org.scope` to the authed admin shell |

### Frontend / i18n
| Path | Action | Notes |
|------|--------|-------|
| `resources/js/Pages/Organizations/{Index,Create,Edit}.tsx` | create | list + form (logo uploader, municipality select) |
| `resources/js/Pages/Branches/{Index,Create,Edit}.tsx` | create | list + form (org select) |
| `resources/js/Pages/Representatives/{Index,Create,Edit}.tsx` | create | list + form (org/branch select, shift, coordinator, photo) |
| `resources/js/Pages/Directors/{Index,Create,Edit}.tsx` | create | list + form |
| `resources/js/Pages/Municipalities/Index.tsx` | create | catalog list + inline CRUD (mirror Categories/Index) |
| `lang/es.json` | edit | `organizations.*`/`branches.*`/`directors.*`/`representatives.*`/`municipalities.*`/`representative_shift.*` |
| `lang/en.json` | edit | same keys |
| `resources/js/types/generated.d.ts` | regenerate | `typescript:transform` (do NOT hand-edit) |

### Tests
| Path | Action | Notes |
|------|--------|-------|
| `tests/Unit/Organization/RepresentativeShiftTest.php` | create | enum labelKey/color/cases (no DB) |
| `tests/Feature/Organization/OrganizationCrudTest.php` | create | create/logo-required/slug-unique/delete-blocked-on-branches/delete-ok |
| `tests/Feature/Organization/MunicipalityCrudTest.php` | create | create/delete-blocked-in-use/delete-ok |
| `tests/Feature/Organization/BranchDeletionTest.php` | create | **§11.4 #2 cascade articles (no reps) + files removed; #3 blocked (reps exist)** |
| `tests/Feature/Organization/DirectorUniqueTest.php` | create | one-per-org Action guard + DB unique-index backstop (raw insert raises) |
| `tests/Feature/Organization/RepresentativeCrudTest.php` | create | create/shift cast/is_coordinator/update/delete |
| `tests/Feature/Organization/OrgValidationTest.php` | create | 302-not-422; Exists FKs; bad shift |
| `tests/Feature/Organization/OrgRoleGateTest.php` | create | guest 302; editor→403 on branches; manager→403 on organizations; super_admin all; manager ok on branches/reps |
| `tests/Feature/Organization/OrganizationScopeIsolationTest.php` | create | **CROWN — manager sees only own org (articles/branches/reps), cross-org show=404, super_admin sees all, falsifiable `withoutGlobalScope` count guard (UNIGES DemoReportIsolationTest mirror)** |
| `tests/Feature/Organization/RetrofitMigrationTest.php` | create | `users.organization_id` FK exists + restrict; `articles.organization_id`/`branch_id` exist + restrict; CreateArticleAction stamps org_id from author |
| `tests/Feature/Organization/OrgPropsTest.php` | create | prop contracts per page |
| `tests/Feature/Organization/OrgLangKeyTest.php` | create | all flashed/thrown keys + shift labels es/en |
| `tests/Arch/ArchitectureTest.php` | edit | add Organization-only-uses-Shared/Models/App\Support (+ App\Support never imports App\Domain) |
| **(existing, MUST update — see §5)** | edit | the 002 fixtures that now need an org |

## 5. Existing tests that MUST be updated (and why) — protect the 248-green baseline

The retrofit changes two things observable to prior tests: (a) `Article` now carries a
global `OrganizationScope`, and (b) `CreateArticleAction` now stamps `organization_id` from
the author. Almost all 002 tests are UNAFFECTED because the `OrganizationContext` **defaults
to unconfined** (no acting `org.scope` middleware in those tests → the scope is a no-op,
identical to today). The narrow set that needs a touch:

| Existing test | Why it may break | Required change |
|---------------|------------------|-----------------|
| `tests/Feature/Content/ArticleCrudTest.php` | `CreateArticleAction` reads `$author->organization_id`; if a test calls the Action with a User that has no org and later asserts the row, the column is now present (null) — assertions on the row shape may need `organization_id` acknowledged. The Action must tolerate a null-org author (super_admin/test) — stamp null, don't throw. | Confirm the Action stamps `?int` (null-safe); add `organization_id` to any `assertDatabaseHas` only where a test pins an org. No scope confinement in these tests (unconfined default) so visibility is unchanged. |
| `tests/Feature/Content/ContentRoleGateTest.php` | Routes now also carry `org.scope`; an authenticated editor/manager with a **null** org would be confined-null → fail-closed (sees nothing) and a list assertion could go empty. | Give the acting users in these tests an `organization_id` (via `UserFactory::forOrganization()`), OR assert these gate tests run as super_admin/admin (unconfined). Pick per test; document. |
| `tests/Feature/Content/ContentPropsTest.php` | Same `org.scope` confinement risk for any manager/editor actor whose fixtures lack an org. | Ensure actors either are unconfined (admin+) or have an org + matching article fixtures. |
| `database/factories/ArticleFactory.php` | New nullable columns. | Default `organization_id`/`branch_id` to null (unconfined-safe); add a `forOrganization()` state used by the crown isolation test + the org-pinned 002 assertions. |
| `tests/Feature/Auth/RoleGateMatrixTest.php` | If it hits admin routes that now carry `org.scope`, a null-org manager/editor still passes the **role** gate (org.scope never 403s — it only filters data), so the gate matrix is unchanged. | Likely NO change — verify `org.scope` is non-blocking (it confines data, never aborts). Keep it that way. |

> **Design guard that keeps the blast radius tiny:** `EnsureOrganizationScope` **never
> aborts** (no 403) — it only sets the context; data filtering is the scope's job. And the
> context **defaults to unconfined**, so every test that doesn't go through the middleware
> (i.e. all 248 prior tests, which don't exercise `org.scope` with a confined manager) sees
> exactly today's behavior. The only real edits are factory defaults + a few fixtures that
> assert list contents while acting as a (previously org-less) manager/editor.

## 6. Key design decisions

- **Scope + context in `App\Support`, not `App\Domain`** — domain-neutral, so Article/
  Branch/Director/Representative add the global scope without importing `App\Domain\Identity`
  (arch tests forbid cross-domain imports). Direct mirror of UNIGES `DemoScope`/`DemoContext`.
- **Default-unconfined context** is the regression firewall: CLI, queue, seeders, and every
  prior test see all rows (today's behavior). Confinement is opt-in, set only by the
  `org.scope` middleware for a manager/editor with an org. This is why the 248 tests survive.
- **Fail-closed on confined-null** (`WHERE 1=0`): a manager misconfigured with a null org
  sees NOTHING, never every org's data. Safer than the alternative; matches the security
  posture (no fail-open).
- **`User` is scoped at the listing query, not globally** (Deviation A): a global scope on
  the auth model breaks the login lookup (runs before any org context). The future
  `UserController@index` applies `where('organization_id', …)` explicitly (or via a local
  `scopeVisibleTo`). Auth, `Auth::user()`, and the slice-001 tests are untouched.
- **The branch→article cascade is application-level** (`DeleteBranchAction`, §6.1 + §3.2
  ORG-02): no DB `ON DELETE CASCADE`. Inside one transaction it soft-deletes the branch's
  articles `withoutGlobalScope(OrganizationScope)` (so the delete reaches every article of
  the branch regardless of acting context) and removes their physical featured-image files,
  then soft-deletes the branch. If reps exist it throws BEFORE touching anything.
- **Graceful restrict-delete everywhere a restrict FK could 500** (UNIGES
  `Delete{Department}Action` + `CatalogInUseException` + `bootstrap/app.php` render):
  Municipality (orgs), Organization (branches), Branch (representatives) each pre-check
  `->exists()` (withTrashed-aware where a soft-deleted child still physically holds the FK)
  and throw a domain exception BEFORE any DELETE.
- **One-director-per-org** (ORG-04) is enforced in TWO layers: the Action pre-check
  (`DirectorAlreadyAssignedException` → graceful 302) AND a DB `unique` index on
  `directors.organization_id` (the backstop a raw insert trips — the test proves the
  constraint physically exists, not just the Action guard).
- **`organizations.director_id` circular FK** resolved by splitting into a 6th migration
  added after both tables exist (`set null` per §6.4). The reverse drops the FK; the column
  + its unique index drop with the organizations table.
- **One route-agnostic DTO per entity** (UNIGES convention): uniqueness/one-per-org asserted
  in the Action, NOT a DTO attribute, so the same DTO serves store + update.
- **Logo-required-on-create via `rules()`** in `OrganizationData` (ORG-01) using
  `Rule::requiredIf` keyed on the absence of an existing record (create vs update), exactly
  the slice-002 publish-precondition-in-context idea but for the DTO layer.
- **PHPDoc for L9:** nullable belongsTo (`Article::organization`/`branch`,
  `User::organization`, `Organization::director`) ARE `|null`; non-nullable belongsTo
  (`Branch::organization`, `Director::organization`, `Representative::organization`/`branch`)
  are NOT `|null` (the LESSONS guard). `newFactory()` on every `app/Domain` model.
- **TS generated file is types-only** — type-only import of `RepresentativeShift` + the
  DTOs in the pages; never value-import the enum (breaks the Vite build).
- **Arch rule additions:** `Organization domain only leans on Shared, Models, App\Support,
  Illuminate, Spatie\LaravelData, Spatie\TypeScriptTransformer, Database\Factories`
  (+ `->ignoring(['__','now'])`, + `Illuminate\Http\UploadedFile` allowed like slice-002);
  AND a guard `App\Support` (the scope/context) never imports `App\Domain` (keeps the
  neutral location honest, the UNIGES reason for that placement).

## 7. Risks & mitigations

| Risk | Mitigation |
|------|-----------|
| The scope breaks one of the 248 prior tests | default-unconfined context (no-op without `org.scope`); the middleware never aborts; only the enumerated §5 fixtures change; verify with `composer test` at the gate |
| Global scope on `User` breaks login | `User` is NOT globally scoped (Deviation A); listing-only scope; slice-001 auth tests untouched |
| Confined-null fails open (sees all orgs) | scope emits `WHERE 1=0` for confined-null — fail CLOSED; the crown test asserts an org-less confined manager sees nothing |
| Branch delete trips the `representatives.branch_id` restrict FK → 500 | `DeleteBranchAction` pre-checks `representatives()->exists()` and throws `BranchHasRepresentativesException` BEFORE any DELETE; rendered to 302/422 |
| Org/Municipality delete trips a restrict FK → 500 | `isReferenced()` pre-checks (withTrashed-aware) throw the in-use exception before DELETE |
| Circular `organizations.director_id` ↔ `directors.organization_id` blocks `migrate:fresh` | split the director FK into migration #6 after both tables exist |
| `articles` NOT-NULL retrofit destroys existing rows | columns added NULLABLE + restrict FK (Deviation C); new rows stamped from the author; tighten later via a backfill data-migration |
| Cross-org row visible to a manager via route-model binding | the global scope makes the cross-org bound model unresolvable → 404; crown test asserts the 404 |
| `withoutGlobalScope` forgotten in a super_admin cross-org Action → super_admin can't see other orgs | super_admin runs UNCONFINED (the middleware unconfines admin+), so the scope is already a no-op; the explicit `withoutGlobalScope` is belt-and-suspenders inside Delete Actions only |
| One-director-per-org only in the Action (bypassable) | DB `unique` index on `directors.organization_id` is the backstop; the test inserts raw to prove it |
| Lang key drift | `OrgLangKeyTest` asserts every flashed/thrown key + each `representative_shift.*` resolves es+en |
| TS value-import of the shift enum breaks build | type-only import; tsc catches it |

## 8. Verification (read-only steps the build may run; NOT pest/migrate on shared DB)

- `composer analyse` (Larastan L9) — read-only, allowed.
- `tsc --noEmit` / `php artisan typescript:transform` — read-only/codegen, allowed.
- **Do NOT** run `pest` / `migrate` against the shared dev DB from the generating agent;
  CI / the human gate runs the full suite (and confirms the 248 stay green). Tests are
  written to be green under `migrate:fresh --seed` on PostgreSQL 18.
