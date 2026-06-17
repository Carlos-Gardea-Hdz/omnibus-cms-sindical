# BUILD-PLAN.md — Corporate CMS execution roadmap

Derived from `SPEC.md` (the single source of truth). This sequences the build in
**dependency order** so each step compiles and its Pest tests pass before the next.
Run inside a session with a live toolchain — verify continuously with
`vendor/bin/pest`, `vendor/bin/phpstan` (Larastan level 9), `vendor/bin/pint`.

> Status: **foundation only**. `scaffold.sh` + `docker-compose.dev.yml` +
> `.env.example` exist. No domain PHP is written yet — start at Step 0.

---

## Step 0 — Foundation (run once)

```bash
./scaffold.sh
cp .env.example .env && php artisan key:generate
docker compose -f docker-compose.dev.yml up -d        # PG18, Valkey, Meilisearch, Mailpit
php artisan inertia:middleware                          # then register in bootstrap/app.php
```

Wire up: `vite.config.ts` (+`@vitejs/plugin-react`, `@tailwindcss/vite`),
`resources/js/app.tsx` (Inertia React root), `phpstan.neon` (Larastan, level 9),
`tests/Pest.php`. Add the **arch tests** first (they encode the Law and fail fast):

- every file under `app/` has `declare(strict_types=1)`
- `app/Domain/**` classes are `final`
- controllers are anemic (no `DB`, no `Validator`, ≤ 15 lines) — assert via arch
- no Action is imported across domains (cross-domain = Events only, SPEC §5.2)

---

## Phase 1 — Foundation + Auth + Content (MVP)

### 1a. Shared + Identity primitives (no FK deps)
1. **Value Objects** (`app/Domain/Shared/ValueObjects/`): `Slug`, `Email`, `Money`,
   `Phone` — copy the canonical implementations from SPEC §5.5 verbatim. Pest unit
   tests for each (valid + invalid construction).
2. **Trait** `Shared/Traits/HasUlid.php` — ULID (CHAR(26)) primary keys for all models.
3. **Enums**: `Identity/Enums/UserRole` (SPEC App.A — `level()`, `canManageUsers()`…),
   `Content/Enums/ArticleStatus` (with `canTransitionTo()`), `Jobs/Enums/JobStatus`,
   `Membership/Enums/MemberStatus`, `Organization/Enums/RepresentativeShift`. Pest tests
   for transition matrices.

### 1b. Migrations (STRICT FK ORDER — SPEC §6.4)
Create in this order so every FK target exists first. All parents use
`SoftDeletes`; FK `onDelete` per SPEC §6.4 (RESTRICT default, CASCADE only
`article_images`, SET NULL for `members.organization_id` / `page_views.article_id`):

```
municipalities → directors → organizations → branches → representatives
→ users → categories → articles → article_images → job_postings
→ contact_messages → members → daily_snapshots → page_views
```
(`organizations.director_id` is a nullable UNIQUE FK added in a follow-up
`alter` migration to break the directors↔organizations cycle.)
Verify: `php artisan migrate` then `php artisan migrate:rollback` (reversibility).

### 1c. Models + Identity domain
4. Eloquent models for the tables above (casts: enums, `content`→array/JSONB,
   `curp`/`rfc`→`encrypted`, money columns via `Money` cast). Relationships per §6.2.
5. **Identity**: `LoginData`, `CreateUserData` (Spatie Data, `#[TypeScript]`);
   `AuthenticateUserAction`, `CreateUserAction`; `UserPolicy`; `UserLoggedIn` event;
   `InvalidCredentialsException`. Login + demo-login (AUTH-01/02, 30-min TTL, rate limits).
6. **Middleware**: `EnsureRoleMiddleware` (4-level), `DemoSessionMiddleware`,
   `SecurityHeadersMiddleware`. Register in `bootstrap/app.php`.

### 1d. Content domain + Landing
7. `HtmlSanitizerService` (whitelist SPEC §3.3); `CreateArticleData`,
   `PublishArticleData`; `CreateArticleAction`, `PublishArticleAction` (transactional,
   dispatch `ArticlePublished`); `CategoryController` with delete protection (CAT-02).
8. Anemic controllers (≤15 lines) + Inertia pages: `Articles/{Index,Create,Edit,Show}`,
   `Categories/Index`, `Landing/Index` (8 articles + 6 jobs), `Auth/Login`.
9. **Frontend core**: `ThemeContext` (dark/light, class on `<html>`), `LocaleContext`
   (ES/EN, `locale` shared prop), `AuthenticatedLayout`/`GuestLayout`/`LandingLayout`.
10. `php artisan typescript:transform` → `resources/js/Types/generated.d.ts`.

**Phase 1 done when:** SPEC §15 Phase 1 acceptance boxes all pass + arch tests green.

---

## Phase 2 — Organization + Jobs + Engagement
- Organization/Branch/Representative/Director CRUD; `UniqueDirectorPerOrganizationRule`.
- `DeleteBranchAction` — **application-level** cascade (soft-delete articles + remove
  image files in a transaction), NOT DB cascade (SPEC §3.2, §6.1). HTTP 422 on blocked deletes.
- `JobPosting` with `salary_*_cents` (BIGINT, `Money` VO); salary string→cents transform (JOB salary rule).
- `ContactMessage` + `SubmitContactAction`, rate-limited 3/15min (CONTACT-01).
- `EnsureOrganizationScopeMiddleware` (manager scoping).

## Phase 3 — Membership + Search + Analytics
- `Member` with `curp`/`rfc` encrypted casts; `Curp`/`Rfc` value objects (regex SPEC §3.6);
  `RegisterMemberAction`, `ApproveMemberAction`.
- Scout + Meilisearch: make `Article` searchable, filters (category/date/org), manager scoping (NEWS-06).
- `TrackPageViewAction` (IP-hash dedup, 1/IP/article/24h) via `TrackPageViewMiddleware`;
  `GetOrganizationStatsAction` + `SegmentedStatsService`; `Analytics/Index` dashboard.

## Phase 4 — ETL + Demo + Deploy
- `migrate:legacy` ETL command (legacy MySQL → PG, salary/municipality transforms).
- Demo mode: ephemeral data, 15-min cleanup job (SPEC §13).
- Deploy via `docker-compose.prod.yml` to `cms.carlosgardea.com` (**human-gated**, see
  `~/dotfiles/docs/claude-code-vps-user.md`). Lighthouse ≥ 90, then portfolio `available: true`.

---

## Standing constraints (from CLAUDE.md + SPEC §5.2)
- `declare(strict_types=1)` everywhere; `final` domain classes; explicit return types; no `mixed`.
- Validation ONLY via Spatie Data DTOs — **no** `$request->validate()` / FormRequest.
- Controllers anemic (≤15 lines); business logic in Actions (`DB::transaction()` multi-table).
- Cross-domain calls via Events only; Money as integer cents; ULID PKs.
- Every commit GPG-signed, Conventional Commits, feature branch → PR. Verify reversibility before migrating.
- pnpm only (npm is blocked by policy). Dark/light + ES/EN are non-negotiable.
