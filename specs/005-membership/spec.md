# Spec 005 — Membership Domain (union members — public registration + org-scoped admin review)

> **Phase:** Specify (the WHAT and WHY, not the HOW).
> **SSOT:** `SPEC.md` (§3.6 Membership MEMBER-01..03 + the **PII Validation** block [CURP/RFC/
> municipality]; §6.3.12 `members` table; §6.4 FK summary [`organization_id` **SET NULL**,
> `municipality_id` **RESTRICT**]; §7.1 public `POST /membership/register`; §7.3 Manager+
> `/admin/members` index + `/{id}/approve` + `/{id}/reject`; §10.2 RBAC [Members CRUD: super_admin
> All / administrator All / manager Own-org / editor —]; §10.4 rate-limit [5/hour/IP];
> §10.5 PII protection [CURP+RFC Eloquent `encrypted`, decrypted only for admin/manager of same
> org]; §11.2 Membership arch boundary; §11.4 #9/#10 [CURP/RFC format unit tests]; Appendix A
> `MemberStatus`; Appendix B `CurpFormat`/`RfcFormat`/`MexicanPhone`; §1.4/§1.5 magenta theme).
> **Reference impl (same stack, slice-004 Jobs — built + reviewed):** the org-scoping spine
> (`App\Support\OrganizationContext` + `App\Support\OrganizationScope` + `EnsureOrganizationScope`
> `org.scope` middleware ordered BEFORE `SubstituteBindings`); `CreateJobPostingAction`'s
> server-side org-stamp; the `AssertsBranchBelongsToOrganization` trait (Jobs-local, references
> the Organization `Branch` MODEL — arch-allow-listed); `JobOrgIsolationTest` (the falsifiable
> READ + WRITE crown); `ToggleJobStatusAction` + `InvalidJobTransitionException` (the lifecycle
> guard + its `bootstrap/app.php` render); `Public\ArticleController` (unconfined + status-filtered
> public read); `Admin\JobController` (anemic CRUD); `DeleteMunicipalityAction` (the
> restrict-FK in-use pre-check, which THIS slice must EXTEND — members add a second
> `municipality_id` referrer). `omnibus-uniges` remains the upstream Action/DTO/PII pattern source.
> **Built on (DO NOT break — the slices 001–004 suites are green):** slice 001 Identity
> (`UserRole` ladder, `EnsureRole` `role:<level>`, `App\Models\User` + its `organization_id` FK),
> slice 002 Content (the `Public\*Controller` unconfined read pattern, the `bootstrap/app.php`
> exception-render pattern), slice 003 Organization (`Organization`, `Branch`, `Municipality`
> models, the org-scoping spine, `DeleteOrganizationAction`/`DeleteMunicipalityAction`), slice 004
> Jobs (the public + admin split, the lifecycle exception pattern), the arch suite, the magenta
> theme + `lang/{es,en}.json`.
> **Status:** DRAFT — awaiting human review gate before `/sdd-plan` / build.

## 1. Problem & why

This is a **sindical (union) CMS** — its core entity is the **member (afiliado)**. SPEC §3.6
(MEMBER-01..03), §6.3.12 (`members`), §7.1 (`POST /membership/register`), §7.3
(`/admin/members*`) and §10.5 (PII) define exactly this entity and its two-sided lifecycle:

1. **MEMBER-01 — public registration.** Anyone fills a **public** form (PII fields: name, CURP,
   RFC, DOB, address, municipality, phones) and a `members` row is created with
   `status = pending`. This is the FIRST and only **unconfined public WRITE** in the whole CMS
   (Content/Jobs public paths are read-only; Contact is the only sibling, slice 006). It is
   rate-limited (5/hour/IP, §10.4).
2. **MEMBER-02 — view members.** An admin/manager lists members **scoped to their organization**
   (§10.2: manager = Own-org; administrator/super_admin = All). PII is **decrypted only for
   authorized viewers** of the same org. Editor has **NO access** (§10.2 Members CRUD: editor `—`).
3. **MEMBER-03 — approve / reject.** A manager reviews **pending** registrations and transitions
   them `pending → approved` or `pending → rejected` (both terminal). This is the lifecycle.

This slice exercises the org-scoping spine in a NEW shape vs slice 004:

- **No admin Create/Update/Destroy and no `branch_id`.** §7.3 gives admin only `index` + `approve`
  + `reject` (registration is the public path; SPEC defines no admin member CRUD, no
  member→branch FK). So the slice-004 "stamp org + assert branch belongs to org" Create crown does
  **not** apply to an admin path — there is no admin member-create and no branch FK to assert.
- **The WRITE crown moves to the lifecycle + the public org-assignment.** The falsifiable
  tenant-WRITE isolation here is: a confined manager of org A **cannot** approve/reject an org-B
  member (the route-model-bound `{member}` 404s under `OrganizationScope`), and the public
  registration assigns `organization_id` from the **public payload** (a registrant-chosen org,
  `Exists`-validated, or `null`) — a confined session never silently re-stamps it (registration is
  always unconfined).
- **PII-at-rest is the new first-class concern.** CURP + RFC are stored via the Eloquent
  `encrypted` cast (§10.5) — opaque in the DB, decrypted only when an authorized viewer reads the
  model. Format validation (CURP 18-char, RFC 13-char, Mexican 10-digit phone) runs in the DTO
  via the Appendix-B `ValidationRule` classes.
- **A second `municipality_id` referrer.** `members.municipality_id` is RESTRICT (§6.4). The
  existing `DeleteMunicipalityAction` in-use pre-check only counts `organizations` today; it MUST
  be extended to ALSO refuse a municipality still referenced by any member (else the restrict FK
  500s) — the slice-004 cross-slice cascade lesson, applied to a catalog delete.

Getting the public-registration org-assignment, the cross-org approve/reject 404, and the PII
encryption right the first time is the whole point.

## 2. Scope

### In scope (Membership MVP — public registration + org-scoped admin review + PII)

1. **`MemberStatus` backed enum** (`app/Domain/Membership/Enums/MemberStatus.php`,
   `#[TypeScript]`, `string`-backed) — `Pending = 'pending'`, `Approved = 'approved'`,
   `Rejected = 'rejected'` (Appendix A; §6.3.12 default `pending`). Owns `allowedTransitions()`
   (`pending → [approved, rejected]`; `approved → []`; `rejected → []` — both terminal),
   `canTransitionTo()`, `labelKey()` (`member_status.<value>`), `color()` (magenta palette).
   No magic strings. Mirrors `JobStatus` but with terminal approve/reject.

2. **`members` table + `Member` model** (SPEC §6.3.12) — bigint id (matches the live
   `users`/`articles`/`branches`/`job_postings` ids; the established slice-003 Deviation, NOT the
   SPEC's ULID note); `organization_id` (**nullable**, FK `SET NULL`), `municipality_id`
   (**NOT NULL**, FK `RESTRICT`); PII columns: `curp` TEXT (encrypted), `rfc` TEXT (encrypted),
   `first_name`/`last_name_paternal`/`last_name_maternal` VARCHAR(100), `date_of_birth` DATE,
   `address` TEXT, `postal_code` VARCHAR(5), `neighborhood` VARCHAR(100), `phone` VARCHAR(10)
   nullable, `mobile` VARCHAR(10) NOT NULL, `is_affiliated` BOOLEAN default false; `status`
   (string column, `MemberStatus` cast, default `pending`); timestamps, `SoftDeletes`. Indexes
   per §6.3.12: `organization_id`, `municipality_id`, `status`. Registers `OrganizationScope` in
   `booted()` from birth. **`curp`/`rfc` cast `encrypted`** (§10.5); `date_of_birth` cast
   `date`; `is_affiliated` cast `boolean`; `status` cast `MemberStatus`.

3. **`RegisterMemberData` DTO** (Spatie Data, `#[TypeScript]`) — the PUBLIC registration payload.
   All PII fields Required with the right max/format. `curp` → `CurpFormat` rule (18-char
   `/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/`); `rfc` → `RfcFormat` (13-char
   `/^[A-ZN&]{3,4}\d{6}[A-Z0-9]{3}$/`); `mobile` Required + `MexicanPhone` (10-digit); `phone`
   Nullable + `MexicanPhone`; `postal_code` Required + 5-digit; `date_of_birth` Required + a
   sane `before:today`; `municipality_id` Required + `Exists('municipalities','id')`;
   `organization_id` **Nullable** + `Exists('organizations','id')` (the registrant may pick an
   org or register org-less — §6.3.12 nullable). `is_affiliated` is NOT in the public DTO (it
   defaults false; not a public-settable flag). `status` is NOT in the DTO (server-set `pending`).

4. **Actions** (Illuminate\Http-free, `DB::transaction`, one op each):
   - `RegisterMemberAction` — the PUBLIC create. Stamps `status = MemberStatus::Pending`;
     persists `organization_id` from the payload **as-is** (registration is always unconfined —
     there is no confined caller to override; a null org is valid). NO branch assertion (no
     `branch_id`). `curp`/`rfc` are written through the `encrypted` cast (the Action passes plain
     values; the cast encrypts on save). Returns the `Member`.
   - `ApproveMemberAction` — `pending → approved` lifecycle. Uses `MemberStatus::canTransitionTo`;
     throws `InvalidMemberTransitionException` on an illegal edge (e.g. approving an already
     `approved`/`rejected` member), rendered 302 + `status` field error / 422 JSON. On approve,
     sets `is_affiliated = true` (an approved member is affiliated — §6.3.12 `is_affiliated` is
     the post-approval flag). Returns the `Member`.
   - `RejectMemberAction` — `pending → rejected` lifecycle; same transition guard; leaves
     `is_affiliated = false`. Returns the `Member`.
   - **NO** `UpdateMemberAction`, **NO** `DeleteMemberAction` in this MVP (SPEC §7.3 defines no
     admin member update/destroy route). Member soft-delete exists at the schema level
     (`SoftDeletes`) for future use + the org-deletion `SET NULL` semantics, but no admin delete
     UI/route ships. **See §6 Decision D.**

5. **`InvalidMemberTransitionException`** (`app/Domain/Membership/Exceptions/`) + its
   `bootstrap/app.php` render (302 + `status` field error on web, 422 JSON) — mirror
   `InvalidJobTransitionException`.

6. **`Admin\MemberController`** (anemic, ≤15 lines/method) — `index` (org-scoped list, paginated,
   snake_case rows, optional `?status=` filter), `approve`, `reject`. Gated under a
   `['auth','role:manager','org.scope']` group (SPEC §7.3 places `/admin/members*` in the
   **Manager+** table — editor has NO member access per §10.2). DTO-less mutations (approve/reject
   are bodyless POSTs; the bound `{member}` is the only input). The org.scope global scope confines
   a manager's list AND makes a cross-org `{member}` route-binding 404.

7. **`Public\MemberController`** (anemic) — `create` (renders the public `Membership/Register`
   form, unconfined, with `municipality_options` + `organization_options`) and `store`
   (`RegisterMemberData $data, RegisterMemberAction $action` → 302 + success flash). The store
   route carries the `throttle:5,60` rate limiter (§10.4: 5 submissions / 60 min / IP).
   **NO public READ of member data** — there is NO public member directory (§3.6/§7.1 define
   none; member PII is admin-only per §10.5). The public controller only WRITES (register) and
   serves the empty form.

8. **`CurpFormat`, `RfcFormat`, `MexicanPhone` ValidationRule classes** (Appendix B). Home:
   `app/Domain/Shared/Rules/` (domain-neutral, reusable; arch-allow-listed under `App\Domain\Shared`
   which every domain may use). Each is `final`, implements `Illuminate\Contracts\Validation\ValidationRule`,
   `strtoupper`-normalizes before matching, and `$fail(__('validation.<key>'))`.

9. **Routes** (SPEC §7.1, §7.3) — public `GET /membership/register` (`membership.create`),
   `POST /membership/register` (`membership.store`, `throttle:5,60`); admin
   `GET /admin/members` (`admin.members.index`), `POST /admin/members/{member}/approve`
   (`admin.members.approve`), `POST /admin/members/{member}/reject` (`admin.members.reject`).
   All `->name()`d, no closures.

10. **Inertia pages** — `Admin/Members/Index` (admin shell: a review table with a status badge
    + Approve/Reject actions on pending rows) and public `Membership/Register` (the PII form).
    Magenta theme, dark/light, bilingual via the locale hook. Snake_case props; a prop-contract
    test per page. The generated `MemberStatus` TS enum is **type-only imported**. **The admin
    Index renders only the minimum needed columns; full PII is shown to authorized viewers** —
    but even there, the list view exposes name + status + municipality + masked/limited identifiers
    (see §6 Decision E for exactly which PII columns the admin Index serializes).

11. **`MemberFactory`** + a demo seed addition — **FICTIONAL data only** (faker names; a
    syntactically-valid but **fake** CURP/RFC generated from faker, never a real one; no real
    emails/phones). Factory states: `pending()`, `approved()`, `rejected()`, `forOrganization()`,
    `forMunicipality()`, `affiliated()`, `orgLess()`.

12. **`DeleteMunicipalityAction` EXTENSION (the cross-slice lesson).** `members.municipality_id`
    is a SECOND RESTRICT referrer of `municipalities`. The existing in-use pre-check counts only
    `organizations`; it MUST additionally refuse deletion when any member (incl. trashed) holds
    that `municipality_id`, so the restrict FK never 500s. **A regression test proves a
    municipality referenced by a member alone is refused gracefully (302 + `municipality` error).**

13. **i18n** — every `__()` key in BOTH `lang/es.json` AND `lang/en.json`:
    `membership.registered`, `members.approved`, `members.rejected`,
    `members.error.invalid_transition`, `member_status.pending|approved|rejected`,
    `validation.curp_format`, `validation.rfc_format`, `validation.mexican_phone`.
    (`municipalities.error.in_use` already exists — REUSED for the member referrer; its wording
    may broaden to "organizations or members" — plan decides.)

14. **Tests** — public registration CRUD (creates a `pending` member; rate-limit), validation
    302s (CURP/RFC/phone/postal/DOB/required), the lifecycle (legal `pending→approved/rejected`
    + illegal edges), the org-isolation crown (READ — a manager sees only its org's members;
    WRITE — a confined manager CANNOT approve/reject an org-B member, falsifiable), role gating
    (editor has NO member access; guest → login; manager+ reach the index), the PII-encryption
    invariant (CURP/RFC are ciphertext at rest, plaintext via the model accessor), the
    municipality-in-use regression (a member-only referrer blocks the catalog delete), prop-contract
    per page, lang-key resolution, the `MemberStatus` unit test, the CURP/RfcFormat/MexicanPhone
    unit tests (§11.4 #9/#10), and the arch boundary (`App\Domain\Membership` only uses
    `App\Domain\Shared` + `App\Models` + `App\Support` + `App\Domain\Organization\Models` +
    Illuminate/Spatie/Database\Factories).

### Out of scope / DEFERRED (with notes)

- **Admin member CREATE / UPDATE / DELETE.** SPEC §7.3 gives `/admin/members` only `index` +
  `approve` + `reject`. There is NO admin create/update/destroy route, no member edit form.
  Registration is the sole create path (public). **DEFERRED — note for a future slice if the
  product adds back-office member editing.** (The schema carries `SoftDeletes` so a future
  admin delete is a small follow-on; nothing here ships it.)
- **A public member directory / public member profile.** §3.6/§7.1 define NO public read of
  member data, and §10.5 makes CURP/RFC admin-only. **DEFERRED — no public PII exposure ships.**
  (The LESSON "a public-facing flag may need an unconfined-but-filtered read" does NOT apply:
  SPEC marks all member data PRIVATE; `is_affiliated` is an internal flag, not a public directory
  toggle.)
- **Dues / fees / payment tracking.** SPEC §3.6 + §6.3.12 define NO dues, fee, or payment column
  on `members` (no integer-cents money field anywhere in the member schema). The union-dues
  concept is not in this MVP. **DEFERRED — note for a future Finance slice if the product adds it.**
- **Member registration trend analytics** (§3.7 ANALYTICS, "Members: registration trend").
  Analytics is its own slice (§3.7); this slice ships the `members` read model + `status`/
  `created_at` the trend needs. **DEFERRED to the Analytics slice.**
- **ETL of legacy `Curp_Afiliado`/`Rfc_Afiliado`/`Municipio_Afiliado`** (§12.3 mapping table:
  encrypt CURP/RFC, municipality-name→FK lookup). That is the §12 ETL slice, not the greenfield
  build. This slice accepts already-FK municipality + plain CURP/RFC (encrypted on save).
  **DEFERRED to §12 ETL.**
- **Per-viewer PII redaction policy beyond org-scoping.** §10.5 says "decrypted only for
  admin/manager of same org" — the org scope already confines WHICH members a viewer sees; the
  `encrypted` cast transparently decrypts for any code that reads the model. A finer field-level
  redaction (e.g. mask CURP for a manager but full for super_admin) is NOT in §10.5. **DEFERRED —
  org-scoping + encryption-at-rest is the §10.5 contract; no extra masking ships.** (But the admin
  Index serializes a deliberately limited column set — §6 Decision E.)

## 3. Acceptance scenarios (When… Then…)

**MEMBER-01 — Public registration**
- When ANY visitor (anonymous) POSTs `/membership/register` with valid PII (name parts ≤100, a
  valid 18-char CURP, a valid 13-char RFC, a valid 10-digit mobile, a 5-digit postal_code, a DOB
  before today, an existing `municipality_id`, and either an existing `organization_id` or none),
  Then a `members` row is created with `status = pending`, `is_affiliated = false`, the chosen (or
  null) `organization_id`, and a 302 + success flash. The CURP/RFC are CIPHERTEXT in the DB.
- When the CURP fails the 18-char pattern / the RFC fails the 13-char pattern / the mobile is not
  10 digits / postal_code is not 5 digits / DOB is today-or-future / a required field is empty,
  Then 302 + session errors on the offending field(s) (never 422), and no row is written.
- When `municipality_id` does not exist, Then 302 + a `municipality_id` error; no row written.
- When `organization_id` is supplied but does not exist, Then 302 + an `organization_id` error;
  when `organization_id` is omitted, Then the member is created org-less (null) — valid.
- When the same IP POSTs `/membership/register` a 6th time within 60 minutes, Then a 429 (the
  `throttle:5,60` limiter, §10.4); the first 5 succeed.

**MEMBER-02 — View members (READ confinement)**
- When a manager of org A GETs `/admin/members`, Then ONLY org-A members appear (falsifiable:
  `Member::withoutGlobalScope(OrganizationScope::class)->count()` exceeds the visible count).
  Org-less (null-org) members do NOT appear for a confined manager (the scope's
  `organization_id = A` predicate excludes nulls).
- When a super_admin / administrator GETs `/admin/members`, Then ALL orgs' members (incl. org-less)
  appear (unconfined).
- When an authorized viewer reads a member, Then the CURP/RFC are decrypted (plaintext via the
  model accessor) — the `encrypted` cast is transparent for the model owner.

**MEMBER-03 — Approve / Reject (lifecycle + WRITE confinement, the crown)**
- When a manager of org A POSTs `/admin/members/{member}/approve` for a PENDING org-A member,
  Then the member becomes `approved`, `is_affiliated = true`, 302 + flash.
- When a manager of org A POSTs `/admin/members/{member}/reject` for a PENDING org-A member,
  Then the member becomes `rejected`, `is_affiliated` stays false, 302 + flash.
- When a manager approves/rejects an ALREADY `approved`/`rejected` member (an illegal edge), Then
  302 + a `status` field error (never a 500), and the status is unchanged.
- **WRITE crown:** When a confined manager of org A POSTs approve/reject for an **org-B** member,
  Then a **404** (the route-model-bound `{member}` is unresolvable under `OrganizationScope`) —
  falsifiable: the org-B member's status is physically unchanged
  (`Member::withoutGlobalScope(...)` still shows it `pending`). A confined manager can NEVER
  mutate another org's member.

**Role gating (SPEC §7.3, §10.2)**
- When a guest hits `/admin/members*`, Then 302 → login.
- When an authenticated **editor** reaches `/admin/members`, Then a **403** (editor has NO member
  access — §10.2 Members CRUD editor `—`; the route group is `role:manager`).
- When a manager / administrator / super_admin reaches `/admin/members`, Then 200.

**PII at rest (§10.5)**
- A persisted member's `curp`/`rfc` columns hold CIPHERTEXT (a raw DB read does NOT equal the
  plaintext); reading `$member->curp`/`$member->rfc` through the model returns the plaintext.
  (DB-shape + accessor assertion.)

**Municipality-in-use regression (the cross-slice lesson)**
- When an administrator deletes a municipality referenced ONLY by a member (no organization uses
  it), Then 302 + a `municipality` field error (graceful), the municipality survives, and no
  restrict-FK 500 occurs.

## 4. Non-goals / invariants restated

- Domain code never imports `Illuminate\Http`; `OrganizationContext` (App\Support) + the
  `App\Domain\Shared\Rules` validators are OK.
- Controllers anemic (≤15 lines/method): public store = DTO→Action→response; admin approve/reject =
  bound `{member}`→Action→response.
- `declare(strict_types=1)`, `final`, `readonly` VO/DTO, backed enums, no magic strings.
- Migration reversible; `members.organization_id` FK **SET NULL** (NOT restrict — a deleted org
  nulls its members, never blocks), `members.municipality_id` FK **RESTRICT**; `SoftDeletes`.
- CURP + RFC **encrypted** at rest (Eloquent `encrypted` cast); fixtures FICTIONAL (no real
  CURP/RFC/PII); PII scanned before any output/commit.
- Web validation = 302 + session errors; Inertia props snake_case; generated TS enum is type-only.
- NO public read of member PII (no public directory). Member CRUD is editor-EXCLUDED.
- The slices 001–004 suites stay green (registration runs unconfined; the org-deletion guard
  must NOT add a members RESTRICT pre-check — members are SET NULL, not RESTRICT).

## 5. Success = these are falsifiable and green

The cross-org WRITE test (a confined manager of org A CANNOT approve/reject an org-B member — it
404s and the org-B member's status is physically unchanged) and the PII-encryption test
(`curp`/`rfc` are ciphertext at rest, plaintext via the accessor) are the load-bearing,
must-be-RED-against-a-naive-impl tests. The municipality-in-use regression (a member-only referrer
blocks the catalog delete) is the cross-slice crown.

## 6. Decisions to lock in plan.md

- **A. `members.id` type.** `$table->id()` **bigint** — consistent with the established slice-003
  Deviation (all CMS ids are bigint, not the SPEC's ULID note). Lock as bigint.
- **B. `members.organization_id` FK rule = SET NULL (NOT restrict).** §6.4 is explicit. This means
  (i) `DeleteOrganizationAction` MUST NOT gain a members pre-check (members survive org deletion,
  nulled); (ii) `OrganizationScope` correctly hides null-org members from a confined manager
  (the `= A` predicate excludes null) — org-less members are super_admin-only. Lock and add a
  regression note: deleting an org with members succeeds and nulls their `organization_id`.
- **C. The `MemberStatus` transition graph.** `pending → [approved, rejected]`; `approved → []`;
  `rejected → []` (both terminal). No reactivation, no un-approve in this MVP. Lock the exact edges.
- **D. No admin Update/Delete Action/route.** Confirm §7.3 has only index/approve/reject; ship NO
  `UpdateMemberAction`/`DeleteMemberAction`/edit page. `SoftDeletes` stays on the schema for the
  org `SET NULL` + future use. Lock.
- **E. The admin Index PII column set.** The review table needs enough to triage: `id`,
  `full_name` (first + paternal + maternal), `municipality_name`, `status` (+ `status_label_key`),
  `is_affiliated`, `created_at`. **CURP/RFC are NOT serialized into the Index list** (decrypting
  PII into a bulk JSON prop for a list view is needless exposure — they belong only to a future
  per-member detail view, which is itself DEFERRED). Lock: the admin Index serializes the
  non-CURP/RFC column set above; CURP/RFC never enter an Inertia prop in this slice.
- **F. The public registration org assignment.** The public form offers an `organization_options`
  picker; `organization_id` is Nullable+`Exists`. `RegisterMemberAction` persists it as-is
  (registration is unconfined; no confined override). A null org = a member pending an admin to
  later associate (future). Lock: no server-side org-stamp on the PUBLIC path (the opposite of the
  Jobs admin path — registration has no confined caller).
- **G. The validation-rule home.** `CurpFormat`/`RfcFormat`/`MexicanPhone` live in
  `app/Domain/Shared/Rules/` (so the DTO in `App\Domain\Membership` can `use` them arch-clean —
  `App\Domain\Shared` is the universal allow-list entry, and these rules are reusable catalog-wide).
  Lock vs a Membership-local home.
- **H. `is_affiliated` semantics.** Default false on registration; set true by `ApproveMemberAction`
  on `pending → approved`; stays false on reject. It is NOT a public-settable nor admin-toggle
  field in this MVP. Lock.
- **I. The municipality in-use pre-check broadening.** `DeleteMunicipalityAction::isReferenced`
  gains a member referrer check (`Member::withoutGlobalScope(OrganizationScope)->withTrashed()
  ->where('municipality_id', …)->exists()`). The arch test must allow the Organization domain to
  reference the `App\Domain\Membership\Models` MODEL for this in-use count (a cross-domain MODEL
  reference, NOT an Action call) — mirror the slice-004 `App\Domain\Jobs\Models` allow-list edge
  added to the Organization rule. Lock the arch allow-list update.
