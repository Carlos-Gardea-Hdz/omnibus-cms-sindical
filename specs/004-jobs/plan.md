# Plan 004 — Jobs Domain (the HOW)

> **Phase:** Plan. Turns `spec.md` into architecture + the exact files to touch.
> **Gated on:** CLAUDE.md §No-negociables + SPEC §5.2/§6.1/§6.4/§11.2 arch + FK rules.
> **Build order:** enum → migration → model → factory/seed → DTOs → exception → Actions →
> controllers/routes/exception-render → pages/i18n → tests (the two crowns last). Backend
> before frontend; the org-stamp Actions are the spine everything hangs on.

## 1. Architecture overview

```
HTTP (Inertia)                              Domain (Illuminate\Http-free) + App\Support
─────────────                               ───────────────────────────────────────────
Admin\JobController (editor+, org.scope)    Domain\Jobs\Data\{CreateJobPosting,ToggleJobStatus}Data
Public\JobController (unconfined+active)     Domain\Jobs\Actions\{Create,Update,Delete,ToggleStatus}JobPostingAction
                                            Domain\Jobs\Enums\JobStatus (#[TypeScript])
bootstrap/app.php (render                    Domain\Jobs\Exceptions\InvalidJobTransitionException
  InvalidJobTransitionException →            Domain\Jobs\Models\JobPosting (OrganizationScope in booted())
  302 + status field error / 422 JSON)      App\Support\BelongsToOrganizationAssertion (DECISION C — moved trait)
                                            App\Support\OrganizationScope / OrganizationContext (slice 003 — reused)
```

**Rule compliance:** Actions return `JobPosting`/void, throw `InvalidJobTransitionException`
or `ValidationException`; never import `Illuminate\Http`. Controllers anemic
(DTO→Action→response; `Auth::user()` for the actor; `request()` only for the index status
filter, exactly like `Admin\ArticleController`). DTOs are the only validation. The lifecycle
exception renders to 302+field-error (web) / 422 (JSON) in `bootstrap/app.php`, mirroring
`InvalidArticleTransitionException`.

### DECISION C (cross-row branch assertion home) — RESOLVED

The arch test forbids `App\Domain\Jobs` from importing `App\Domain\Organization`. So the
shared `ResolvesBranchForOrganization` logic moves to a **domain-neutral** home:

- **Create `app/Support/BelongsToOrganizationAssertion.php`** (a trait, or a final service
  — trait keeps parity with the existing pattern) holding
  `assertBranchBelongsToOrganization(int $branchId, ?int $organizationId): void`. It does a
  scope-free `Branch::withoutGlobalScope(OrganizationScope::class)->whereKey()->where('organization_id')->exists()`
  check and throws `ValidationException` on `branch_id`.
- **Refactor** `App\Domain\Organization\Actions\ResolvesBranchForOrganization` to delegate
  to (or be replaced by) the `App\Support` trait — keeping the 003 Representative tests
  green. App\Support is already the home of `OrganizationScope`/`OrganizationContext`, so
  importing `Branch` there is acceptable (App\Support is not a domain; it already imports
  domain models indirectly via the scope). **Verify the 003 suite stays green after the
  move.**
- The Jobs Actions `use App\Support\BelongsToOrganizationAssertion;` — arch-clean.

> If moving the trait proves to ripple, fallback (i): a thin Jobs-domain trait
> `App\Domain\Jobs\Actions\ResolvesJobBranch` that itself only touches `App\Support` +
> the Jobs `JobPosting` cannot see Branch (forbidden). So the **App\Support move is the
> required path** — the Jobs domain MUST NOT import the Organization `Branch` model. The
> assertion lives in App\Support, which may import `Organization\Models\Branch`.

## 2. Data model & migration (reversible, dependency-safe)

> **ID type:** `$table->id()` **bigint** — matches live `users`/`articles`/`branches` ids
> (slices 001–003). Consistent deviation from the SPEC's ULID note, as slice-003 already
> established.

**File:** `database/migrations/2026_06_20_000500_create_job_postings_table.php`
(timestamp after the slice-003 `...0407` retrofit so FKs to organizations/branches/users
resolve).

```php
Schema::create('job_postings', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
    $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
    $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
    $table->string('title', 100);
    $table->text('description');
    $table->string('schedule', 100);
    $table->string('contact_info', 100);
    $table->bigInteger('salary_min_cents')->nullable();
    $table->bigInteger('salary_max_cents')->nullable();
    $table->string('salary_display', 50)->nullable();
    $table->string('status', 20)->default('active'); // JobStatus backing value; cast in model
    $table->timestamps();
    $table->softDeletes();

    $table->index('organization_id');
    $table->index('branch_id');
    $table->index('status');
    $table->index(['organization_id', 'status', 'created_at']);
});
// down(): Schema::dropIfExists('job_postings');
```

All three FKs RESTRICT (§6.4). No CASCADE — `job_postings` is a leaf (nothing FKs to it).

## 3. JobStatus enum (DECISION B — locked)

`app/Domain/Jobs/Enums/JobStatus.php`, `#[TypeScript]`, `string`-backed, mirror
`ArticleStatus`:

```
Draft   = 'draft'    → [Active, Closed]
Active  = 'active'   → [Paused, Closed]
Paused  = 'paused'   → [Active, Closed]
Closed  = 'closed'   → []   (terminal)
```

`allowedTransitions(): array`, `canTransitionTo(self): bool` (strict in_array),
`labelKey(): string` → `'job_status.'.$value`, `color(): string` (magenta palette:
Draft `#A03CC7`, Active `#DD00FF`, Paused `#E647FF`, Closed `#9211CF`). Default on create
is `Active` (JOB-01).

## 4. Model

`app/Domain/Jobs/Models/JobPosting.php` — `final`, `HasFactory`, `SoftDeletes`,
`booted()` registers `self::addGlobalScope(new OrganizationScope)`. Full `@property`/
`@property-read` PHPDoc for PHPStan L9 (non-nullable `belongsTo` NOT `|null`):
`organization` (Organization), `branch` (Branch), `creator` (User) are all NOT-NULL RESTRICT
FKs → no `|null`. Casts: `status => JobStatus::class`, `salary_min_cents => integer`,
`salary_max_cents => integer`. `$fillable` = all writable columns. `newFactory()` returns
`JobPostingFactory::new()`. Relations: `organization()`, `branch()`, `creator()` (→ `User`,
FK `created_by`).

## 5. DTOs (Spatie Data, `#[TypeScript]`)

**`app/Domain/Jobs/Data/CreateJobPostingData.php`** (route-agnostic, store+update):

```php
#[Required, Max(100)] public string $title,
#[Required] public string $description,
#[Required, Max(100)] public string $schedule,
#[Required, Max(100)] public string $contact_info,
#[Required, Exists('organizations', 'id')] public int $organization_id, // NOT trusted for confined caller
#[Required, Exists('branches', 'id')]     public int $branch_id,
#[Nullable] public ?int $salary_min_cents = null,
#[Nullable] public ?int $salary_max_cents = null,
#[Nullable, Max(50)] public ?string $salary_display = null,
```

`rules()` closure (DECISION E): `salary_min_cents`/`salary_max_cents` → `['nullable','integer','min:0']`;
a cross-field closure on `salary_max_cents` asserting (when both present) `max >= min`, else
`$fail(__('jobs.error.salary_range'))`. `status` is NOT in this DTO (default active).

**`app/Domain/Jobs/Data/ToggleJobStatusData.php`**:
```php
#[Required] public JobStatus $status,
```
A bad value is a 302 validation error (enum coercion); the legality (`canTransitionTo`) is
the Action's job.

## 6. Exception

`app/Domain/Jobs/Exceptions/InvalidJobTransitionException.php` — `final`, extends
`\DomainException` (mirror `InvalidArticleTransitionException`). Rendered in
`bootstrap/app.php`:
```php
$exceptions->render(function (InvalidJobTransitionException $e, Request $request) {
    return $request->expectsJson()
        ? response()->json(['message' => $e->getMessage()], 422)
        : back()->withErrors(['status' => $e->getMessage()]);
});
```

## 7. Actions (`DB::transaction`, Illuminate\Http-free)

**`CreateJobPostingAction`** (mirror `CreateRepresentativeAction`):
```php
public function __construct(private readonly OrganizationContext $context) {}
public function handle(CreateJobPostingData $data, User $actor): JobPosting
{
    $organizationId = $this->context->isUnconfined()
        ? $data->organization_id
        : $this->context->organizationId();
    $this->assertBranchBelongsToOrganization($data->branch_id, $organizationId); // App\Support trait
    return DB::transaction(fn (): JobPosting => JobPosting::create([
        'organization_id' => $organizationId,
        'branch_id' => $data->branch_id,
        'created_by' => $actor->getKey(),
        'title' => $data->title,
        'description' => $data->description,   // PLAIN TEXT — no SanitizesContent (Decision A)
        'schedule' => $data->schedule,
        'contact_info' => $data->contact_info,
        'salary_min_cents' => $data->salary_min_cents,
        'salary_max_cents' => $data->salary_max_cents,
        'salary_display' => $data->salary_display,
        'status' => JobStatus::Active,         // JOB-01 default
    ]));
}
```

**`UpdateJobPostingAction`** — same server-side org resolve + branch assertion; `fill()` the
writable columns (NOT `status` — that's the toggle Action's job) on the bound model inside a
transaction. A confined manager cannot move the job to another org (org resolved
server-side, payload org ignored for confined).

**`DeleteJobPostingAction`** — `DB::transaction(fn () => $job->delete())` (SoftDelete). No
restrict pre-check (leaf entity). Graceful by construction.

**`ToggleJobStatusAction`**:
```php
public function handle(JobPosting $job, JobStatus $target): JobPosting
{
    if (! $job->status->canTransitionTo($target)) {
        throw new InvalidJobTransitionException(__('jobs.error.invalid_transition'));
    }
    return DB::transaction(function () use ($job, $target): JobPosting {
        $job->update(['status' => $target]);
        return $job;
    });
}
```

## 8. Controllers + routes

**`app/Http/Controllers/Admin/JobController.php`** (anemic, mirror `Admin\ArticleController`):
- `index(): Response` — `JobPosting::query()->with(['branch:id,name','creator:id,name'])`
  + optional `?status=` filter + `latest('id')->paginate(15)->through(mapRow)`; renders
  `Admin/Jobs/Index` with `jobs` (data/links/meta), `branchOptions`, `statuses`, `filters`.
  The global scope confines a manager automatically.
- `create(): Response` — renders `Admin/Jobs/Create` with `branchOptions` (+ org options for
  an unconfined admin, like the Representative form).
- `store(CreateJobPostingData $data, CreateJobPostingAction $action): RedirectResponse` —
  `Auth::user()` actor → `redirect()->route('admin.jobs.index')->with('success', __('jobs.created'))`.
- `edit(JobPosting $job): Response` — renders `Admin/Jobs/Edit` with the snake_case job + options.
- `update(JobPosting $job, CreateJobPostingData $data, UpdateJobPostingAction $action)` → 302 + `jobs.updated`.
- `destroy(JobPosting $job, DeleteJobPostingAction $action)` → 302 + `jobs.deleted`.
- `toggleStatus(JobPosting $job, ToggleJobStatusData $data, ToggleJobStatusAction $action)` →
  `back()->with('success', __('jobs.status_changed'))`.

**`app/Http/Controllers/Public/JobController.php`** (anemic, mirror `Public\ArticleController`):
- `index(): Response` — `JobPosting::query()->withoutGlobalScope(OrganizationScope::class)->where('status', JobStatus::Active)->with('branch:id,name')->latest('created_at')->paginate(12)` → `Jobs/Index`.
- `show(JobPosting|string $job)` — resolve by id WITHOUT the scope, `where('status', JobStatus::Active)->findOrFail()` (a non-active job 404s) → `Jobs/Show`. (Bind manually like the public article, NOT scoped route binding, so a confined editor browsing the public site still sees active jobs.)

**Routes** (`routes/web.php`):
```php
// inside the existing ['auth','role:editor','org.scope'] admin group:
Route::get('/admin/jobs', [JobController::class, 'index'])->name('admin.jobs.index');
Route::get('/admin/jobs/create', [JobController::class, 'create'])->name('admin.jobs.create');
Route::post('/admin/jobs', [JobController::class, 'store'])->name('admin.jobs.store');
Route::get('/admin/jobs/{job}/edit', [JobController::class, 'edit'])->name('admin.jobs.edit');
Route::put('/admin/jobs/{job}', [JobController::class, 'update'])->name('admin.jobs.update');
Route::delete('/admin/jobs/{job}', [JobController::class, 'destroy'])->name('admin.jobs.destroy');
Route::post('/admin/jobs/{job}/status', [JobController::class, 'toggleStatus'])->name('admin.jobs.status');

// public (top level, no auth):
Route::get('/jobs', [PublicJobController::class, 'index'])->name('jobs.index');
Route::get('/jobs/{job}', [PublicJobController::class, 'show'])->name('jobs.show');
```

> NOTE: SPEC §7.2 places `/admin/jobs*` in the **Editor+** table → put the routes in the
> existing `role:editor` group. The status toggle is also editor+ (JOB-02 "Editor scoped to
> own organization"). Do NOT gate the toggle at manager+ (that was an Article-specific rule).

## 9. Inertia pages + prop contracts (snake_case)

`Admin/Jobs/{Index,Create,Edit}` + public `Jobs/{Index,Show}`. Reuse the admin shell layout,
form primitives (text input, textarea, select, money input), status badge (using
`JobStatus.color()` / `labelKey()`), pagination. Generated `JobStatus` TS enum is **type-only
imported** (never value-imported). Exact prop shapes in the CONTRACT (returned to the
orchestrator). Magenta theme, dark/light, bilingual.

## 10. Factory + seed

`database/factories/JobPostingFactory.php` (mirror `RepresentativeFactory`): default
`organization_id`/`branch_id` via a shared `Organization`/`Branch` factory, `created_by` via
a `User` factory of that org, fake title/description/schedule/contact_info, random
salary cents, `status => JobStatus::Active`. States: `forOrganization()`, `forBranch()`,
`createdBy(User)`, `active()`, `draft()`, `paused()`, `closed()`. Add a few fictional jobs to
the demo seeder (no real data).

## 11. i18n keys (BOTH lang/es.json + lang/en.json)

`jobs.created`, `jobs.updated`, `jobs.deleted`, `jobs.status_changed`,
`jobs.error.invalid_transition`, `jobs.error.branch_org_mismatch`, `jobs.error.salary_range`,
`job_status.draft`, `job_status.active`, `job_status.paused`, `job_status.closed`.
(Reuse `representatives.error.branch_org_mismatch` IF the assertion trait keeps one key;
plan keeps a `jobs.*` key for clarity — the trait's message key is passed in / overridable.)

## 12. Tests (the two crowns LAST)

See the CONTRACT for the exact, falsifiable list. Arch test gets a Jobs boundary rule
(already in SPEC §11.2) + the App\Support trait must NOT break the per-domain isolation.
Add the `->ignoring(['__','now','request'])` + Carbon/Database\Factories allow-list entries
the prior domains needed.

## 13. Files to touch (summary)

NEW: `app/Domain/Jobs/Enums/JobStatus.php`,
`app/Domain/Jobs/Models/JobPosting.php`,
`app/Domain/Jobs/Data/CreateJobPostingData.php`,
`app/Domain/Jobs/Data/ToggleJobStatusData.php`,
`app/Domain/Jobs/Exceptions/InvalidJobTransitionException.php`,
`app/Domain/Jobs/Actions/{Create,Update,Delete,ToggleStatus}JobPostingAction.php`,
`app/Support/BelongsToOrganizationAssertion.php`,
`app/Http/Controllers/Admin/JobController.php`,
`app/Http/Controllers/Public/JobController.php`,
`database/migrations/2026_06_20_000500_create_job_postings_table.php`,
`database/factories/JobPostingFactory.php`,
`resources/js/Pages/Admin/Jobs/{Index,Create,Edit}.tsx`,
`resources/js/Pages/Jobs/{Index,Show}.tsx`,
plus the test files.

EDIT: `routes/web.php` (admin group + public routes), `bootstrap/app.php` (render the
transition exception), `lang/es.json` + `lang/en.json` (keys),
`app/Domain/Organization/Actions/ResolvesBranchForOrganization.php` (delegate to the moved
App\Support trait — keep 003 green), the demo seeder, `tests/Arch/ArchitectureTest.php`
(Jobs boundary + allow-list).
