# Spec 006 — Engagement Domain (public contact form + org-scoped admin message inbox)

> **Phase:** Specify (the WHAT and WHY, not the HOW).
> **SSOT:** `SPEC.md` (§3.5 Engagement CONTACT-01 [public submit; name/last_name ≤60, email valid
> + ≤60, phone exactly 10 digits, message ≤1000; rate-limited 3/15min] + CONTACT-02 [admin/manager
> reads messages for own org, scoped by `organization_id`]; §6.3.11 `contact_messages` table; §6.4
> FK summary [`organization_id` **RESTRICT**, `branch_id` **RESTRICT**]; §7.1 public
> `POST /contact`; §7.3 Manager+ `GET /admin/contacts`; §10.2 RBAC [Contacts Read: super_admin All /
> administrator All / manager Own-org / editor —]; §10.4 rate-limit [3 submissions / 15 min / IP];
> §11.2 Engagement arch boundary [`App\Domain\Engagement` toOnlyUse Shared/Illuminate/Spatie];
> §8.3 `ContactForm.tsx` public component; §8.1 `Contacts/Index.tsx`; §1.4/§1.5 magenta theme).
> **Reference impl (same stack, slices 004 Jobs + 005 Membership — built + reviewed):** the
> org-scoping spine (`App\Support\OrganizationContext` + `App\Support\OrganizationScope` +
> `EnsureOrganizationScope` `org.scope` middleware ordered BEFORE `SubstituteBindings`);
> **slice-005 `Public\MemberController` + `RegisterMemberData` + `RegisterMemberAction`** — the
> PUBLIC ANONYMOUS WRITE pattern this slice mirrors closest (anonymous, `throttle:N,M`, CSRF,
> server-owned defaults, 302-not-422, no PII read back); **slice-004 `AssertsBranchBelongsToOrganization`**
> (the Engagement-local branch→org assertion this slice copies — `contact_messages` has BOTH an
> `organization_id` and a `branch_id` RESTRICT FK, both from the public payload, so the Action MUST
> assert the chosen branch belongs to the chosen org); **slice-004 `Admin\JobController` index** (the
> anemic paginated org-scoped read model this slice's `Admin\ContactController@index` mirrors);
> **slice-005 `MexicanPhone` rule** (`App\Domain\Shared\Rules` — REUSED for the 10-digit phone).
> `omnibus-uniges` remains the upstream Action/DTO pattern source.
> **Built on (DO NOT break — the slices 001–005 suites are green):** slice 001 Identity (`UserRole`
> ladder, `EnsureRole` `role:<level>`, `App\Models\User` + its `organization_id` FK), slice 002
> Content (the `Public\*Controller` unconfined-read pattern), slice 003 Organization (`Organization`,
> `Branch` models, the org-scoping spine, the Delete Actions' restrict-FK pre-checks), slice 004 Jobs
> (the public+admin split, `AssertsBranchBelongsToOrganization`), slice 005 Membership (the public
> anonymous registration pattern, `MexicanPhone`), the arch suite, the magenta theme + `lang/{es,en}.json`.
> **Status:** DRAFT — awaiting human review gate before `/sdd-plan` / build.

## 1. Problem & why

Engagement is the CMS's **inbound public-contact channel**. SPEC §3.5 (CONTACT-01/02), §6.3.11
(`contact_messages`), §7.1 (`POST /contact`) and §7.3 (`GET /admin/contacts`) define exactly one
entity — the **contact message** — with a two-sided flow:

1. **CONTACT-01 — public submission.** Any visitor fills a **public** form (`first_name`,
   `last_name`, `email`, `phone`, `message`) targeting a specific organization (and one of its
   branches) and a `contact_messages` row is created. This is the SECOND public anonymous WRITE in
   the CMS (the sibling is slice-005 membership registration; Content/Jobs public paths are
   read-only). It is **rate-limited 3 submissions / 15 min / IP** (§10.4) and CSRF-protected.
2. **CONTACT-02 — admin inbox.** An admin/manager lists messages **scoped to their organization**
   (§10.2: manager = Own-org; administrator/super_admin = All; **editor has NO access**). It is a
   **READ-ONLY** inbox: there is no moderation lifecycle, no approve/reject, no edit, no delete.

**This is the SIMPLEST Engagement shape — and that simplicity is a deliberate, SPEC-enforced
decision, not an omission.** Unlike a comments/newsletter system, the SPEC's `contact_messages`
table (§6.3.11) has:

- **NO `status` column.** A contact message is never `pending`/`approved`/`spam`. ⇒ **No
  `ContactMessageStatus` enum, no transition guard, no `InvalidTransitionException`, no
  `bootstrap/app.php` render, no approve/reject Actions.** The "moderation lifecycle" lesson does
  **NOT** apply — there is no lifecycle to moderate. (Contrast slice-005 Member, which DID have a
  `status` enum + approve/reject — Engagement has neither.)
- **NO `SoftDeletes`** (no `deleted_at` in §6.3.11). ⇒ A contact message is a **permanent audit
  record** (§6.4 rationale: "Protect audit trail"). No delete Action ships (and there is no admin
  delete route — §7.3 gives only `index`).
- **NO rich-HTML body.** `message` is plain `TEXT` (≤1000 chars, app-level), NOT a TipTap JSONB
  document. ⇒ **`SanitizesContent` does NOT apply** (it sanitizes the structured TipTap tree, not
  scalar text). The stored-XSS surface is handled by storing the message as a scalar string that
  the React renderer escapes on output (never `dangerouslySetInnerHTML`).

What this slice DOES exercise — and must get right the first time:

- **The public-anonymous-write hardening (the slice-005 pattern):** `POST /contact` is anonymous
  (no `auth`), `throttle:3,15` rate-limited (§10.4), CSRF-protected, validated via a Spatie Data
  DTO (302 + session errors, never 422). **No server-moderated field exists** in `contact_messages`,
  so there is nothing for a submitter to self-elevate — but the DTO must NOT carry any field the
  server should own, and any future moderation flag would be hardcoded server-side. The submission
  is **never read back on a public path** (no public message directory; messages are admin-only).
- **TENANT-WRITE provenance on an anonymous path (a NEW shape vs slices 004/005):** the message's
  `organization_id` + `branch_id` come from the **public payload** (the visitor chooses WHO they
  are contacting). The Action must **assert the chosen `branch_id` belongs to the chosen
  `organization_id`** (mirror slice-004 `AssertsBranchBelongsToOrganization`) — so a hostile or
  buggy payload cannot file a message under org A's name against org B's branch. There is NO
  server-side org-stamp (the submitter is anonymous — there is no confined caller, exactly like
  slice-005 registration's Decision F); the org/branch are the submitter's declared target,
  validated for internal consistency.
- **TENANT-READ isolation (the admin inbox crown):** `ContactMessage` is org-scoped via the global
  `OrganizationScope` in `booted()`. A confined manager of org A sees ONLY org-A messages
  (falsifiable: `ContactMessage::withoutGlobalScope(OrganizationScope::class)->count()` exceeds the
  manager's visible count). administrator/super_admin see all. editor → 403.
- **PII handling:** a contact message carries `first_name`/`last_name`/`email`/`phone` — submitter
  PII. Fixtures are FICTIONAL (faker names/emails/phones, never real). PII is minimized in the
  admin props (the inbox shows what the SPEC needs) and **never exposed on a public path**. The
  SPEC does NOT require encryption-at-rest for contact PII (contrast §10.5, which encrypts only
  member CURP/RFC) — so `email`/`phone` are stored plain (no `encrypted` cast); a PII scan runs
  before any commit.
- **A new RESTRICT FK to two soft-deletable parents (the slice-004 cross-slice lesson, evaluated
  and resolved):** `contact_messages.organization_id` and `.branch_id` are RESTRICT to
  `organizations`/`branches`, which are SOFT-deletable. Because both parents soft-delete (UPDATE,
  not DELETE), the RESTRICT FK is **never tripped** by `DeleteOrganizationAction`/`DeleteBranchAction`
  (mirrors how `articles`/`job_postings` RESTRICT-to-org/branch without extending the org pre-check).
  ⇒ **NO pre-check extension is required**, and `contact_messages` (no SoftDeletes) is NOT added to
  `DeleteBranchAction`'s application-level cascade (the cascade soft-deletes children; a contact
  message has no `deleted_at` and is a permanent audit record — it survives the branch's soft delete,
  pointing at a now-trashed branch). This decision is PROVEN by a regression test (§5, Decision E).

Getting the public-submission org/branch-consistency assertion, the anonymous-write hardening, and
the org-scoped READ isolation right the first time is the whole point.

## 2. Scope

### In scope (Engagement MVP — public contact submission + org-scoped admin inbox)

1. **`contact_messages` table + `ContactMessage` model** (SPEC §6.3.11) — `bigint` id (the
   established slice-003 Deviation: all CMS ids are bigint, NOT the SPEC's ULID note);
   `organization_id` (**NOT NULL**, FK `RESTRICT`), `branch_id` (**NOT NULL**, FK `RESTRICT`);
   `first_name` VARCHAR(60), `last_name` VARCHAR(60), `email` VARCHAR(60), `phone` VARCHAR(10),
   `message` TEXT; `timestamps()`; **NO `softDeletes()`** (§6.3.11 has no `deleted_at`). Indexes per
   §6.3.11: `organization_id`, `[organization_id, created_at]`. Registers `OrganizationScope` in
   `booted()` from birth (CONTACT-02 org-scoped read). NO `status`/`SoftDeletes` cast — only
   `belongsTo` relations + the bigint/string column casts Eloquent infers. `final`, `HasFactory`,
   full `@property`/`@property-read` PHPDoc (both belongsTo are NOT NULL → `Organization`/`Branch`,
   never `|null`). REUSES the `App\Domain\Organization\Models\{Organization,Branch}` cross-domain
   MODEL references (arch-allow-listed for Engagement).

2. **`SubmitContactData` DTO** (Spatie Data, `#[TypeScript]`) — the PUBLIC submission payload. All
   fields Required:
   - `first_name` `#[Required, Max(60)]`
   - `last_name` `#[Required, Max(60)]`
   - `email` `#[Required, Email, Max(60)]`
   - `phone` `#[Required]` + `MexicanPhone` rule (10-digit, REUSED from `App\Domain\Shared\Rules`)
   - `message` `#[Required, Max(1000)]`
   - `organization_id` `#[Required, Exists('organizations','id')]` (the submitter's chosen target)
   - `branch_id` `#[Required, Exists('branches','id')]` (a branch of that org — consistency asserted
     in the Action, NOT the DTO, because the DTO cannot cheaply express the cross-field FK predicate)

   **NO server-owned field is in this DTO** — `contact_messages` has no `status`/moderation column,
   so there is nothing for the server to own here. The Action stamps nothing the submitter could
   self-elevate (there is no such field); it ONLY asserts the org/branch consistency and persists.

3. **`SubmitContactAction`** (`App\Domain\Engagement\Actions`, Illuminate\Http-free,
   `DB::transaction`, one op) — the PUBLIC create (the SOLE write path). NO `OrganizationContext`
   override (anonymous, unconfined — mirror slice-005 `RegisterMemberAction` Decision F): the
   `organization_id`/`branch_id` are persisted from the payload **as-is**, AFTER the
   `AssertsBranchBelongsToOrganization` consistency check (the chosen branch must physically belong
   to the chosen org, scope-free — else a 302 + `branch_id` field error via `ValidationException`,
   mirroring slice-004). Returns the `ContactMessage`.

4. **`AssertsBranchBelongsToOrganization` (Engagement-local trait)** — copy of the slice-004 Jobs
   trait (`App\Domain\Engagement\Actions`), querying `Branch::withoutGlobalScope(OrganizationScope)
   ->whereKey($branchId)->where('organization_id', $organizationId)->exists()` → 302 +
   `branch_id` error (`__('contact.error.branch_org_mismatch')`) on mismatch. It runs scope-free so
   the invariant holds even though the submitter is unconfined (a public request never confines the
   context — the scope is a no-op, so the EXPLICIT `organization_id` match is what guarantees
   consistency). **NOT a shared `App\Support` trait** (App\Support stays domain-neutral — it must
   never import `App\Domain`), exactly as the Jobs copy reasoned.

5. **`Public\ContactController`** (anemic, ≤15 lines/method) — `store(SubmitContactData $data,
   SubmitContactAction $action): RedirectResponse` only. (No `create` method: per §8.1 the contact
   FORM is a `ContactForm.tsx` COMPONENT embedded in the landing/footer, not a standalone Inertia
   page — see Decision G; the form's org/branch option lists are supplied by the existing
   `LandingController`/footer shared props, NOT a new GET route. If a standalone contact page is
   later desired it is a small follow-on — **DEFERRED**, noted §"Out of scope".) `store` hands the
   validated DTO (a bad field → 302 + session errors, NEVER 422) to the Action → 302 + success flash.

6. **`Admin\ContactController`** (anemic) — `index(): Response` only (SPEC §7.3 gives
   `/admin/contacts` ONLY `index`; there is NO show/destroy/update). Org-scoped paginated list,
   snake_case rows, optional `?organization_id=` filter for an unconfined admin (mirror
   `Admin\JobController`/`Admin\MemberController` index). The global `OrganizationScope` confines a
   manager's inbox automatically (no manual `where`). Gated under a `['auth','role:manager','org.scope']`
   group (SPEC §7.3 places `/admin/contacts` in the **Manager+** table — editor has NO contact
   access per §10.2). NO mutations (no approve/reject/destroy — the inbox is read-only).

7. **Routes** (SPEC §7.1, §7.3) — public `POST /contact` (`contact.store`, **`throttle:3,15`**,
   §10.4); admin `GET /admin/contacts` (`admin.contacts.index`, in the existing `role:manager` +
   `org.scope` group alongside branches/representatives/members). All `->name()`d, no closures.

8. **`ContactForm.tsx` public component** (§8.3) + **`Admin/Contacts/Index.tsx` page** (§8.1) —
   `ContactForm` is a reusable React component (the public form: name/last_name/email/phone/message
   + org + branch selects, `useForm` POSTing to `contact.store`, snake_case fields, success-flash +
   inline error display); `Admin/Contacts/Index` is the admin-shell read-only inbox table (sender,
   email, phone, branch, message preview, received-at). Magenta theme, dark/light, bilingual via
   the locale hook. Snake_case props; a prop-contract test on the admin Index. The message is
   rendered as **escaped plain text** (never `dangerouslySetInnerHTML`). NO generated TS enum
   (Engagement has no enum).

9. **`ContactMessageFactory`** + a demo seed addition — **FICTIONAL data only** (faker names,
   emails, 10-digit phones; never real PII). `organization_id`/`branch_id` via a consistent
   `Branch::factory()->for($organization)` pair (so the factory always satisfies the branch→org
   invariant). States: `forOrganization(Organization)`, `forBranch(Branch)`.

10. **i18n** — every `__()` key in BOTH `lang/es.json` AND `lang/en.json`:
    `contact.submitted` (the success flash), `contact.error.branch_org_mismatch` (the branch→org
    assertion — wording mirrors `jobs.error.branch_org_mismatch`). (`validation.mexican_phone`
    already exists from slice-005 — REUSED for `phone`.) A lang-key resolution test asserts each new
    key exists in BOTH files.

11. **Tests** — the public submission (a valid POST lands a `contact_messages` row, 302 + success
    flash, the row carries the submitted org/branch + sender PII); the **anonymous-write hardening**
    (validation 302s for empty/over-length name/last_name/email/phone/message, invalid email,
    non-10-digit phone, message >1000; a **TAMPER test** that any extra/unknown key in the payload —
    e.g. a forged `id`/`created_at`/an invented `status` — is inert: Spatie Data discards unknown
    keys and the Action persists only the defined fields, so a submitter can plant nothing they
    should not own); the **branch→org consistency assertion** (a `branch_id` belonging to a
    DIFFERENT org → 302 + `branch_id` error, no row written; the matching branch → success); the
    **rate-limit** (`throttle:3,15`: the first 3 POSTs pass, the 4th 429s, no 4th row); the
    **org-scoped READ isolation crown** (a manager of org A sees ONLY org-A messages — falsifiable
    vs the physical `withoutGlobalScope` count; a cross-org message is invisible; super_admin sees
    all); **role gating** (guest → login; editor → 403; manager/administrator/super_admin → 200);
    the **graceful branch soft-delete regression** (deleting a branch that has contact messages
    succeeds — the branch soft-deletes, the RESTRICT FK is NOT tripped, the messages survive
    pointing at the trashed branch, no 500 — Decision E); the **no-public-read** assertion (there is
    NO public route that returns contact-message data — PII never leaks publicly); the
    **prop-contract** test on `Admin/Contacts/Index`; the **lang-key resolution** test; and the
    **arch boundary** (`App\Domain\Engagement` toOnlyUse Shared/Models?/App\Support/Organization\Models/
    Illuminate/Spatie/Database\Factories — exact edges locked in plan.md).

### Out of scope / DEFERRED (with notes)

- **A standalone public contact PAGE / `GET /contact` route.** SPEC §7.1 defines ONLY
  `POST /contact`; §8.3 lists `ContactForm.tsx` as a COMPONENT (embedded in the landing/footer), and
  §8.1 has no `Contact/Create` page. So no GET form route ships; the form lives where the landing
  layout already renders it (the org/branch option lists ride existing shared/landing props). **A
  dedicated `/contacto` page is a small follow-on — DEFERRED.** (Decision G locks this.)
- **A moderation lifecycle (status / approve / reject / spam / delete).** §6.3.11 has NO `status`
  column and NO `deleted_at`; §7.3 gives `/admin/contacts` only `index`. A contact message is a
  permanent, unmoderated audit record. **DEFERRED — note for a future slice if the product adds
  triage/spam handling; it would add the `status` column + an enum + the moderation Actions.**
- **A "mark as read" / reply / assignment workflow.** Not in §3.5/§6.3.11/§7.3. **DEFERRED.**
- **Email notification to the org on a new message.** §3.5 defines storage only; no notification/
  mailable. **DEFERRED — note for a future Notifications slice.**
- **Encryption-at-rest of contact email/phone.** §10.5 encrypts ONLY member CURP/RFC; contact PII is
  NOT in the §10.5 encryption table. `email`/`phone` are stored plain (no `encrypted` cast).
  **DEFERRED — the SPEC does not require it; revisit only if §10.5 broadens.** (PII is still
  minimized in props + scanned before commit + never on a public path.)
- **CAPTCHA / spam scoring on the public form.** §10.4 specifies a rate limit (3/15min/IP) as the
  abuse control; no CAPTCHA. **DEFERRED — the throttle is the §10.4 contract.**
- **Contact messages count in `daily_snapshots` (§3.7 Analytics "Contact: messages received per
  period", §6.3.13 `contact_messages_count`).** Analytics is its own slice (§3.7); this slice ships
  the `contact_messages` row model + `created_at` the trend needs. **DEFERRED to the Analytics slice.**
- **ETL of any legacy contact data.** No legacy `contactos` mapping in §12. Greenfield build only.
  **DEFERRED to §12 ETL if ever needed.**

## 3. Acceptance scenarios (When… Then…)

**CONTACT-01 — Public submission (the anonymous WRITE)**
- When ANY visitor (anonymous) POSTs `/contact` with valid fields (`first_name`/`last_name` ≤60, a
  valid `email` ≤60, a 10-digit `phone`, a `message` ≤1000, an existing `organization_id`, and a
  `branch_id` that belongs to that org), Then a `contact_messages` row is created with the submitted
  org/branch + sender PII, and a 302 + `contact.submitted` success flash. No server-owned field is
  set from the payload (there is none on this table).
- When `first_name`/`last_name`/`email`/`phone`/`message` is empty or over its limit, or `email` is
  not a valid email, or `phone` is not exactly 10 digits, or `message` exceeds 1000 chars, Then 302
  + session errors on the offending field(s) (NEVER 422), and no row is written.
- When `organization_id` does not exist, Then 302 + an `organization_id` error; when `branch_id`
  does not exist, Then 302 + a `branch_id` error; no row written.
- **Branch→org consistency:** When `branch_id` exists but belongs to a DIFFERENT organization than
  `organization_id`, Then 302 + a `branch_id` error (`contact.error.branch_org_mismatch`), and no
  row is written (the cross-org attach never reaches the DB).
- **TAMPER (no self-elevation):** When the payload carries extra/unknown keys (a forged `id`, a
  `created_at`, an invented `status`/`is_spam`), Then those keys are inert (Spatie Data discards
  them; the Action writes only the defined fields) — the row lands with a server-assigned id and
  timestamps, and nothing the submitter should not own is persisted.
- **Rate limit:** When the same IP POSTs `/contact` a 4th time within 15 minutes, Then a 429 (the
  `throttle:3,15` limiter, §10.4); the first 3 succeed; no 4th row is written.

**CONTACT-02 — Admin inbox (READ confinement, the crown)**
- When a manager of org A GETs `/admin/contacts`, Then ONLY org-A messages appear (falsifiable:
  `ContactMessage::withoutGlobalScope(OrganizationScope::class)->count()` exceeds the visible count).
- When a super_admin / administrator GETs `/admin/contacts`, Then ALL orgs' messages appear
  (unconfined); the optional `?organization_id=` filter narrows the unconfined view.
- The inbox is READ-ONLY: there is no approve/reject/edit/delete control or route.

**Role gating (SPEC §7.3, §10.2)**
- When a guest hits `/admin/contacts`, Then 302 → login.
- When an authenticated **editor** reaches `/admin/contacts`, Then a **403** (editor has NO contact
  access — §10.2 Contacts Read editor `—`; the route group is `role:manager`).
- When a manager / administrator / super_admin reaches `/admin/contacts`, Then 200.

**No public read (PII safety)**
- There is NO public route that returns contact-message data. A visitor can submit but never read
  back any message; sender PII never appears on a public path.

**Graceful branch soft-delete regression (the cross-slice lesson, resolved)**
- When a manager/admin deletes a branch that has contact messages, Then the branch soft-deletes
  successfully (no RESTRICT-FK 500), the contact messages SURVIVE (they have no `deleted_at`),
  physically still pointing at the now-trashed branch. (Proves no pre-check extension / cascade
  inclusion is needed — Decision E.)

## 4. Non-goals / invariants restated

- Domain code never imports `Illuminate\Http`; the `App\Domain\Shared\Rules\MexicanPhone` validator
  and the `App\Domain\Organization\Models\{Organization,Branch}` model references are OK
  (cross-domain MODEL refs, not Action calls).
- Controllers anemic (≤15 lines/method): public store = DTO→Action→response; admin index uses
  `request()` only for the optional `?organization_id=` filter (like `Admin\JobController`).
- `declare(strict_types=1)`, `final`, `readonly` DTO, NO magic strings. **No enum** (the table has
  no status column — do not invent one).
- Migration reversible; `contact_messages.organization_id` + `.branch_id` FKs **RESTRICT**; **NO
  SoftDeletes** (permanent audit record, §6.3.11). The branch→org consistency is asserted in the
  Action (mirror slice-004), never trusted blindly.
- The PUBLIC `POST /contact` is anonymous + `throttle:3,15` + CSRF (web middleware) + Spatie-Data
  validated (302 not 422). No server-moderated field exists to forge; any unknown payload key is
  inert.
- `ContactMessage` is org-scoped (OrganizationScope in `booted()`) — the admin inbox READ is
  confined; there is no WRITE confinement (the public path is unconfined by design, Decision F).
- Contact PII (name/email/phone) is stored PLAIN (no `encrypted` cast — §10.5 does not require it),
  minimized in admin props, NEVER on a public path; fixtures FICTIONAL; PII scanned before commit.
- Web validation = 302 + session errors; Inertia props snake_case; the message body is rendered as
  escaped plain text (never `dangerouslySetInnerHTML`); `SanitizesContent` is NOT used (plain text,
  not TipTap JSONB).
- The slices 001–005 suites stay green: submission runs unconfined; the org/branch Delete Actions
  are UNTOUCHED (both parents soft-delete, so the new RESTRICT FK never trips; `contact_messages` is
  NOT added to the `DeleteBranchAction` cascade — it has no `deleted_at`).

## 5. Success = these are falsifiable and green

The org-scoped READ crown (a confined manager of org A sees ONLY org-A messages — falsifiable
against the physical `withoutGlobalScope` count) and the branch→org consistency test (a `branch_id`
of a different org → 302 + `branch_id` error, no row written) are the load-bearing,
must-be-RED-against-a-naive-impl tests. The TAMPER test (an unknown/forged payload key is inert) and
the graceful branch-soft-delete regression (a branch with contact messages soft-deletes without a
500, the messages survive) are the anonymous-write-hardening and cross-slice crowns.

## 6. Decisions to lock in plan.md

- **A. `contact_messages.id` type.** `$table->id()` **bigint** — consistent with the established
  slice-003 Deviation (all CMS ids are bigint, not the SPEC's ULID note). Lock as bigint.
- **B. NO `status` enum, NO moderation lifecycle, NO exception, NO bootstrap render.** §6.3.11 has no
  `status` column; §7.3 gives only `index`. Do NOT invent a `ContactMessageStatus` enum or any
  approve/reject/spam Action. The "public anonymous unmoderated-state" lesson reduces to "there is
  nothing to moderate" — the row is the terminal state. Lock: no enum, no transition guard.
- **C. NO `SoftDeletes`.** §6.3.11 has no `deleted_at`; §6.4 marks the row a permanent audit record.
  ⇒ no `SoftDeletes` trait, no delete Action, no admin delete route. Lock.
- **D. Both FKs from the public payload + the branch→org assertion.** `organization_id` and
  `branch_id` are NOT NULL RESTRICT and BOTH come from the anonymous submitter's payload (there is
  no confined caller to stamp from — Decision F mirror of slice-005). `SubmitContactAction` MUST
  assert the chosen branch belongs to the chosen org (Engagement-local
  `AssertsBranchBelongsToOrganization`, copied from Jobs, scope-free `organization_id` match) → 302
  + `branch_id` error on mismatch. Lock: no server-side org-stamp; assert consistency instead.
- **E. The org/branch Delete Actions are UNTOUCHED + a regression proves it.** Both parents
  SOFT-delete, so the new RESTRICT FK on `contact_messages` is never tripped (no hard DELETE on the
  parent). `DeleteOrganizationAction`/`DeleteBranchAction` get NO new pre-check, and
  `contact_messages` is NOT added to the `DeleteBranchAction` application-level cascade (the cascade
  soft-deletes children; a contact message has no `deleted_at` and is a permanent audit record — it
  survives the branch's soft delete, pointing at the trashed branch). A regression test asserts a
  branch with contact messages soft-deletes gracefully (no 500) and the messages survive. **No arch
  allow-list edge is added to the Organization rule** (the Organization domain does NOT reference
  `App\Domain\Engagement\Models` — the contrast with the slice-005 `DeleteMunicipalityAction` edge,
  which WAS needed because `members.municipality_id` is RESTRICT to a HARD-deleted municipality).
  Lock this contrast explicitly.
- **F. The public submission org/branch provenance.** The public form offers `organization_options`
  + `branch_options` (the latter dependent on the chosen org — a `/api/branches?organization_id=X`
  partial reload, OR pre-loaded per org). `organization_id`/`branch_id` are Required+`Exists`;
  `SubmitContactAction` persists them as-is AFTER the consistency assertion (unconfined; no override).
  Lock: the submitter declares the target org/branch; the Action validates internal consistency, not
  ownership (there is no owner — the submitter is anonymous).
- **G. The contact FORM is a COMPONENT, not a page; no `GET /contact` route.** §8.3 lists
  `ContactForm.tsx` (a component) and §8.1 has no `Contact/Create` page; §7.1 defines only
  `POST /contact`. The form is embedded in the landing/footer (its org/branch option lists ride the
  existing landing/shared props). NO new GET route ships. Lock (a standalone `/contacto` page is a
  DEFERRED follow-on).
- **H. Contact PII is stored PLAIN (no `encrypted` cast).** §10.5 encrypts ONLY member CURP/RFC;
  contact email/phone are not in the §10.5 table. Lock: plain columns, PII minimized in props +
  never public + scanned before commit. (Contrast slice-005 Member, which DID encrypt.)
- **I. The phone validator is REUSED, not re-created.** `phone` uses the existing
  `App\Domain\Shared\Rules\MexicanPhone` (10-digit) from slice-005 — `email` uses Spatie's `#[Email]`.
  No new validation-rule class. Lock.
- **J. The arch boundary edges.** `App\Domain\Engagement` toOnlyUse: `App\Domain\Shared` (the
  MexicanPhone rule), `App\Support` (the OrganizationScope the model registers in `booted()`),
  `App\Domain\Organization\Models` (the Organization/Branch belongsTo + the branch→org assertion
  target — a cross-domain MODEL ref, NOT an Action call), `Illuminate`, `Spatie\LaravelData`,
  `Spatie\TypeScriptTransformer`, `Database\Factories`; `->ignoring(['__','now','request'])`; plus a
  `never depends on HTTP` guard and the final/DTO-final rules. (NO `App\Models` edge is needed —
  there is no acting-User argument on any Engagement Action; NO `Carbon` edge — the DTO has no
  CarbonImmutable field. Confirm both during plan and DROP them from the SPEC §11.2 stub's broad
  toOnlyUse if PHPStan/arch shows they are unused.) Lock the exact edge set.
