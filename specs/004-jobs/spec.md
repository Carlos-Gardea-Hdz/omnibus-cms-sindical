# Spec 004 — Jobs Domain (org-scoped job board + public active-jobs listing)

> **Phase:** Specify (the WHAT and WHY, not the HOW).
> **SSOT:** `SPEC.md` (§3.4 Jobs JOB-01..03 + the **Salary Transformation Rule**,
> §6.3.10 `job_postings` table + §6.4 FK summary [all three FKs **RESTRICT**], §7.1
> public `/jobs` + `/jobs/{id}`, §7.2 Admin Editor+ `/admin/jobs*`, §10.2 RBAC matrix,
> §11.2 Jobs arch boundary, §5.5 `Money` VO [integer-cents law], §1.4/§1.5 magenta theme).
> **Reference impl (same stack):** the CMS slice-003 Organization domain — the
> `Representative` Create/Update Actions (`OrganizationContext` server-side org-stamp +
> `ResolvesBranchForOrganization` cross-row FK assertion), `OrganizationScopeIsolationTest`
> (the falsifiable cross-org READ + WRITE crown), the slice-002 `Public\ArticleController`
> (unconfined + status-filtered public read) and `Admin\ArticleController` (anemic CRUD +
> graceful restrict-delete). `omnibus-uniges` remains the upstream Action/DTO/state-machine
> pattern source.
> **Built on (DO NOT break — the 003 suite is green):** slice 001 Identity (`UserRole`
> ladder, `EnsureRole` `role:<level>`, `app/Models/User` + its `organization_id` FK),
> slice 002 Content (the `Public\ArticleController` unconfined+published read pattern, the
> `bootstrap/app.php` exception-render pattern), slice 003 Organization (`Organization`,
> `Branch`, the **org-scoping spine**: `App\Support\OrganizationContext` +
> `App\Support\OrganizationScope` + `EnsureOrganizationScope` `org.scope` middleware ordered
> before `SubstituteBindings`, the `ResolvesBranchForOrganization` trait), the arch suite,
> the magenta theme + `lang/{es,en}.json`.
> **Status:** DRAFT — awaiting human review gate before `/sdd-plan` / build.

## 1. Problem & why

The CMS is a corporate/union platform whose member-facing value includes a **job board**:
each organization (and a specific branch of it) posts openings, and the public site shows
the **active** ones. SPEC §3.4 (JOB-01..03), §6.3.10 (`job_postings`), §7.1 (`/jobs`,
`/jobs/{id}`) and §7.2 (`/admin/jobs*`) define exactly this entity.

Slices 001–003 built the auth ladder, the content core and the **org-scoping spine**.
This slice is the FIRST consumer of that spine for a brand-new org-owned entity that did
**not** exist before: `job_postings` is born org-scoped (unlike Article, which was
retrofitted). It exercises the spine end to end:

1. an org-scoped model that registers `OrganizationScope` in `booted()` from birth;
2. **tenant-WRITE isolation** — the global scope filters SELECT/route-binding but does
   NOT constrain INSERT/UPDATE column values, so the Create/Update Actions MUST stamp
   `organization_id` SERVER-SIDE from `OrganizationContext` for a confined caller and only
   trust a payload `organization_id` for an unconfined admin (the slice-003 BLOCKER);
3. a cross-row FK (`branch_id`) that MUST be asserted to belong to the resolved org (the
   `ResolvesBranchForOrganization` pattern, generalized to Jobs);
4. a **public read path** that is unconfined (a visitor of any/no org sees the board) but
   **status-filtered** to `active` only — never leaking `draft`/`paused`/`closed` (the
   slice-003 public-leak BLOCKER);
5. a **`JobStatus` lifecycle** (`draft → active → paused → closed`) — the first non-Content
   state machine, with an enum guard and a graceful illegal-transition render;
6. **integer-cents money** — `salary_min_cents` / `salary_max_cents` as `BIGINT`, never
   float (SPEC §5.5 law).

Getting these right the first time is the whole point: the cross-org write test and the
public-active-only test are the falsifiable crowns.

## 2. Scope

### In scope (Jobs MVP — the org-scoped job board + public active listing)

1. **`JobStatus` backed enum** (`app/Domain/Jobs/Enums/JobStatus.php`, `#[TypeScript]`) —
   `draft`, `active`, `paused`, `closed` (SPEC §3.4 JOB-01 default `active`, JOB-02 toggle
   ladder). Owns `allowedTransitions()`, `canTransitionTo()`, `labelKey()`, `color()`
   (magenta palette). No magic strings. Mirror `ArticleStatus`.

2. **`job_postings` table + `JobPosting` model** (SPEC §6.3.10) — bigint id (matches the
   live `users`/`articles`/`branches` ids; Deviation, consistent with slice-003);
   `organization_id` (RESTRICT FK), `branch_id` (RESTRICT FK), `created_by` (→ `users`,
   RESTRICT FK), `title` (≤100), `description` (TEXT), `schedule` (≤100), `contact_info`
   (≤100), `salary_min_cents` (BIGINT nullable), `salary_max_cents` (BIGINT nullable),
   `salary_display` (≤50 nullable), `status` (string column, `JobStatus` cast, default
   `active`), timestamps, `SoftDeletes`. Indexes per §6.3.10:
   `organization_id`, `branch_id`, `status`, `[organization_id, status, created_at]`.
   Registers `OrganizationScope` in `booted()` from birth.

3. **`CreateJobPostingData` DTO** (Spatie Data, `#[TypeScript]`) — one route-agnostic DTO
   for store + update. `title`/`schedule`/`contact_info` Required+Max, `description`
   Required, `branch_id` Required+`Exists('branches','id')`, `organization_id`
   Required+`Exists('organizations','id')` (route-agnostic — NOT trusted as the tenant id
   for a confined caller; the Action resolves that), `salary_min_cents`/`salary_max_cents`
   nullable integers ≥ 0 with `max ≤` cross-field rule, `salary_display` nullable Max(50).
   Status is NOT in the create/update DTO (created as `active` by default per JOB-01; the
   lifecycle is a separate toggle DTO/Action).

4. **`ToggleJobStatusData` DTO** (Spatie Data, `#[TypeScript]`) — carries the target
   `JobStatus`; the Action enforces `canTransitionTo()`.

5. **Actions** (Illuminate\Http-free, `DB::transaction`, one op each):
   - `CreateJobPostingAction` — stamps `organization_id` SERVER-SIDE from
     `OrganizationContext` for a confined caller (mirrors `CreateRepresentativeAction`);
     asserts `branch_id` belongs to the resolved org; stamps `created_by` from the actor;
     `status = active` (JOB-01 default); `description` is plain text (NOT TipTap) so NO
     `SanitizesContent` — see §6 Decisions.
   - `UpdateJobPostingAction` — same server-side org-stamp + branch assertion; cannot move
     a job to another org via the payload for a confined manager.
   - `DeleteJobPostingAction` — SoftDelete (SPEC §7.2 "Soft delete"). No children → NO
     restrict-delete pre-check needed (job_postings is a leaf; nothing FKs to it). Graceful
     by construction.
   - `ToggleJobStatusAction` — `canTransitionTo()` guard; throws
     `InvalidJobTransitionException` on an illegal edge (rendered 302 + `status` field
     error / 422 JSON, mirroring `InvalidArticleTransitionException`).

6. **`InvalidJobTransitionException`** + its `bootstrap/app.php` render (302 + `status`
   field error on web, 422 JSON) — mirror `InvalidArticleTransitionException`.

7. **`ResolvesBranchForOrganization` reuse** — the cross-row FK assertion the Jobs Actions
   need is IDENTICAL to the Representative one. DECISION (see §6): generalize the existing
   `App\Domain\Organization\Actions\ResolvesBranchForOrganization` trait into a
   domain-neutral home OR duplicate a Jobs-domain trait — resolved in plan.md so the arch
   boundary (`Jobs` may only use `Shared` + `Illuminate` + `Spatie`) stays green.

8. **`Admin\JobController`** (anemic, ≤15 lines/method) — `index` (org-scoped list,
   paginated, snake_case rows), `create`, `store`, `edit`, `update`, `destroy`, plus a
   `toggleStatus` (JOB-02). Routes under the `['auth','role:editor','org.scope']` group
   (SPEC §7.2 places `/admin/jobs*` in the **Editor+** table — editor may CRUD; org.scope
   confines a manager/editor). DTO resolved via method signature → web validation is 302 +
   session errors, never 422.

9. **`Public\JobController`** (anemic) — `index` (active jobs, unconfined, ordered
   `created_at DESC`, paginated) + `show` (a single active job by id). BOTH bypass
   `OrganizationScope` (`withoutGlobalScope`) BUT filter to `JobStatus::Active` only — a
   `draft`/`paused`/`closed` job of ANY org 404s on `/jobs/{id}` and never appears on
   `/jobs`. Mirrors `Public\ArticleController`.

10. **Routes** (SPEC §7.1, §7.2) — public `GET /jobs` (`jobs.index`),
    `GET /jobs/{job}` (`jobs.show`); admin `GET /admin/jobs` (`admin.jobs.index`),
    `GET /admin/jobs/create`, `POST /admin/jobs`, `GET /admin/jobs/{job}/edit`,
    `PUT /admin/jobs/{job}`, `DELETE /admin/jobs/{job}`, `POST /admin/jobs/{job}/status`
    (`admin.jobs.status`). All `->name()`d, no closures.

11. **Inertia pages** — `Admin/Jobs/Index`, `Admin/Jobs/Create`, `Admin/Jobs/Edit`
    (admin shell + form primitives) and public `Jobs/Index`, `Jobs/Show`. Magenta theme,
    dark/light, bilingual via the locale hook. Snake_case props; a prop-contract test per
    page. (Frontend depth is plan-scoped; the contract pins the prop shapes.)

12. **`JobPostingFactory`** + a demo seed addition (fictional data only). Factory states:
    `forOrganization()`, `forBranch()`, `active()`, `draft()`, `paused()`, `closed()`,
    `createdBy()`.

13. **i18n** — every `__()` key in BOTH `lang/es.json` AND `lang/en.json`:
    `jobs.created|updated|deleted|status_changed`, `jobs.error.invalid_transition`,
    `jobs.error.branch_org_mismatch` (or reuse the representatives key — plan decides),
    `jobs.error.salary_range` (min>max), `job_status.draft|active|paused|closed`.

14. **Tests** — CRUD, validation 302s, the lifecycle (legal + illegal transitions), the
    org isolation crown (READ + WRITE — a confined manager cannot plant/move a job into
    another org), the public active-only crown, role gating, the salary-cents invariant,
    prop-contract per page, lang-key resolution, the `JobStatus` unit test, and the arch
    boundary (Jobs only uses Shared/Illuminate/Spatie).

### Out of scope / DEFERRED (with notes)

- **JobApplication / applicant / ATS pipeline.** SPEC §3.4 defines NO application or
  applicant sub-entity — JOB-01..03 are posting CRUD, status toggle, and the public active
  listing only. There is no `job_applications` table in §6.3, no applicant route in §7.
  **DEFERRED — not part of this slice; note for a future slice if the product adds it.**
- **Salary ETL from the legacy VARCHAR** (`"15,000 - 20,000"` → cents). SPEC §3.4 + §12.3
  describe the migration transform; that belongs to the **ETL slice (§12)**, not the
  greenfield Jobs build. This slice accepts `salary_min_cents`/`salary_max_cents` directly
  and renders `salary_display`. **DEFERRED to §12 ETL.**
- **Meilisearch indexing of jobs.** SPEC §9.1 indexes articles; jobs search is not in
  §3.4. **DEFERRED.**
- **Landing-page "6 latest active jobs"** (SPEC §3.4 JOB-03 / NEWS-05). The Landing
  controller already exists (slice 002). Wiring the 6-jobs widget into Landing is a small
  follow-on; this slice ships the public `/jobs` board + `JobPosting` read model it needs.
  **NOTE:** plan may include the Landing widget if cheap; otherwise DEFERRED with the read
  model ready.
- **Branch picker UI in the admin job form for an unconfined admin** mirrors the
  Representative form; reuse the existing org→branch dropdown primitive. Not a new concern.

## 3. Acceptance scenarios (When… Then…)

**JOB-01 — Create**
- When an **editor** of org A submits a valid job (title ≤100, description, schedule ≤100,
  contact_info ≤100, branch of org A), Then a `job_posting` is created with
  `status = active`, `organization_id = A` (server-stamped, NOT from payload),
  `created_by = the editor`, and a 302 + success flash.
- When the title is >100 / description empty / schedule >100 / contact_info >100, Then the
  response is **302 + session errors** on the offending field(s) (never 422), and no row
  is written.
- When `salary_min_cents > salary_max_cents`, Then 302 + a `salary_max_cents` (or
  `salary_min_cents`) field error; no row written.
- When `branch_id` belongs to a DIFFERENT org than the resolved org, Then 302 + a
  `branch_id` field error; no row written.

**Tenant WRITE isolation (the crown)**
- When a **confined manager/editor of org A** POSTs a create with `organization_id = B`
  forged in the payload, Then the job is created under **org A** (server-stamped), NOT org
  B — falsifiable: no row with that title exists under org B, one exists under org A.
- When a confined manager of org A PUTs its OWN job with `organization_id = B`, Then the
  job stays in org A (never moves tenants).
- When an **unconfined super_admin** POSTs a create with `organization_id = B` (+ a branch
  of B), Then the job IS created under org B (payload honoured for an admin).

**JOB-02 — Toggle status (lifecycle)**
- When an editor toggles `draft → active`, `active → paused`, `paused → active`,
  `active → closed`, `paused → closed`, `draft → closed`, Then the status changes (302 +
  flash).
- When an editor attempts an ILLEGAL edge (e.g. `closed → active`, or a self→self), Then
  302 + a `status` field error (never a 500), and the status is unchanged.

**JOB-03 — Public active listing (the public crown)**
- When ANY visitor (anonymous, or a logged-in org-confined editor) GETs `/jobs`, Then the
  list shows **only `active`** jobs across ALL orgs, ordered `created_at DESC` — no
  `draft`/`paused`/`closed` ever appears.
- When a visitor GETs `/jobs/{id}` for an `active` job, Then a 200 with the job detail.
- When a visitor GETs `/jobs/{id}` for a `draft`/`paused`/`closed` job (of any org), Then
  a **404** — never a 200 leak.

**Tenant READ isolation**
- When a manager of org A GETs `/admin/jobs`, Then only org-A jobs appear (falsifiable:
  `JobPosting::withoutGlobalScope(...)->count()` exceeds the visible count).
- When a manager of org A GETs `/admin/jobs/{job}/edit` for an org-B job, Then a **404**.
- When a super_admin GETs `/admin/jobs`, Then ALL orgs' jobs appear.

**Role gating (SPEC §7.2, §10.2)**
- When a guest hits any `/admin/jobs*`, Then 302 → login.
- When an authenticated user of EVERY role ≥ editor reaches `/admin/jobs`, Then 200 (editor
  is the lowest rung for the Jobs group per §7.2).

**Money invariant**
- A persisted job's `salary_min_cents`/`salary_max_cents` are integer cents (BIGINT); no
  float is ever stored. (Unit + DB-shape assertion.)

## 4. Non-goals / invariants restated

- Domain code never imports `Illuminate\Http`; `OrganizationContext` (App\Support) is OK.
- Controllers anemic (≤15 lines/method), DTO→Action→response.
- `declare(strict_types=1)`, `final`, `readonly` VO/DTO, backed enums, no magic strings.
- Migrations reversible; FKs RESTRICT (§6.4); `SoftDeletes`.
- Web validation = 302 + session errors; Inertia props snake_case; generated TS enum is
  type-only.
- The 003 suite stays green (the public read path bypasses the scope explicitly; CLI/seed
  paths default unconfined).

## 5. Success = these are falsifiable and green

The cross-org WRITE test (a confined manager CANNOT plant/move a job into another org) and
the public active-only test (a `draft`/`paused`/`closed` job 404s on `/jobs/{id}` and is
absent from `/jobs`) are the load-bearing, must-be-RED-against-a-naive-impl tests.

## 6. Decisions to lock in plan.md

- **A. `description` is PLAIN TEXT, not TipTap.** SPEC §6.3.10 types it `TEXT` and §3.4
  lists no rich-content rule (unlike NEWS-01's JSONB/TipTap). So NO `SanitizesContent`,
  NO JSONB cast — a plain string column, escaped at render (React auto-escapes; never
  `dangerouslySetInnerHTML`). This is the deliberate divergence from Article.
- **B. The `JobStatus` transition graph.** Proposed (a superset of the JOB-02 linear
  `draft→active→paused→closed`, adding the reactivate edge `paused→active`):
  `draft → active, closed`; `active → paused, closed`; `paused → active, closed`;
  `closed → ` (terminal). Lock the exact edges in plan.md.
- **C. The cross-row branch assertion home.** The Jobs Actions need the
  `ResolvesBranchForOrganization` logic, but the arch test forbids `Jobs` from importing
  `App\Domain\Organization`. Options: (i) move the trait to `App\Support`
  (domain-neutral, like `OrganizationScope`) and have both domains use it; (ii) a small
  Jobs-domain copy. Plan.md picks one; the arch boundary MUST stay green.
- **D. `branch_id` required or nullable on a job?** SPEC §6.3.10 lists `branch_id` as a
  RESTRICT FK with no "NULLABLE" note → **required**. Lock as NOT NULL.
- **E. The salary cross-field rule** (`min ≤ max`, both ≥ 0) lives in the DTO `rules()`
  closure (like ArticleData's tiptap rule), reporting on a single field key.
