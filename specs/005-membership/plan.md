# Plan 005 — Membership Domain (the HOW)

> **Phase:** Plan. Turns `spec.md` into architecture + the exact files to touch.
> **Gated on:** CLAUDE.md §No-negociables + SPEC §6.4 FK rules + §10.5 PII + §11.2 arch.
> **Build order:** enum → migration → model → factory/seed → validation rules → DTO →
> exception → Actions → controllers/routes/exception-render → DeleteMunicipalityAction
> extension → pages/i18n → tests (the WRITE crown + PII + municipality regression LAST).
> Backend before frontend; the public `RegisterMemberAction` + the lifecycle Actions are the spine.

## 1. Architecture overview

```
HTTP (Inertia)                                Domain (Illuminate\Http-free) + App\Support
─────────────                                 ───────────────────────────────────────────
Public\MemberController (unconfined,          Domain\Membership\Data\RegisterMemberData
  throttle:5,60, create+store)                Domain\Membership\Actions\{RegisterMember,ApproveMember,RejectMember}Action
Admin\MemberController (manager+, org.scope,  Domain\Membership\Enums\MemberStatus (#[TypeScript])
  index+approve+reject)                       Domain\Membership\Exceptions\InvalidMemberTransitionException
                                              Domain\Membership\Models\Member (OrganizationScope in booted(); curp/rfc encrypted)
bootstrap/app.php (render                      Domain\Shared\Rules\{CurpFormat,RfcFormat,MexicanPhone}
  InvalidMemberTransitionException →           App\Support\OrganizationScope / OrganizationContext (slice 003 — reused, READ only here)
  302 + status field error / 422 JSON)
EDIT: Domain\Organization\Actions\DeleteMunicipalityAction (add member referrer to the in-use pre-check)
```

**Rule compliance:** Actions return `Member`/void, throw `InvalidMemberTransitionException` or
rely on DTO `ValidationException`; never import `Illuminate\Http`. Controllers anemic (public
store = DTO→Action→response; admin approve/reject = bound `{member}`→Action→response; index uses
`request()` only for the `?status=` filter, like `Admin\JobController`). DTOs are the only
validation. The lifecycle exception renders to 302+field-error (web) / 422 (JSON) in
`bootstrap/app.php`, mirroring `InvalidJobTransitionException`.

### KEY DIFFERENCE vs slice 004 (do NOT blindly copy the Jobs Create crown)

- **No admin create + no `branch_id`** → there is NO `AssertsBranchBelongsToOrganization` analogue
  and NO admin server-side org-stamp Action. The only WRITE-isolation surface is the lifecycle
  (approve/reject), which is protected by the **route-model-binding 404** (the `org.scope`
  middleware runs before `SubstituteBindings`, so a cross-org `{member}` is unresolvable). No new
  branch-assertion trait is written.
- **The public path is unconfined** → `RegisterMemberAction` persists the payload `organization_id`
  AS-IS (Decision F). It is the OPPOSITE of `CreateJobPostingAction` (no `OrganizationContext`
  override). Registration must work for an anonymous user with no org context.

## 2. Data model & migration (reversible, dependency-safe)

> **ID type:** `$table->id()` **bigint** (Decision A) — matches every prior CMS id.

**File:** `database/migrations/2026_06_20_000600_create_members_table.php`
(timestamp AFTER `...0500_create_job_postings` so the FKs to `organizations`/`municipalities`
resolve — both already migrated by `...0401`/`...0400`).

```php
Schema::create('members', function (Blueprint $table): void {
    $table->id();
    // organization_id is NULLABLE + SET NULL (§6.4): a deleted org NULLS its members,
    // never blocks. nullOnDelete() requires the column be nullable.
    $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
    $table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
    // PII — encrypted at rest (§10.5). TEXT because ciphertext is longer than the plaintext.
    $table->text('curp');
    $table->text('rfc');
    $table->string('first_name', 100);
    $table->string('last_name_paternal', 100);
    $table->string('last_name_maternal', 100);
    $table->date('date_of_birth');
    $table->text('address');
    $table->string('postal_code', 5);
    $table->string('neighborhood', 100);
    $table->string('phone', 10)->nullable();
    $table->string('mobile', 10);
    $table->boolean('is_affiliated')->default(false);
    $table->string('status', 20)->default('pending'); // MemberStatus backing value; cast in model
    $table->timestamps();
    $table->softDeletes();

    $table->index('organization_id');
    $table->index('municipality_id');
    $table->index('status');
});
// down(): Schema::dropIfExists('members');
```

`organization_id` SET NULL, `municipality_id` RESTRICT (§6.4). No unique constraint on
`curp`/`rfc` (encrypted columns can't be meaningfully unique-indexed; legacy de-dup is the ETL
slice's job — §12). The default status backing value `'pending'` matches §6.3.12.

## 3. MemberStatus enum (Decision C — locked)

`app/Domain/Membership/Enums/MemberStatus.php`, `#[TypeScript]`, `string`-backed, mirror
`JobStatus` but terminal:

```
Pending  = 'pending'  → [Approved, Rejected]
Approved = 'approved' → []   (terminal)
Rejected = 'rejected' → []   (terminal)
```

`allowedTransitions(): list<self>`, `canTransitionTo(self): bool` (strict `in_array`),
`labelKey(): string` → `'member_status.'.$value`, `color(): string` (magenta palette:
Pending `#A03CC7`, Approved `#DD00FF`, Rejected `#9211CF`). Default on create is `Pending`.

## 4. Model

`app/Domain/Membership/Models/Member.php` — `final`, `HasFactory`, `SoftDeletes`, `booted()`
registers `self::addGlobalScope(new OrganizationScope)`. Full `@property`/`@property-read` PHPDoc
for PHPStan L9. **`organization` is NULLABLE** (`belongsTo` → `?Organization`, FK SET NULL) — the
ONLY nullable belongsTo so far (`@property-read Organization|null $organization`,
`@property int|null $organization_id`). **`municipality` is NOT NULL** (RESTRICT →
`@property-read Municipality $municipality`, no `|null`). Casts:

```php
protected function casts(): array
{
    return [
        'curp' => 'encrypted',
        'rfc' => 'encrypted',
        'date_of_birth' => 'date',
        'is_affiliated' => 'boolean',
        'status' => MemberStatus::class,
    ];
}
```

`$fillable` = all writable columns (incl. `organization_id`, `municipality_id`, `curp`, `rfc`,
the name parts, `date_of_birth`, `address`, `postal_code`, `neighborhood`, `phone`, `mobile`,
`is_affiliated`, `status`). Relations: `organization()` (`belongsTo` Organization, nullable),
`municipality()` (`belongsTo` Municipality). `newFactory()` returns `MemberFactory::new()`.
References `App\Domain\Organization\Models\{Organization,Municipality}` — a cross-domain MODEL
reference (allowed; arch allow-lists `App\Domain\Organization\Models` for Membership).

## 5. Validation rules (Decision G — `app/Domain/Shared/Rules/`)

Mirror Appendix B exactly. Each `final`, implements
`Illuminate\Contracts\Validation\ValidationRule`:

- `CurpFormat` — `/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/` on `strtoupper($value)`,
  `$fail(__('validation.curp_format'))`.
- `RfcFormat` — `/^[A-ZN&]{3,4}\d{6}[A-Z0-9]{3}$/` on `strtoupper($value)`,
  `$fail(__('validation.rfc_format'))`.
- `MexicanPhone` — `/^\d{10}$/`, `$fail(__('validation.mexican_phone'))`.

These get UNIT tests (§11.4 #9/#10) directly. Living under `App\Domain\Shared` keeps the
Membership DTO arch-clean and makes them reusable.

## 6. DTO (Spatie Data, `#[TypeScript]`)

**`app/Domain/Membership/Data/RegisterMemberData.php`** (PUBLIC registration; one create-only DTO):

```php
#[Required, Max(100)] public string $first_name,
#[Required, Max(100)] public string $last_name_paternal,
#[Required, Max(100)] public string $last_name_maternal,
#[Required] public string $curp,                 // + CurpFormat in rules()
#[Required] public string $rfc,                  // + RfcFormat in rules()
#[Required] public CarbonImmutable $date_of_birth, // + before:today in rules()
#[Required, Exists('municipalities', 'id')] public int $municipality_id,
#[Required] public string $address,
#[Required] public string $postal_code,          // + digits:5 in rules()
#[Required, Max(100)] public string $neighborhood,
#[Required] public string $mobile,               // + MexicanPhone in rules()
#[Nullable] public ?string $phone = null,        // + MexicanPhone in rules()
#[Nullable, Exists('organizations', 'id')] public ?int $organization_id = null,
```

`rules()` closure attaches the Shared rule objects + the scalar constraints:

```php
public static function rules(ValidationContext $context): array
{
    return [
        'curp' => ['required', 'string', new CurpFormat],
        'rfc' => ['required', 'string', new RfcFormat],
        'mobile' => ['required', new MexicanPhone],
        'phone' => ['nullable', new MexicanPhone],
        'postal_code' => ['required', 'digits:5'],
        'date_of_birth' => ['required', 'date', 'before:today'],
    ];
}
```

`status`, `is_affiliated` are NOT in the DTO (server-set). `CarbonImmutable` import requires the
`Carbon` arch allow-list entry on Membership (mirror the Organization rule).

## 7. Exception

`app/Domain/Membership/Exceptions/InvalidMemberTransitionException.php` — `final`, extends
`\DomainException` (mirror `InvalidJobTransitionException`). Rendered in `bootstrap/app.php`:

```php
$exceptions->render(function (InvalidMemberTransitionException $e, Request $request) {
    return $request->expectsJson()
        ? response()->json(['message' => $e->getMessage()], 422)
        : back()->withErrors(['status' => $e->getMessage()]);
});
```

## 8. Actions (`DB::transaction`, Illuminate\Http-free)

**`RegisterMemberAction`** (PUBLIC create — NO OrganizationContext, Decision F):
```php
public function handle(RegisterMemberData $data): Member
{
    return DB::transaction(fn (): Member => Member::create([
        'organization_id' => $data->organization_id, // payload as-is (nullable, unconfined)
        'municipality_id' => $data->municipality_id,
        'curp' => $data->curp,   // encrypted cast encrypts on save
        'rfc' => $data->rfc,     // encrypted cast encrypts on save
        'first_name' => $data->first_name,
        'last_name_paternal' => $data->last_name_paternal,
        'last_name_maternal' => $data->last_name_maternal,
        'date_of_birth' => $data->date_of_birth,
        'address' => $data->address,
        'postal_code' => $data->postal_code,
        'neighborhood' => $data->neighborhood,
        'phone' => $data->phone,
        'mobile' => $data->mobile,
        'is_affiliated' => false,
        'status' => MemberStatus::Pending,
    ]));
}
```
> Member::create runs UNCONFINED here (a public request never went through `org.scope` to
> confine the context — it stays default-unconfined), so the INSERT is not blocked even though
> the OrganizationScope is registered on the model (the scope only filters SELECT, and is a no-op
> when unconfined anyway).

**`ApproveMemberAction`**:
```php
public function handle(Member $member): Member
{
    if (! $member->status->canTransitionTo(MemberStatus::Approved)) {
        throw new InvalidMemberTransitionException(__('members.error.invalid_transition'));
    }
    return DB::transaction(function () use ($member): Member {
        $member->update(['status' => MemberStatus::Approved, 'is_affiliated' => true]);
        return $member;
    });
}
```

**`RejectMemberAction`** — identical shape, `MemberStatus::Rejected`, `is_affiliated` untouched
(stays false). Both throw the transition exception on an illegal edge (already approved/rejected).

## 9. Controllers + routes

**`app/Http/Controllers/Public/MemberController.php`** (anemic, unconfined):
- `create(): Response` — `Inertia::render('Membership/Register', ['municipality_options' => …,
  'organization_options' => …])` (both id+name option lists; municipalities not org-scoped,
  organizations listed unconfined for the public picker).
- `store(RegisterMemberData $data, RegisterMemberAction $action): RedirectResponse` —
  `$action->handle($data); return redirect()->route('membership.create')->with('success', __('membership.registered'));`

**`app/Http/Controllers/Admin/MemberController.php`** (anemic, mirror `Admin\JobController` index):
- `index(): Response` — `Member::query()->with(['municipality:id,name'])->when($status, …)
  ->latest('id')->paginate(15)->through(fn (Member $m) => $this->mapRow($m))` → `Admin/Members/Index`
  with `members` (data/links/meta), `statuses`, `filters`. The global scope confines a manager
  automatically. `mapRow` serializes ONLY the Decision-E columns (NO curp/rfc).
- `approve(Member $member, ApproveMemberAction $action): RedirectResponse` →
  `back()->with('success', __('members.approved'));`
- `reject(Member $member, RejectMemberAction $action): RedirectResponse` →
  `back()->with('success', __('members.rejected'));`

`mapRow` (Decision E — NO CURP/RFC):
```php
return [
    'id' => $member->id,
    'full_name' => trim("{$member->first_name} {$member->last_name_paternal} {$member->last_name_maternal}"),
    'municipality_name' => $member->municipality->name, // NOT NULL restrict FK (eager-loaded)
    'status' => $member->status->value,
    'status_label_key' => $member->status->labelKey(),
    'is_affiliated' => $member->is_affiliated,
    'created_at' => $member->created_at->toIso8601String(),
];
```

**Routes** (`routes/web.php`):
```php
// public — top level, no auth, rate-limited (§10.4: 5/60min/IP):
Route::get('/membership/register', [PublicMemberController::class, 'create'])->name('membership.create');
Route::post('/membership/register', [PublicMemberController::class, 'store'])
    ->middleware('throttle:5,60')->name('membership.store');

// admin — Manager+ (SPEC §7.3): editor has NO member access (§10.2). New group at role:manager.
Route::middleware(['auth', 'role:manager', 'org.scope'])->group(function (): void {
    Route::get('/admin/members', [MemberController::class, 'index'])->name('admin.members.index');
    Route::post('/admin/members/{member}/approve', [MemberController::class, 'approve'])->name('admin.members.approve');
    Route::post('/admin/members/{member}/reject', [MemberController::class, 'reject'])->name('admin.members.reject');
});
```
> The existing `role:manager` admin group (branches/representatives) can host the member routes,
> OR add a dedicated group — plan keeps them with branches/representatives' `role:manager` group
> (same rung, same `org.scope`). The `{member}` is implicit (scoped) route binding — a cross-org
> member 404s under OrganizationScope (the WRITE crown).

## 10. Inertia pages + prop contracts (snake_case)

`Admin/Members/Index` (admin shell: review table — columns full_name, municipality_name, status
badge via `MemberStatus.color()`/`labelKey()`, is_affiliated, created_at; Approve/Reject POST
buttons shown only on `pending` rows) + public `Membership/Register` (the PII form using the
existing text-input / select / date primitives). The generated `MemberStatus` TS enum is
**type-only imported** (never value-imported). Magenta theme, dark/light, bilingual.

**Exact prop shapes** (snake_case) — the CONTRACT pins these; a prop-contract test per page asserts them:

`Admin/Members/Index`:
```
members: {
  data: Array<{
    id: number; full_name: string; municipality_name: string;
    status: string; status_label_key: string; is_affiliated: boolean; created_at: string;
  }>;
  links: Array<{ url: string|null; label: string; active: boolean }>;
  meta: { from: number|null; to: number|null; total: number };
}
statuses: Array<{ value: string; label_key: string }>;
filters: { status: string|null };
```
`Membership/Register`:
```
municipality_options: Array<{ id: number; name: string }>;
organization_options: Array<{ id: number; name: string }>;
```
(Form posts: first_name, last_name_paternal, last_name_maternal, curp, rfc, date_of_birth,
municipality_id, address, postal_code, neighborhood, mobile, phone?, organization_id? — snake_case.)

## 11. Factory + seed (FICTIONAL — PII-safe)

`database/factories/MemberFactory.php` (mirror `RepresentativeFactory`/`JobPostingFactory`):
default `municipality_id` via `Municipality::factory()`, `organization_id` via
`Organization::factory()`, faker name parts, a **synthetically-generated fake CURP/RFC** (build a
pattern-valid but FAKE string from faker — e.g. random uppercase letters + a fake date + filler;
NEVER a real CURP/RFC), `date_of_birth` a faker adult DOB, fake address/neighborhood, a fake
5-digit postal_code, fake 10-digit mobile/phone, `is_affiliated => false`,
`status => MemberStatus::Pending`. States: `pending()`, `approved()` (status Approved +
`is_affiliated => true`), `rejected()`, `forOrganization(Organization)`,
`forMunicipality(Municipality)`, `affiliated()`, `orgLess()` (`organization_id => null`).
Add a few FICTIONAL members to the demo seeder. **Scan for real PII before commit.**

## 12. DeleteMunicipalityAction extension (Decision I — the cross-slice lesson)

`app/Domain/Organization/Actions/DeleteMunicipalityAction::isReferenced` gains a member referrer:

```php
private function isReferenced(Municipality $municipality): bool
{
    $byOrg = Organization::withTrashed()
        ->where('municipality_id', $municipality->getKey())->exists();

    $byMember = Member::withoutGlobalScope(OrganizationScope::class)
        ->withTrashed()
        ->where('municipality_id', $municipality->getKey())->exists();

    return $byOrg || $byMember;
}
```
The reused message key `municipalities.error.in_use` stays (its wording may broaden to mention
members — i18n step). This makes `DeleteMunicipalityAction` import `App\Domain\Membership\Models\Member`
+ `App\Support\OrganizationScope` (already imported). **Arch:** the Organization domain rule's
allow-list MUST add `App\Domain\Membership\Models` (a cross-domain MODEL reference for the in-use
count — NOT an Action call; mirrors the slice-004 `App\Domain\Jobs\Models` edge added for
`DeleteBranchAction`). A regression test proves a member-only referrer blocks the delete.

> **DeleteOrganizationAction is UNTOUCHED** (Decision B): `members.organization_id` is SET NULL,
> not RESTRICT, so deleting an org with members succeeds and nulls them — NO members pre-check is
> added. A regression test asserts this (org delete nulls member org, never 500s/blocks).

## 13. i18n keys (BOTH lang/es.json + lang/en.json)

`membership.registered`, `members.approved`, `members.rejected`,
`members.error.invalid_transition`,
`member_status.pending`, `member_status.approved`, `member_status.rejected`,
`validation.curp_format`, `validation.rfc_format`, `validation.mexican_phone`.
(`municipalities.error.in_use` already exists — reused; optionally re-worded to include members.)
A lang-key resolution test asserts every key exists in BOTH files.

## 14. Tests (the WRITE crown + PII + municipality regression LAST)

See the CONTRACT §"tests" for the exact, falsifiable list. The arch test gets a Membership
boundary rule (already declared in SPEC §11.2 — `App\Domain\Membership` toOnlyUse Shared/
Illuminate/Spatie) BROADENED to the actual edges: `App\Models`, `App\Support`,
`App\Domain\Organization\Models`, `Carbon`, `Database\Factories`, with
`->ignoring(['__','now','request'])` and a `never depends on HTTP` guard — mirror the Jobs rule.
The Organization rule's allow-list gains `App\Domain\Membership\Models` (Decision I).

## 15. Files to touch (summary)

NEW:
`app/Domain/Membership/Enums/MemberStatus.php`,
`app/Domain/Membership/Models/Member.php`,
`app/Domain/Membership/Data/RegisterMemberData.php`,
`app/Domain/Membership/Exceptions/InvalidMemberTransitionException.php`,
`app/Domain/Membership/Actions/{RegisterMember,ApproveMember,RejectMember}Action.php`,
`app/Domain/Shared/Rules/{CurpFormat,RfcFormat,MexicanPhone}.php`,
`app/Http/Controllers/Public/MemberController.php`,
`app/Http/Controllers/Admin/MemberController.php`,
`database/migrations/2026_06_20_000600_create_members_table.php`,
`database/factories/MemberFactory.php`,
`resources/js/Pages/Admin/Members/Index.tsx`,
`resources/js/Pages/Membership/Register.tsx`,
plus the test files (see CONTRACT).

EDIT:
`routes/web.php` (public membership routes + admin members in the role:manager group),
`bootstrap/app.php` (render `InvalidMemberTransitionException`),
`lang/es.json` + `lang/en.json` (keys),
`app/Domain/Organization/Actions/DeleteMunicipalityAction.php` (member referrer in the in-use pre-check),
the demo seeder (fictional members),
`tests/Arch/ArchitectureTest.php` (Membership boundary rule + the Organization allow-list edge).
