# Spec 003 — Organization Domain + the org-scoping retrofit (multi-branch structure + tenant confinement)

> **Phase:** Specify (the WHAT and WHY, not the HOW).
> **SSOT:** `SPEC.md` (§3.2 Organization ORG-01..05 + the **Deletion Cascade Rules**
> table, §3.1 AUTH-03/AUTH-04, §6.1 FK philosophy, §6.3.1–6.3.6 tables + §6.4 FK
> summary, §6.3.6 `users.organization_id` + §6.3.8 `articles.organization_id`/`branch_id`,
> §7.4 Administrator+ org routes + §7.3 Manager+ branch/representative routes, §10.2 RBAC
> matrix + §10.3 `EnsureOrganizationScopeMiddleware`, §11.4 scenarios **#2/#3/#8**,
> §1.4/§1.5 magenta theme).
> **Reference impl (same stack):** `omnibus-uniges` — `App\Support\DemoScope` +
> `App\Support\DemoContext` + the `AppServiceProvider` singleton + the model `booted()`
> `addGlobalScope` + `DemoReportIsolationTest` are the **global-scope retrofit + symmetric
> isolation** template for the org-confinement; the Academic `DepartmentController` +
> `DepartmentData` + `Delete{Department}Action` + `CatalogInUseException` (rendered in
> `bootstrap/app.php`) are the **catalog CRUD + graceful restrict-delete** template.
> **Built on (DO NOT break — 248 passing tests):** CMS slice 001 Identity (`UserRole`
> ladder, `EnsureRole` `role:<level>`, `app/Models/User` with the **already-nullable
> `organization_id` column awaiting its FK**), slice 002 Content (`Article`/`Category`/
> `ArticleImage`, `SanitizesContent`, the `CategoryInUseException` graceful-delete +
> `bootstrap/app.php` render pattern, `ArticleController@index` + the slug-uniqueness
> Actions whose queries this slice's scope silently joins), the arch suite, the magenta
> theme + `lang/{es,en}.json`.
> **Status:** DRAFT — awaiting human review gate before `/sdd-plan` / build.

## 1. Problem & why

Slices 001–002 built the auth spine and the content core, but both shipped a deliberate
hole: **`organization_id` was scaffolded but never constrained or enforced.** `users`
carries a nullable, FK-less `organization_id`; `articles` carries no org column at all.
Every admin query is currently global — a manager in org A can see and mutate org B's
articles. The CMS's whole multi-tenant premise (SPEC §1.3 "multi-sucursal", §3.1 AUTH-03
"manager: own org scoped", §10.2 "Own org") is unmet.

This slice builds the **Organization domain structure** (organizations, branches,
directors, representatives, municipalities) with their CRUD and the load-bearing
**§3.2 deletion-cascade rules**, AND performs the cross-cutting **org-scoping retrofit**:
it constrains `users.organization_id` with a real FK, adds `organization_id` (+ `branch_id`)
to `articles`, and installs a symmetric **`OrganizationScope`** (the UNIGES DemoScope
pattern) so a `manager` is confined to its own organization while `super_admin`/
`administrator` see across/within as §10.2 dictates — **without rewriting the 002 Content
queries**, which inherit the scope automatically.

This is the first **tenant-isolation** slice: it exercises a global Eloquent scope driven
by a per-request context, a five-table hierarchy with mixed cascade/restrict/null FK
rules, an application-level branch→article soft-delete cascade, and a DB-level unique
director constraint — none of which prior slices needed. It is also the first slice that
**modifies already-reviewed code** (the deferred FKs + the scope), so the no-regression
contract is explicit.

## 2. Scope

### In scope (Organization structure + CRUD + the scoping retrofit)

1. **`RepresentativeShift` backed enum** (`app/Domain/Organization/Enums/RepresentativeShift.php`,
   `#[TypeScript]`) — `morning`, `evening`, `night` (SPEC §3.2 ORG-03). Owns
   `labelKey()`, `color()`. No magic strings.

2. **`municipalities` table + `Municipality` model** (SPEC §6.3.1) — `name` (≤100),
   `state` (≤100). **No `SoftDeletes`** (§6.3.1 lists no `deleted_at`; it is a hard-delete
   reference catalog guarded by a restrict-in-use pre-check, exactly like UNIGES
   `Department`). Seeded with a small fictional municipality list (Appendix below). FK
   target of `organizations.municipality_id` (RESTRICT) and `members.municipality_id`
   (deferred to Membership).

3. **`organizations` table + `Organization` model** (SPEC §6.3.2) — `municipality_id`
   FK→`municipalities` **restrict**, `director_id` FK→`directors` **set null**, NULLABLE,
   **UNIQUE** (1:1), `name` (≤100), `slug` (≤120, unique, derived from name), `logo_path`
   (nullable ≤255), `registered_at` (date), `SoftDeletes`. The director FK is added in a
   **second migration** after `directors` exists (circular dependency — see plan §2).

4. **`branches` table + `Branch` model** (SPEC §6.3.3) — `organization_id`
   FK→`organizations` **restrict**, `name` (≤100), `location` (≤100), `SoftDeletes`.

5. **`directors` table + `Director` model** (SPEC §6.3.4) — `organization_id`
   FK→`organizations` **restrict**, **UNIQUE** (one director per organization at DB
   level — SPEC §3.2 ORG-04, the `UniqueDirectorPerOrganizationRule` intent enforced as a
   DB unique index), `first_name`/`last_name` (≤100), `photo_path` (nullable), `SoftDeletes`.

6. **`representatives` table + `Representative` model** (SPEC §6.3.5) — `organization_id`
   FK→`organizations` **restrict**, `branch_id` FK→`branches` **restrict**, `first_name`/
   `last_name` (≤100), `shift` (`RepresentativeShift`, not null), `is_coordinator`
   (bool, default false), `photo_path` (nullable), `SoftDeletes`. **This is the
   `branch.representatives` relation §3.2 ORG-02 checks before allowing a branch delete.**

7. **THE RETROFIT — constrain `users.organization_id` + add it to `articles`**
   (reversible migrations):
   - **`users.organization_id`**: an `ALTER` migration adds the FK→`organizations(id)`
     **restrict** (§6.4) on the existing nullable column (kept nullable: super_admin may
     be org-less / cross-org — see Deviation B). `down()` drops only the FK, never the
     column (slice 001 owns it).
   - **`articles.organization_id` + `articles.branch_id`**: an `ALTER` migration adds both
     columns. Per §6.4 both are **restrict**. Because live article rows may already exist
     and the FK is restrict (no backfill target is guessable safely), the columns are added
     **nullable** with the restrict FK; the Create/Update Article Actions are retrofitted to
     **stamp the author's `organization_id`** on write (and `branch_id` when the editor
     selects one). Documented divergence (Deviation C): §6.3.8 lists them NOT NULL, but a
     nullable-with-restrict-FK column is the only reversible, non-destructive way to bolt
     org onto an already-shipped table without a data backfill; new articles always get a
     non-null `organization_id`.

8. **`OrganizationScope` global scope + `OrganizationContext` per-request singleton**
   (`app/Support/OrganizationScope.php` + `app/Support/OrganizationContext.php`, both
   domain-neutral under `App\Support` so org-scoped models don't import `App\Domain`):
   - `OrganizationContext` holds the active confinement: a nullable `?int $organizationId`
     **plus** a `bool $unconfined` flag. Set per request by the new
     `EnsureOrganizationScope` middleware from the authenticated user.
   - `OrganizationScope` (implements `Illuminate\Database\Eloquent\Scope`) is added via
     `booted()` to the org-scoped models. Semantics (symmetric, falsifiable):
     - **Unconfined (super_admin, or administrator within-org reads, or CLI/console/no
       user)** → scope is a **no-op** (sees all rows). For super_admin this is genuine
       cross-org; for CLI/queue/seeders it preserves global access.
     - **Confined to org X (manager / editor)** → `WHERE <table>.organization_id = X`.
     - A confined user with a **null** `organization_id` (misconfigured) → `WHERE 1 = 0`
       (sees nothing — fail closed, never fail open to all orgs).
   - Cross-org administrative operations bypass the scope explicitly with
     `Model::withoutGlobalScope(OrganizationScope::class)` (used only inside the super_admin
     org-management Actions, never in manager-facing paths).
   - **Org-scoped models (carry the scope):** `Article`, `Branch`, `Director`,
     `Representative`, and the **`User` listing** (the `UserController@index` scope — but
     auth itself must NOT be scoped; see §Deviation A / plan §4). `Organization` itself is
     **NOT** globally scoped (it is the super_admin-only catalog; route-gating confines it).
     `Category`/`Municipality` are shared catalogs — **not** org-scoped.

9. **`EnsureOrganizationScope` middleware** (`app/Http/Middleware/EnsureOrganizationScope.php`,
   alias `org.scope`) — SPEC §10.3. Reads `$request->user()`; if the user's role is
   `manager` or `editor` it confines the `OrganizationContext` to `user->organization_id`;
   for `administrator` and `super_admin` it leaves the context **unconfined**. Registered
   in `bootstrap/app.php` beside the `role` alias and applied to the admin route group
   AFTER `auth` (it needs the resolved user). Because Content's slice-002 routes already
   run under `['auth','role:editor']`, the scope is applied there too so a manager/editor
   automatically sees only its org's articles — **no Content controller code changes for
   the read path** (the global scope does the work).

10. **DTOs (Spatie Data, `#[TypeScript]`, `final`)** — one route-agnostic DTO per entity
    (the UNIGES convention: uniqueness enforced in the Action, not a DTO `Unique`, so the
    same DTO serves store + update):
    - `MunicipalityData` — `name` (required ≤100), `state` (required ≤100).
    - `OrganizationData` — `name` (required ≤100), `slug` (nullable ≤120, derived from name
      when absent, slug-format validated), `municipality_id` (required,
      `Exists('municipalities','id')`), `registered_at` (required date), `logo`
      (nullable `UploadedFile` — image mime, ≤20MB; **required on create** via a custom
      `rules()` clause per ORG-01 "Logo required").
    - `BranchData` — `organization_id` (required, `Exists('organizations','id')`), `name`
      (required ≤100), `location` (required ≤100).
    - `DirectorData` — `organization_id` (required, `Exists`), `first_name` (required ≤100),
      `last_name` (required ≤100), `photo` (nullable `UploadedFile`). The one-per-org
      uniqueness is asserted in the Action (DB unique index is the backstop).
    - `RepresentativeData` — `organization_id` (required, `Exists`), `branch_id` (required,
      `Exists('branches','id')`), `first_name`/`last_name` (required ≤100), `shift`
      (required `RepresentativeShift`), `is_coordinator` (bool, default false), `photo`
      (nullable `UploadedFile`).

11. **Actions (`final`, `DB::transaction` on multi-table/file writes, one op each)**:
    - **Municipality:** `Create/Update/DeleteMunicipalityAction`. Delete is the graceful
      restrict-delete — `isReferenced()` pre-check (`Organization::where('municipality_id')
      ->exists()`) throws `MunicipalityInUseException` BEFORE any DELETE (ORG-05 → HTTP 422
      / 302 field error, never 500).
    - **Organization:** `Create/Update/DeleteOrganizationAction`. Create derives/validates
      the unique slug, stores the logo. **`DeleteOrganizationAction` is the graceful
      restrict-delete** — `isReferenced()` pre-check (`Branch::where('organization_id')
      ->exists()`, withTrashed-aware) throws `OrganizationInUseException` (ORG-01 "Cannot
      delete if branches exist" → 422/302), so the restrict FK is never tripped. These
      Actions are super_admin-only and operate **`withoutGlobalScope(OrganizationScope)`**
      where they touch scoped children, so cross-org management works.
    - **Branch:** `Create/Update/DeleteBranchAction`. **`DeleteBranchAction` is the
      load-bearing §3.2 ORG-02 + §11.4 #2/#3 Action:**
      1. If the branch has any **representatives** (`branch->representatives()->exists()`,
         withTrashed-aware) → throw `BranchHasRepresentativesException` (→ 422/302, branch
         NOT deleted, no 500 from the restrict FK on `representatives.branch_id`).
      2. Else (no representatives) → inside one `DB::transaction`: **application-level
         cascade** — soft-delete every `Article` where `branch_id = branch.id`
         (`withoutGlobalScope(OrganizationScope)` so a super_admin delete reaches all of the
         branch's articles regardless of acting context), **delete the physical featured-
         image files** for those articles (Storage), then soft-delete the branch itself.
         This is an **application-layer cascade**, never a DB `ON DELETE CASCADE` (§6.1).
    - **Director:** `Create/Update/DeleteDirectorAction`. Create asserts one-per-org
      (`Director::where('organization_id')->exists()` → `DirectorAlreadyAssignedException`,
      the ORG-04 `UniqueDirectorPerOrganizationRule` intent; the DB unique index is the
      backstop). On create/update, set `organizations.director_id` to wire the 1:1.
    - **Representative:** `Create/Update/DeleteRepresentativeAction`. Plain CRUD; the
      representative's existence is what blocks its branch's delete (above).

12. **Anemic controllers (≤15 lines/method, DTO→Action→response, no `Illuminate\Http\Request`,
    no Eloquent in mutations)**:
    - `Admin\OrganizationController` — resource (index/create/store/edit/update/destroy);
      **super_admin-only** (§7.4 + §10.2 "Organizations CRUD: super_admin only").
    - `Admin\MunicipalityController` + `Admin\DirectorController` — resource;
      **administrator+** (§7.4).
    - `Admin\BranchController` + `Admin\RepresentativeController` — resource; **manager+**
      (§7.3). Their index lists are **org-scoped automatically** by `OrganizationScope`
      (manager sees only own-org branches/representatives; super_admin/administrator see
      all — administrator within-org is acceptable here since an administrator belongs to
      one org, see Deviation D).

13. **Routes** (`routes/web.php`, all `->name()`, no closures) — added inside / beside the
    existing admin group with the correct level gate AND the new `org.scope` middleware:
    - **`role:super_admin`** group → `Route::resource('admin/organizations', …)`.
    - **`role:administrator`** group → `admin/municipalities`, `admin/directors` resources.
    - **`role:manager`** group → `admin/branches`, `admin/representatives` resources.
    - `org.scope` is applied to the whole authenticated admin shell (so the Content routes
      from 002 also confine managers/editors to their org). The level gates are unchanged;
      only the new resources + the `org.scope` middleware are added. Resource route NAMES
      follow `admin.organizations.*`, `admin.branches.*`, etc.

14. **Inertia 2 + React 19 + TS pages** (magenta theme, dark/light + ES/EN, snake_case
    props matching the controller payload exactly; a prop-contract test per page):
    - `Organizations/Index` + `Organizations/Create` + `Organizations/Edit` — list
      (name, slug, municipality, branch_count, director_name, registered_at) + form
      (name/slug, municipality select, registered_at, logo uploader).
    - `Branches/Index` + `Branches/Create` + `Branches/Edit` — list (name, location,
      organization_name, representative_count) + form (organization select, name, location).
    - `Representatives/Index` + `Representatives/Create` + `Representatives/Edit` — list +
      form (organization select, branch select, names, shift select, is_coordinator, photo).
    - `Directors/Index` + `Directors/Create` + `Directors/Edit` — list + form.
    - `Municipalities/Index` — list + inline create/edit/delete (mirrors the UNIGES catalog
      page + the slice-002 `Categories/Index`).

15. **i18n keys** — `organizations.*`, `branches.*`, `directors.*`, `representatives.*`,
    `municipalities.*`, `representative_shift.*` added to BOTH `lang/es.json` and
    `lang/en.json` — every `__()` key any Action/exception/controller flashes or throws,
    plus the `representative_shift.{morning,evening,night}` labels the enum resolves
    (full enumerated list in the contract). Pure UI copy (field labels, buttons) is
    client-side i18n.

16. **Tests (Pest, PostgreSQL 18 — never SQLite; Feature for app-bound, Unit for pure):**
    See §3 acceptance scenarios; the exhaustive falsifiable list is in plan.md / the
    contract. The crown is the **`OrganizationScopeIsolationTest`** — the CMS analogue of
    UNIGES `DemoReportIsolationTest`: a manager in org A sees/mutates ONLY org A's
    articles/branches/representatives; a super_admin sees all; a falsifiable
    `Article::withoutGlobalScope(...)->count()` proves the rows physically exist and the
    scope hides them (not that the data is merely absent).

### Out of scope — DEFERRED (explicitly noted, not silently dropped)

- **AUTH-04 super_admin assigning/moving a user across orgs** (the `users.organization_id`
  reassignment + the "only one super_admin at a time" rule) → the **User-management slice**.
  This slice adds the `users.organization_id` **FK** and scopes the `UserController@index`
  listing, but the full `Admin\UserController` CRUD (create/assign-role/move-org) is NOT
  built here (§7.4 lists `admin/users` as a separate administrator+ resource). A
  super_admin moving a user between orgs is supported at the **schema** level (nullable FK,
  restrict) and noted as the mechanism the future slice uses; the Action is deferred. **Gate E.**
- **`organization_id`/`branch_id` on `job_postings`, `contact_messages`, `members`,
  `page_views`, `daily_snapshots`** (§6.3.10–6.3.14) → their owning domain slices (Jobs,
  Engagement, Membership, Analytics). This slice scopes **Article + the Organization
  hierarchy** only. Each future slice adds its own org column + the `OrganizationScope`
  trait/`booted()` line, reusing the `App\Support` scope this slice ships.
- **Director ↔ Organization 1:1 reverse polish** (rich `Organization/Show` page, the
  representative coordinator workflow) → polish slice. Core CRUD + the unique constraint
  land now.
- **Meilisearch org filter** (NEWS-06 "Manager-level: scoped to own organization") → the
  Search slice; once `Article` is `Searchable` its `toSearchableArray` carries
  `organization_id` and the search query filters by the confined org. Noted, not built.
- **`branch_id` UI on the Article editor** → this slice adds the `articles.branch_id`
  column + FK and stamps `organization_id` automatically; surfacing a branch picker on the
  002 article form is a small follow-up (the column + scope are the load-bearing part).
  **Gate F.**

### Deviation from SPEC, flagged for the gate

- **A. `OrganizationScope` is NOT applied to authentication.** The `User` model is the
  framework auth model; a global org scope on it would break login (the login lookup runs
  before any org context exists) and the slice-001 auth tests. → the scope confines the
  **`UserController@index` listing** via an explicit scoped query (or a dedicated query
  scope), NOT the `User` model's global `booted()` scope. `Article`/`Branch`/`Director`/
  `Representative` carry the global scope; `User` is scoped only at the listing query.
  **Gate decision A.**
- **B. `users.organization_id` stays NULLABLE with a restrict FK.** §6.3.6 lists it NOT
  NULL, but a `super_admin` is legitimately cross-org / org-less, and slice 001 already
  shipped it nullable. → keep nullable + add the restrict FK. A confined user with a null
  org fails closed (`WHERE 1=0`). **Gate decision B.**
- **C. `articles.organization_id`/`branch_id` added NULLABLE with restrict FK.** §6.3.8
  lists them NOT NULL. Adding a NOT-NULL restrict FK to an already-populated table with no
  safe backfill target is destructive/irreversible-in-spirit. → add NULLABLE + restrict FK;
  the Create/Update Article Actions stamp `organization_id` from the author so every NEW
  article is non-null; a future data-migration can tighten to NOT NULL once backfilled.
  **Gate decision C.**
- **D. `administrator` sees all branches/representatives across orgs (unconfined), not
  "within one org".** §10.2 says administrator is "All within org" for Branches/Reps. Since
  an administrator belongs to exactly one organization and the scope's binary
  confined/unconfined model treats administrator as unconfined, an administrator currently
  sees every org's branches. → EITHER (recommended, simplest, matches "administrator = all"
  for Organizations-adjacent management) keep administrator unconfined, OR confine
  administrator like manager (then super_admin alone is cross-org). The crown isolation
  guarantee (manager confined, super_admin cross-org) holds either way. **Gate decision D —
  confirm whether administrator is org-confined or org-wide.**
- **E. Bigint IDs, not ULID** (inherited Deviation A from slices 001/002) — `$table->id()`
  + `foreignId()` throughout, matching the live `users`/`articles` tables. **Gate E.**

## 3. Acceptance scenarios (When… Then)

1. **Create organization.** *When* a super_admin posts a valid `OrganizationData` with a
   logo, *then* the organization is created (slug derived/unique, logo stored) and the
   response is a 302 back/redirect with `organizations.created`.
2. **Organization logo required on create (ORG-01).** *When* a super_admin posts
   `OrganizationData` with no logo, *then* 302 back with a `logo` session error; nothing is
   created.
3. **Organization slug unique-ignore-self.** *When* an organization is updated keeping its
   own slug → no error; *when* it takes another org's slug → 302 + `slug` error
   (`organizations.error.slug_taken`).
4. **Organization delete blocked when branches exist (ORG-01).** *When* an organization with
   ≥1 branch is deleted, *then* `OrganizationInUseException` → 302/422 with an
   `organization` field error (`organizations.error.has_branches`); the org is NOT deleted;
   no 500/SQL error reaches the user.
5. **Organization delete allowed when empty.** *When* an organization with no branches is
   deleted, *then* it is soft-deleted and 302 with `organizations.deleted`.
6. **Create municipality + delete protection (ORG-05).** *When* a municipality with ≥1
   organization is deleted → `MunicipalityInUseException` → 302/422 with
   `municipalities.error.in_use`, NOT deleted; an unreferenced municipality deletes cleanly.
7. **Branch delete BLOCKED when representatives exist (ORG-02, §11.4 #3).** *When* a branch
   with ≥1 representative is deleted, *then* `BranchHasRepresentativesException` → 302/422
   with `branches.error.has_representatives`; the branch and its representatives are
   untouched; no 500 from the `representatives.branch_id` restrict FK.
8. **Branch delete CASCADES articles when no representatives (ORG-02, §11.4 #2).** *When* a
   branch with NO representatives but WITH articles is deleted, *then* in one transaction
   the branch is soft-deleted, **every article with that `branch_id` is soft-deleted**, and
   the physical featured-image files of those articles are removed from storage; 302 with
   `branches.deleted`. The cascade is application-level (the articles' restrict FK is never
   the deletion mechanism).
9. **One director per organization (ORG-04).** *When* a director is created for an org that
   already has one, *then* `DirectorAlreadyAssignedException` → 302/422 with
   `directors.error.already_assigned`; the DB unique index is the backstop (a raw second
   insert raises a unique violation, proving the constraint exists).
10. **Representative CRUD + shift enum.** *When* a representative is created with a valid
    `RepresentativeData` (shift ∈ {morning,evening,night}), *then* it is persisted with the
    cast `RepresentativeShift` enum and `is_coordinator` honored; an invalid shift value →
    302 validation error.
11. **Web validation = 302, never 422.** *When* any Org-domain DTO's rules fail (missing
    required field, over-length, non-existent FK reference, bad shift), *then* Spatie Data
    surfaces a 302 redirect-back with session errors, never a 422 JSON response.
12. **Role gating (§7.3/§7.4/§10.2).** *When* a guest hits any `/admin/organizations*` →
    302 login. *When* an **editor** hits `/admin/branches` (manager+) → 403; a **manager**
    hits `/admin/organizations` (super_admin) → 403; a **super_admin** reaches every
    Org-domain route → allowed. A manager reaches `/admin/branches`/`/admin/representatives`
    → allowed.
13. **`users.organization_id` FK constrained.** *When* the retrofit migration runs, *then*
    `users.organization_id` references `organizations(id)` with `RESTRICT`; deleting an org
    that still has users is blocked at the DB level (and surfaced gracefully where a UI path
    exists); `down()` drops only the FK, not the column.
14. **`articles.organization_id` stamped on create (retrofit).** *When* an editor in org A
    creates an article, *then* the persisted row's `organization_id` = A's id (stamped from
    the author by `CreateArticleAction`), and `branch_id` is set when supplied; the column
    + restrict FK exist after migration; `down()` drops both columns + FKs.
15. **CROWN — manager org-confinement is symmetric & falsifiable (§3.1 AUTH-03, §10.2,
    §11.4 #8).** Given articles/branches/representatives in org A and org B:
    - A **manager of org A** listing `/admin/articles`, `/admin/branches`,
      `/admin/representatives` sees **only org-A rows** — never org B's.
    - A manager of org A attempting to view/edit/delete an org-B Article/Branch (route-model
      bound) gets a **404** (the global scope makes the cross-org row unresolvable), never a
      200 and never another org's data.
    - A **super_admin** listing the same routes sees **all** rows (A + B).
    - **Falsifiable guard:** `Article::withoutGlobalScope(OrganizationScope::class)->count()`
      (and the Branch/Representative equivalents) **physically exceeds** the manager's
      visible count — the rows exist; the scope hides them (mirrors UNIGES
      `Student::withoutGlobalScopes()->count()` assertion). If a query ever bypassed the
      scope to "see all", this test goes red.
16. **Symmetric: super_admin / unconfined context sees everything.** *When* a super_admin
    (or a CLI/console run with no user) queries the scoped models, *then* the scope is a
    no-op and all org rows are returned — proving confinement is by the acting user's role,
    not by missing data.
17. **No regression on the 002 Content read path.** *When* the slice ships, *then* the
    existing `ArticleController@index` (now silently scoped) still returns the acting user's
    visible articles, the slug-uniqueness checks still see the right rows, and **all 248
    prior tests pass** — except the explicitly-updated ones (see §4 / contract: the 002
    article-CRUD + role-gate + props tests that now need an `organization_id` on their
    fixtures, called out by name).
18. **Prop contract.** *When* each Org page renders, *then* the Inertia component name + its
    props match the page's prop interface exactly (snake_case); no leaked server props.
19. **Lang keys resolve.** *When* every flashed/thrown `__()` key and each
    `representative_shift.{value}` label is resolved under `es` and `en`, *then* a non-empty,
    non-key string is returned in both locales.
20. **Organization-domain isolation (arch, §11.2).** The Organization domain only leans on
    Shared + Models + `App\Support` (the scope/context) + Illuminate + Spatie\LaravelData +
    Spatie\TypeScriptTransformer + Database\Factories; never HTTP (except the
    `UploadedFile` boundary), never another domain's Actions. Strict types, final domain
    classes, anemic controllers, no FormRequest — all green. A new
    `Organization-only-uses-Shared` arch rule is added.

## 4. Success criteria (definition of done for this slice)

- `composer format` (Pint) clean; `composer analyse` (Larastan **level 9**) green;
  `composer test` (Pest, incl. the extended arch suite + the new unit/feature tests on
  PostgreSQL 18) green — **including all 248 prior tests** (the only edits to existing tests
  are the fixture updates enumerated in the contract, each with its reason).
- `php artisan typescript:transform` emits `RepresentativeShift`, `OrganizationData`,
  `BranchData`, `DirectorData`, `RepresentativeData`, `MunicipalityData` into
  `resources/js/types/generated.d.ts`; `tsc --noEmit` and Vitest pass.
- `php artisan migrate:fresh --seed` applies cleanly on PostgreSQL 18: the five Org tables
  + the two ALTER migrations (`users` FK, `articles` org/branch columns) created with the
  documented FK rules (§6.4); the director circular FK resolved by ordering; municipalities
  seeded; every `down()` reverses cleanly in dependency-safe order (the ALTERs drop only
  what they added).
- Org CRUD is a working round trip in the UI; branch delete cascades articles (no reps) /
  blocks (reps); organization delete blocks on branches; one-director-per-org is enforced.
- **The crown `OrganizationScopeIsolationTest` proves a manager sees ONLY its org and a
  super_admin sees all, with the falsifiable `withoutGlobalScope` count guard.**
- No real PII anywhere; demo seed data is fictional (no real municipality/personal data);
  `.gitignore` still blocks db/secrets/uploads.

## 5. Constitution (§No-negociables) this slice must satisfy

`declare(strict_types=1)` everywhere · explicit return types · `final` domain classes ·
`readonly` + constructor promotion on DTOs/VOs · backed `RepresentativeShift` enum with
`labelKey()/color()` · Spatie Data DTOs only (no FormRequest, no `$request->validate()`) ·
Actions one-operation, `DB::transaction` on multi-table/file writes · anemic controllers
(≤15 lines, DTO→Action→response, no `Illuminate\Http\Request`) · domain never imports
`Illuminate\Http` (the `OrganizationScope`/`OrganizationContext` live in `App\Support`,
domain-neutral, so models don't import `App\Domain`) · web validation = 302 + session
errors, never 422 · migrations reversible, FK-restrict + SoftDeletes (no DB cascade
anywhere — the branch→article cascade is application-level per §6.1) · the org-scope is
**symmetric** (confined sees only own org, unconfined sees all) and **fail-closed** (a
confined null-org user sees nothing) with super_admin bypass via
`withoutGlobalScope` · Pest with arch tests + an Organization-domain isolation rule + the
falsifiable cross-org `OrganizationScopeIsolationTest` · PostgreSQL for DB tests ·
dark/light + bilingual UI · every flashed/thrown `__()` key present in both `lang/es.json`
and `lang/en.json` (+ a resolution test) · **the 248 prior tests stay green.**

## 6. Open questions for the gate

- **A. `OrganizationScope` not on the auth `User` model (listing-only) — confirm.** A
  global scope on the framework auth model would break login. Recommend listing-only scope
  for `User`; global `booted()` scope for Article/Branch/Director/Representative. Confirm.
- **B. `users.organization_id` nullable + restrict FK — confirm.** super_admin is org-less.
  Confirm (vs. NOT NULL per §6.3.6 with a sentinel org).
- **C. `articles.organization_id`/`branch_id` nullable + restrict FK, stamped on write —
  confirm.** No safe backfill for existing rows; new rows always stamped. Confirm (vs. a
  data migration to NOT NULL now).
- **D. Is `administrator` org-confined or org-wide for Branches/Representatives?** §10.2
  says "All within org"; the binary scope treats administrator as unconfined (org-wide).
  Recommend: keep administrator unconfined (simplest; an administrator belongs to one org
  anyway) OR confine administrator like manager so only super_admin is cross-org. Confirm
  which — the crown manager-confined / super_admin-cross-org guarantee holds either way.
- **E. Defer the full `Admin\UserController` CRUD (AUTH-04 move-user-across-orgs)?**
  Recommend defer to the User-management slice; this slice lands only the FK + the listing
  scope. Confirm.
- **F. Surface a `branch_id` picker on the 002 Article editor this slice, or defer?**
  Recommend land the column + FK + auto-stamp now; defer the editor picker to a follow-up.
  Confirm.
- **G. `Organization`/`Branch`/`Director`/`Representative` in `app/Domain/Organization/Models`
  (not `app/Models`) with `newFactory()`** — confirm the same model-location convention as
  slice-002 Content (yes, recommended).

## Appendix — fictional municipality seed (demo, no real data)

A small fictional set for the demo (names invented, NOT real municipalities tied to real
people): e.g. `Ciudad Norte / Estado Demo`, `Villa Sur / Estado Demo`,
`Puerto Centro / Estado Demo`, `San Ejemplo / Estado Demo`, `Lago Modelo / Estado Demo`.
The exact list is finalized in the seeder; the only hard requirement is **fictional**
content per the PII rules.
