# Plan 006 — Engagement Domain (the HOW)

> **Phase:** Plan. Turns `spec.md` into architecture + the exact files to touch.
> **Gated on:** CLAUDE.md §No-negociables + SPEC §6.4 FK rules + §10.2/§10.4 + §11.2 arch.
> **Build order:** migration → model → factory/seed → DTO → branch-assertion trait → Action →
> controllers/routes → pages/i18n → tests (the READ crown + branch-org consistency + TAMPER +
> branch-soft-delete regression LAST).
> Backend before frontend; `SubmitContactAction` + the org-scoped `ContactMessage` model are the spine.

## 1. Architecture overview

```
HTTP (Inertia)                                Domain (Illuminate\Http-free) + App\Support
─────────────                                 ───────────────────────────────────────────
Public\ContactController (anonymous,          Domain\Engagement\Data\SubmitContactData (#[TypeScript])
  throttle:3,15, store ONLY)                  Domain\Engagement\Actions\SubmitContactAction (org/branch as-is + assertion)
Admin\ContactController (manager+, org.scope, Domain\Engagement\Actions\AssertsBranchBelongsToOrganization (trait, copied from Jobs)
  index ONLY — read-only inbox)               Domain\Engagement\Models\ContactMessage (OrganizationScope in booted(); NO status/SoftDeletes)
                                              Domain\Shared\Rules\MexicanPhone (REUSED — slice 005)
(no bootstrap/app.php render — no             App\Support\OrganizationScope / OrganizationContext (slice 003 — reused, READ confinement only)
  Engagement exception; no enum)
UNTOUCHED: DeleteOrganizationAction, DeleteBranchAction (both parents soft-delete → RESTRICT never trips; Decision E)
```

**Rule compliance:** `SubmitContactAction` returns `ContactMessage`, throws only Spatie
`ValidationException` (via the branch-org assertion); never imports `Illuminate\Http`. Controllers
anemic (public store = DTO→Action→response; admin index uses `request()` only for the optional
`?organization_id=` filter, like `Admin\JobController`). The DTO is the only validation. There is NO
domain exception and NO `bootstrap/app.php` render edit (no status lifecycle — Decision B).

### KEY DIFFERENCES vs slices 004/005 (do NOT blindly copy)

- **NO status enum + NO moderation lifecycle (vs slice-005 Member).** `contact_messages` has no
  `status` column (§6.3.11) — do not create a `ContactMessageStatus` enum, an approve/reject Action,
  an `InvalidTransitionException`, or a `bootstrap/app.php` render. The row IS its own terminal
  state. (Decision B.)
- **NO SoftDeletes + NO delete Action (vs every prior domain).** §6.3.11 has no `deleted_at`; §7.3
  gives only `index`. A contact message is a permanent audit record. (Decision C.)
- **Both FKs from the PUBLIC payload + the branch→org assertion (the slice-004 trait on an
  anonymous path).** Unlike slice-004's admin Create (server-stamps org from `OrganizationContext`),
  the submitter is anonymous, so org/branch come from the payload and the Action asserts internal
  consistency (branch belongs to org) instead of stamping ownership. (Decisions D/F.)
- **PII stored PLAIN (vs slice-005 encrypted CURP/RFC).** §10.5 encrypts only member CURP/RFC; no
  `encrypted` cast here. (Decision H.)
- **The org/branch Delete Actions stay UNTOUCHED (vs slice-005's DeleteMunicipalityAction edit).**
  Both parents soft-delete, so the new RESTRICT FK never trips — no pre-check extension, no cascade
  inclusion, NO arch allow-list edge on the Organization rule. A regression test proves it.
  (Decision E.)

## 2. Data model & migration (reversible, dependency-safe)

> **ID type:** `$table->id()` **bigint** (Decision A) — matches every prior CMS id.

**File:** `database/migrations/2026_06_20_000700_create_contact_messages_table.php`
(timestamp AFTER `...0600_create_members` so it is the last table; both FK targets
`organizations`/`branches` are already migrated by `...0401`/`...0402`).

```php
Schema::create('contact_messages', function (Blueprint $table): void {
    $table->id();
    // Both NOT NULL + RESTRICT (§6.4) — a deleted (soft) org/branch never blocks because
    // the parents soft-delete (Decision E); a HARD delete is refused by RESTRICT (correct —
    // contact messages are a permanent audit record).
    $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
    $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
    $table->string('first_name', 60);
    $table->string('last_name', 60);
    $table->string('email', 60);          // PLAIN (Decision H — §10.5 does not encrypt contact PII)
    $table->string('phone', 10);          // exactly 10 digits (MexicanPhone rule)
    $table->text('message');              // ≤1000 chars (app-level via DTO Max(1000))
    $table->timestamps();
    // NO softDeletes() — §6.3.11 has no deleted_at (Decision C).

    $table->index('organization_id');
    $table->index(['organization_id', 'created_at']);
});
// down(): Schema::dropIfExists('contact_messages');
```

Indexes per §6.3.11: `organization_id`, `[organization_id, created_at]`. No status index (no status
column). The `message` length cap is enforced app-level by `SubmitContactData::Max(1000)` (the
column is `TEXT`, matching §6.3.11).

## 3. NO enum (Decision B — locked)

There is deliberately no `app/Domain/Engagement/Enums/` directory content. `contact_messages` has no
`status`/lifecycle column. Do not add one. (The arch `Engagement enums are string-backed` rule is
either omitted or a no-op — confirm Pest tolerates an empty-enum expectation; if it errors on an
empty namespace, OMIT that one arch line for Engagement, since there are no enums to guard.)

## 4. Model

`app/Domain/Engagement/Models/ContactMessage.php` — `final`, `HasFactory`, **NO `SoftDeletes`**,
`booted()` registers `self::addGlobalScope(new OrganizationScope)` (CONTACT-02 org-scoped READ).
Full `@property`/`@property-read` PHPDoc for PHPStan L10. **Both belongsTo are NOT NULL** (RESTRICT
FKs): `@property-read Organization $organization` (no `|null`), `@property-read Branch $branch` (no
`|null`); `@property int $organization_id`, `@property int $branch_id`. No casts beyond what Eloquent
infers (no enum, no encrypted, no date casts beyond `created_at`/`updated_at` Carbon defaults).

```php
protected $fillable = [
    'organization_id', 'branch_id',
    'first_name', 'last_name', 'email', 'phone', 'message',
];

protected static function booted(): void
{
    self::addGlobalScope(new OrganizationScope);
}
```

Relations: `organization()` (`belongsTo` Organization), `branch()` (`belongsTo` Branch).
`newFactory()` returns `ContactMessageFactory::new()`. References
`App\Domain\Organization\Models\{Organization,Branch}` — a cross-domain MODEL reference (allowed;
arch allow-lists `App\Domain\Organization\Models` for Engagement). NO `casts()` method is required
(there is nothing non-default to cast) — if PHPStan/Pint prefers an explicit empty `casts()`, omit
it; an empty array adds nothing.

## 5. Validation — NO new rule class (Decision I)

REUSE `App\Domain\Shared\Rules\MexicanPhone` (slice-005, 10-digit `/^\d{10}$/`) for `phone`. Use
Spatie's `#[Email]` for `email`. No new `App\Domain\Shared\Rules` class is written.

## 6. DTO (Spatie Data, `#[TypeScript]`)

**`app/Domain/Engagement/Data/SubmitContactData.php`** (PUBLIC submission; one create-only DTO):

```php
#[Required, Max(60)] public string $first_name,
#[Required, Max(60)] public string $last_name,
#[Required, Email, Max(60)] public string $email,
#[Required] public string $phone,              // + MexicanPhone in rules()
#[Required, Max(1000)] public string $message,
#[Required, Exists('organizations', 'id')] public int $organization_id,
#[Required, Exists('branches', 'id')] public int $branch_id,
```

`rules()` attaches the Shared phone rule:

```php
public static function rules(ValidationContext $context): array
{
    return [
        'phone' => ['required', new MexicanPhone],
    ];
}
```

NO server-owned field is present (the table has none — Decision B). The branch→org consistency is
asserted in the Action (the DTO cannot cheaply express the cross-field FK predicate). `#[TypeScript]`
generates `SubmitContactData` for the React form's `useForm` shape.

## 7. NO exception (Decision B)

No `app/Domain/Engagement/Exceptions/` content and NO `bootstrap/app.php` edit. The only failure
path is the branch→org assertion, which throws Spatie's `Illuminate\Validation\ValidationException`
(→ 302 + `branch_id` session error on web automatically, no custom render needed — exactly how the
slice-004 `AssertsBranchBelongsToOrganization` already behaves).

## 8. Actions (`DB::transaction`, Illuminate\Http-free)

**`AssertsBranchBelongsToOrganization` trait** (`app/Domain/Engagement/Actions/` — copy of the Jobs
trait, Engagement-local so App\Support stays domain-neutral):

```php
trait AssertsBranchBelongsToOrganization
{
    private function assertBranchBelongsToOrganization(int $branchId, int $organizationId): void
    {
        $belongs = Branch::query()
            ->withoutGlobalScope(OrganizationScope::class)   // scope-free: the submitter is unconfined
            ->whereKey($branchId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'branch_id' => __('contact.error.branch_org_mismatch'),
            ]);
        }
    }
}
```
> Note: vs the Jobs copy, `$organizationId` is `int` (NOT `?int`) — a contact message's
> `organization_id` is NOT NULL (Required in the DTO), so there is no nullable-org case.

**`SubmitContactAction`** (PUBLIC create — NO OrganizationContext, Decision F):

```php
final class SubmitContactAction
{
    use AssertsBranchBelongsToOrganization;

    public function handle(SubmitContactData $data): ContactMessage
    {
        $this->assertBranchBelongsToOrganization($data->branch_id, $data->organization_id);

        return DB::transaction(fn (): ContactMessage => ContactMessage::create([
            'organization_id' => $data->organization_id, // payload as-is (unconfined, asserted-consistent)
            'branch_id' => $data->branch_id,
            'first_name' => $data->first_name,
            'last_name' => $data->last_name,
            'email' => $data->email,
            'phone' => $data->phone,
            'message' => $data->message,
        ]));
    }
}
```
> `ContactMessage::create` runs UNCONFINED here (a public request never went through `org.scope`,
> so the context stays default-unconfined) — the INSERT is not blocked even though the
> OrganizationScope is registered (the scope only filters SELECT, and is a no-op when unconfined).
> The assertion is the WRITE-provenance guard; there is no org-stamp because there is no owner.

## 9. Controllers + routes

**`app/Http/Controllers/Public/ContactController.php`** (anemic, unconfined, store ONLY):
```php
public function store(SubmitContactData $data, SubmitContactAction $action): RedirectResponse
{
    $action->handle($data);

    return back()->with('success', __('contact.submitted'));
}
```
> `back()` returns the visitor to the landing/footer where `ContactForm.tsx` lives (Decision G — no
> standalone contact page, no GET route). The Inertia success flash drives the component's
> confirmation. No `create()` method (the form is a component, its org/branch options ride the
> landing/shared props).

**`app/Http/Controllers/Admin/ContactController.php`** (anemic, mirror `Admin\JobController` index):
```php
public function index(): Response
{
    $organizationId = request()->integer('organization_id') ?: null;

    $messages = ContactMessage::query()
        ->with(['branch:id,name'])
        ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
        ->latest('id')
        ->paginate(15)
        ->withQueryString()
        ->through(fn (ContactMessage $message): array => $this->mapRow($message));

    return Inertia::render('Admin/Contacts/Index', [
        'messages' => [
            'data' => $messages->items(),
            'links' => $messages->linkCollection()->toArray(),
            'meta' => [
                'from' => $messages->firstItem(),
                'to' => $messages->lastItem(),
                'total' => $messages->total(),
            ],
        ],
        'filters' => ['organization_id' => $organizationId],
    ]);
}
```
> The global OrganizationScope confines a manager automatically (no manual `where`). The
> `?organization_id=` filter only narrows an UNCONFINED admin's view (a confined manager already
> sees only their org, so the filter is a no-op for them — harmless). NO mutations (read-only inbox).

`mapRow` (PII minimized — the inbox needs enough to triage + reply by phone/email, which IS the
purpose of a contact message, so email/phone ARE shown to the authorized org viewer; this is not a
PII leak — it is the message's whole point, scoped to the org):
```php
return [
    'id' => $message->id,
    'full_name' => trim("{$message->first_name} {$message->last_name}"),
    'email' => $message->email,
    'phone' => $message->phone,
    'branch_name' => $message->branch->name,   // NOT NULL restrict FK (eager-loaded)
    'message' => $message->message,            // plain text; React escapes on render (never dangerouslySetInnerHTML)
    'created_at' => $message->created_at->toIso8601String(),
];
```

**Routes** (`routes/web.php`):
```php
// public — top level, no auth, rate-limited (§10.4: 3/15min/IP):
Route::post('/contact', [PublicContactController::class, 'store'])
    ->middleware('throttle:3,15')->name('contact.store');

// admin — Manager+ (SPEC §7.3): editor has NO contact access (§10.2). Host in the EXISTING
// role:manager + org.scope group (alongside branches/representatives/members):
Route::get('/admin/contacts', [ContactController::class, 'index'])->name('admin.contacts.index');
```
> The public `POST /contact` sits at the top level (like the membership store), `throttle:3,15`. The
> admin `GET /admin/contacts` joins the existing `['auth','role:manager','org.scope']` group that
> already hosts branches/representatives/members — same rung, same scope. No new group is needed.

## 10. Inertia component + page + prop contracts (snake_case)

**`resources/js/components/ContactForm.tsx`** (§8.3 — the reusable public form component): a
`useForm({ first_name, last_name, email, phone, message, organization_id, branch_id })` POSTing to
`route('contact.store')`; org select + dependent branch select (branch options filtered by chosen
org — either a `/api/branches?organization_id=X` partial reload per §7.6, OR the landing props
pre-supply an org→branches map; plan picks the simpler pre-supplied map to avoid adding the §7.6 API
route this slice, which is DEFERRED). Inline error display (the 302 session errors), success flash.
Magenta theme, dark/light, bilingual via the locale hook. NO generated TS enum import (no enum).

**`resources/js/Pages/Admin/Contacts/Index.tsx`** (§8.1 — admin-shell read-only inbox table):
columns full_name, email, phone, branch_name, a message preview (truncated, full on expand), and
received-at. NO action buttons (read-only). The message is rendered as **escaped plain text** (JSX
`{message}` — never `dangerouslySetInnerHTML`). A prop-contract test pins the shape.

**Exact prop shapes** (snake_case) — the CONTRACT pins these; the prop-contract test asserts them:

`Admin/Contacts/Index`:
```
messages: {
  data: Array<{
    id: number; full_name: string; email: string; phone: string;
    branch_name: string; message: string; created_at: string;
  }>;
  links: Array<{ url: string|null; label: string; active: boolean }>;
  meta: { from: number|null; to: number|null; total: number };
}
filters: { organization_id: number|null };
```
`ContactForm.tsx` props (supplied by the landing/footer shared props — Decision G):
```
organization_options: Array<{ id: number; name: string; branches: Array<{ id: number; name: string }> }>;
```
(Form posts: first_name, last_name, email, phone, message, organization_id, branch_id — snake_case.)

> The `organization_options` carry a nested `branches` array per org so the dependent branch select
> works WITHOUT a new API route (the §7.6 `/api/branches` endpoint stays DEFERRED). The landing
> controller (or a footer shared prop) supplies this; plan confirms the exact wiring point during
> implement (the smallest change — likely a `HandleInertiaRequests` shared prop or a
> `LandingController` prop, whichever already feeds the footer).

## 11. Factory + seed (FICTIONAL — PII-safe)

`database/factories/ContactMessageFactory.php` (mirror `JobPostingFactory`'s org/branch pairing):
default `organization_id` via `Organization::factory()`, `branch_id` via
`Branch::factory()->for($organization)` (so the branch→org invariant always holds), faker
`first_name`/`last_name`, a faker `safeEmail()` truncated to 60, a fake 10-digit `phone`
(`fake()->numerify('##########')`), a faker `paragraph` truncated to 1000 for `message`. States:
`forOrganization(Organization)` (`organization_id` + a fresh `Branch::factory()->for($organization)`),
`forBranch(Branch)` (`branch_id` + its `organization_id`). Add a few FICTIONAL contact messages to
the demo seeder. **Scan for real PII before commit.**

## 12. The org/branch Delete Actions stay UNTOUCHED (Decision E — the cross-slice lesson, resolved)

`DeleteOrganizationAction` and `DeleteBranchAction` are NOT edited:
- `contact_messages.organization_id`/`.branch_id` are RESTRICT, but `organizations`/`branches`
  SOFT-delete. A soft delete is an `UPDATE deleted_at`, NOT a SQL `DELETE`, so the RESTRICT FK is
  never evaluated — exactly how `articles`/`job_postings` already RESTRICT-to-org/branch without an
  org pre-check extension. ⇒ NO pre-check is added.
- `contact_messages` is NOT added to `DeleteBranchAction`'s application-level cascade. The cascade
  SOFT-deletes children (article/job) so they don't outlive a trashed branch on a public board; a
  contact message has no `deleted_at` (Decision C) and is a permanent audit record — it correctly
  SURVIVES the branch's soft delete, pointing at the trashed branch (an admin can still read the
  historical message; the branch name resolves via `withTrashed` if the inbox ever needs it — but
  the message itself is unaffected).
- **NO arch allow-list edge is added to the Organization rule** — the Organization domain does NOT
  reference `App\Domain\Engagement\Models` (the explicit contrast with slice-005's
  `DeleteMunicipalityAction`, which DID need the `App\Domain\Membership\Models` edge because
  `municipalities` HARD-delete and so the RESTRICT FK from `members` had to be pre-checked).

A regression test (`tests/Feature/Engagement/BranchDeleteWithContactMessagesTest.php`) proves a
branch with contact messages soft-deletes gracefully (302, no 500) and the messages survive.

## 13. i18n keys (BOTH lang/es.json + lang/en.json)

NEW: `contact.submitted`, `contact.error.branch_org_mismatch`.
REUSED (already present from slice-005): `validation.mexican_phone`.
Suggested copy (mirror the existing `jobs.error.branch_org_mismatch` wording):
- ES `contact.submitted`: "Tu mensaje se envió correctamente. Nos pondremos en contacto pronto."
- EN `contact.submitted`: "Your message was sent successfully. We will be in touch soon."
- ES `contact.error.branch_org_mismatch`: "La sucursal seleccionada no pertenece a esta organización."
- EN `contact.error.branch_org_mismatch`: "The selected branch does not belong to this organization."
A lang-key resolution test asserts every NEW key exists in BOTH files.

## 14. Tests (the READ crown + branch-org consistency + TAMPER + branch-soft-delete regression LAST)

Mirror the slice-005 test file layout under `tests/Feature/Engagement/` (+ no Unit tests — there is
no enum/VO to unit-test; the `MexicanPhone` rule is already unit-tested by slice-005's
`PiiRulesTest`). The exact, falsifiable list is in the CONTRACT §"tests". The arch test gets an
Engagement boundary rule (SPEC §11.2 declares a STUB `App\Domain\Engagement` toOnlyUse
Shared/Illuminate/Spatie — BROADEN it to the actual edges: `App\Domain\Shared` (MexicanPhone),
`App\Support` (OrganizationScope), `App\Domain\Organization\Models` (Organization/Branch belongsTo +
the assertion target), `Illuminate`, `Spatie\LaravelData`, `Spatie\TypeScriptTransformer`,
`Database\Factories`, with `->ignoring(['__','now','request'])`) + a `never depends on HTTP` guard +
the `Engagement actions are final` / `Engagement data DTOs are final` rules. **OMIT** the
`Engagement enums are string-backed` rule (there are no Engagement enums — Decision B). The
Organization rule's allow-list is UNCHANGED (Decision E — no Engagement edge). Confirm during
implement whether `App\Models`/`Carbon` are needed (they are NOT — no acting-User arg, no Carbon
DTO field) and do NOT add them.

## 15. Files to touch (summary)

NEW:
`app/Domain/Engagement/Models/ContactMessage.php`,
`app/Domain/Engagement/Data/SubmitContactData.php`,
`app/Domain/Engagement/Actions/SubmitContactAction.php`,
`app/Domain/Engagement/Actions/AssertsBranchBelongsToOrganization.php`,
`app/Http/Controllers/Public/ContactController.php`,
`app/Http/Controllers/Admin/ContactController.php`,
`database/migrations/2026_06_20_000700_create_contact_messages_table.php`,
`database/factories/ContactMessageFactory.php`,
`resources/js/components/ContactForm.tsx`,
`resources/js/Pages/Admin/Contacts/Index.tsx`,
plus the test files (see CONTRACT).

EDIT:
`routes/web.php` (public `POST /contact` top-level throttled + admin `GET /admin/contacts` in the
role:manager group),
`lang/es.json` + `lang/en.json` (the 2 new keys),
the demo seeder (fictional contact messages),
the landing/footer shared-prop source (supply `organization_options` with nested `branches` for the
public `ContactForm` — Decision G; smallest wiring point confirmed during implement),
`tests/Arch/ArchitectureTest.php` (Engagement boundary rule — NO Organization allow-list edit).

NOT TOUCHED (Decision E — explicit): `app/Domain/Organization/Actions/DeleteOrganizationAction.php`,
`app/Domain/Organization/Actions/DeleteBranchAction.php`, `bootstrap/app.php` (no exception render).
