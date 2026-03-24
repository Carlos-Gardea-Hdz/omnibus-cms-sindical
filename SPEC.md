# SPEC.md — Corporate CMS (Plataforma Empresarial)

**Version:** 1.0.0
**Created:** 2026-03-23
**Status:** Active
**Stack:** Laravel 12 + PostgreSQL 18 + React 19 + Inertia.js 2.0 + Tailwind CSS v4 (Oxide)
**Repository:** omnibus-cms
**Domain:** cms.carlosgardea.com

> **Precedence:** This document is the Single Source of Truth for Corporate CMS.
> It supersedes MASTER-BLUEPRINT-CMS.md (in omnibus-legacy/cms/) when conflicts exist.
> Code must conform to this spec — not the other way around.

---

## Changelog

| Version | Date | Description |
|---------|------|-------------|
| 1.0.0 | 2026-03-23 | Initial SPEC — consolidates MASTER-BLUEPRINT + RESUMEN_PROYECTO + Biblia de Laravel chapters 3.x |

---

## Table of Contents

1. [Project Identity](#1-project-identity)
2. [Vision & Business Objectives](#2-vision--business-objectives)
3. [Functional Requirements](#3-functional-requirements)
4. [Non-Functional Requirements](#4-non-functional-requirements)
5. [System Architecture](#5-system-architecture)
6. [Data Model](#6-data-model)
7. [Route Design](#7-route-design)
8. [Frontend Architecture](#8-frontend-architecture)
9. [Integration Points](#9-integration-points)
10. [Security Protocol](#10-security-protocol)
11. [Testing Strategy](#11-testing-strategy)
12. [Migration Strategy (ETL)](#12-migration-strategy-etl)
13. [Demo Mode Specification](#13-demo-mode-specification)
14. [Deployment Architecture](#14-deployment-architecture)
15. [Phased Delivery Plan](#15-phased-delivery-plan)
16. [Appendices](#16-appendices)

---

## 1. Project Identity

### 1.1 Naming

| Attribute | Value |
|-----------|-------|
| **Commercial Name** | Corporate CMS |
| **Internal Code** | `corporate-cms` |
| **Portfolio ID** | `plataforma-empresarial` |
| **Portfolio Letter** | A |
| **Production Domain** | `cms.carlosgardea.com` |
| **Staging Domain** | `cms.carlosgardea.cloud` |
| **Legacy Whitelabel Domain** | `cms-legacy.carlosgardea.com` |
| **GitHub Repository** | `omnibus-cms` |

### 1.2 Portfolio Position

Version order (non-negotiable narrative arc):

1. **Legacy** — Original client system (available: true, external domain)
2. **Whitelabel** — Neutral branding at `cms-legacy.carlosgardea.com` (available: false until deployed)
3. **Laravel** — Full Laravel 12 rebuild at `cms.carlosgardea.com` (available: false until deployed)

### 1.3 Portfolio Description

**ES:** "Plataforma de administracion empresarial multi-sucursal. Busqueda full-text con Meilisearch, RBAC de 4 niveles y analytics segmentado. Reconstruccion Laravel 12 de un sistema en produccion real."

**EN:** "Multi-branch corporate management platform. Full-text search with Meilisearch, 4-level RBAC and segmented analytics. Laravel 12 rebuild of a real production system."

### 1.4 Branding

#### Color Palette

| Role | Hex | Usage |
|------|-----|-------|
| **Primary** | `#DD00FF` | Buttons, links, accents |
| **Primary Light** | `#E647FF` | Hover states, soft backgrounds |
| **Primary Dark** | `#9211CF` | Active states, pressed buttons |
| **Primary 50** | `#FDF0FF` | Very light backgrounds |
| **Danger** | `#A03CC7` | Alerts, destructive buttons |
| **Danger Dark** | `#9211CF` | Danger hover |
| **Neutral Dark** | `#1F2937` | Primary text (light mode) |
| **Neutral Light** | `#F3F4F6` | Backgrounds (light mode) |
| **Dark BG** | `#0F172A` | Background (dark mode) |
| **Dark Surface** | `#1E293B` | Cards/containers (dark mode) |
| **Dark Border** | `#334155` | Borders (dark mode) |
| **Dark Text** | `#F1F5F9` | Primary text (dark mode) |

#### Typography

| Use | Font | Fallback |
|-----|------|----------|
| Headings | Inter | system-ui, sans-serif |
| Body | Inter | system-ui, sans-serif |
| Monospace | JetBrains Mono | monospace |

### 1.5 Tailwind v4 Theme (CSS-First)

**File:** `resources/css/app.css`

```css
@import "tailwindcss";

@source "../js/**/*.tsx";

@theme {
  /* Colors */
  --color-primary: #DD00FF;
  --color-primary-light: #E647FF;
  --color-primary-dark: #9211CF;
  --color-primary-50: #FDF0FF;
  --color-danger: #A03CC7;
  --color-danger-dark: #9211CF;

  /* Typography */
  --font-sans: "Inter", system-ui, sans-serif;
  --font-mono: "JetBrains Mono", monospace;

  /* Custom spacing */
  --spacing-18: 4.5rem;

  /* Animations */
  --animate-fade-in: fade-in 0.3s ease-in;
}

@keyframes fade-in {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

@layer utilities {
  .text-gradient-primary {
    @apply bg-gradient-to-r from-primary to-primary-dark bg-clip-text text-transparent;
  }
}
```

**IMPORTANT:** No `tailwind.config.js` file. Tailwind v4 uses the Oxide engine with CSS-first configuration exclusively.

### 1.6 Logo Assets

| Asset | Format | Dimensions | Path |
|-------|--------|------------|------|
| Logo Principal | SVG | Vectorial | `resources/images/logo.svg` |
| Logo White | SVG | Vectorial | `resources/images/logo-white.svg` |
| Logo Fallback | PNG | 400x100px | `resources/images/logo.png` |
| Favicon | ICO+PNG | 32x32, 16x16 | `public/favicon.ico` |
| App Icon | PNG | 180x180, 512x512 | `public/apple-touch-icon.png` |
| Placeholder Avatar | SVG | Vectorial | `resources/images/avatar-placeholder.svg` |
| Placeholder Logo | SVG | Vectorial | `resources/images/logo-placeholder.svg` |

---

## 2. Vision & Business Objectives

### 2.1 Problem Statement

The legacy system is a vanilla PHP application (~8,700 LOC, 47 files, 11 tables) with:
- No framework, no ORM, no routing layer
- Zero test coverage
- Critical security vulnerabilities (no CSRF, unauthenticated AJAX endpoints, PII in plaintext, SQL injection risks)
- Procedural architecture with 300+ LOC functions
- No soft deletes (physical deletion only, no audit trail)

### 2.2 Solution

A greenfield Laravel 12 rebuild demonstrating enterprise-grade architecture:
- DDD Lite domain organization with strict boundary enforcement
- Full-text search via Meilisearch
- 4-level RBAC with organization-scoped data access
- Segmented analytics dashboard
- Dark/light mode, bilingual UI (ES/EN)
- Comprehensive test coverage with Pest PHP

### 2.3 Target Audience

| Audience | What they evaluate |
|----------|--------------------|
| **Recruiters** | Code quality, architecture decisions, test coverage |
| **Developers** | Patterns, DDD implementation, Laravel best practices |
| **Potential clients** | Working demo, UX quality, feature completeness |

### 2.4 Success Metrics

| Metric | Target |
|--------|--------|
| Lighthouse Performance | >= 90 |
| Lighthouse Accessibility | >= 90 |
| TTFB (cached) | < 200ms |
| Meilisearch query P95 | < 50ms |
| Pest coverage on Actions | >= 80% |
| Pest coverage overall | >= 70% |
| Vitest coverage on Components | >= 60% |
| Larastan level | 9 (max) |
| OWASP Top 10 findings (critical/high) | 0 |

---

## 3. Functional Requirements

### 3.1 Identity Domain

| ID | User Story | Acceptance Criteria | Business Rules |
|----|------------|---------------------|----------------|
| AUTH-01 | Login with username + password | bcrypt verification, session regeneration, redirect to dashboard | Session lifetime: 120 minutes |
| AUTH-02 | Demo login with role selection | 30-min TTL, IP rate limit (10/hour), session flag `is_demo=true` | 3 roles available: administrator, manager, editor |
| AUTH-03 | Role-based access enforcement | 4-level middleware gates all admin routes | super_admin: cross-org; administrator: all within org; manager: own org scoped; editor: content only |
| AUTH-04 | User CRUD (admin only) | Create/update/delete users with role assignment | Super admin: only one at a time — assigning new unsets previous. Super admins can only edit their own record |
| AUTH-05 | Logout | Session destroy, cookie clear, redirect to login | Demo sessions also cleared properly |

### 3.2 Organization Domain

| ID | User Story | Acceptance Criteria | Business Rules |
|----|------------|---------------------|----------------|
| ORG-01 | CRUD organizations | Logo required, municipality FK, name max 100 | Cannot delete if branches exist (HTTP 422) |
| ORG-02 | CRUD branches | Name max 100, location max 100, belongs to organization | Cannot delete if representatives exist (422). If no representatives: application-level cascade of articles |
| ORG-03 | CRUD representatives | Photo optional, shift enum, coordinator boolean | Shift: morning, evening, night |
| ORG-04 | Assign director | One director per organization, UNIQUE constraint at DB level | Enforced via `UniqueDirectorPerOrganizationRule` |
| ORG-05 | CRUD municipalities | Reference catalog | Cannot delete if organizations attached (HTTP 422) |

#### Deletion Cascade Rules

| Entity | Has children? | Result |
|--------|---------------|--------|
| Municipality | Has organizations | **BLOCKED** (HTTP 422) |
| Organization | Has branches | **BLOCKED** (HTTP 422) |
| Branch | Has representatives | **BLOCKED** (HTTP 422) |
| Branch | No representatives | **Allowed** — application-level cascade: soft-delete associated articles + delete physical image files |

**IMPORTANT:** Cascade is handled at the **application layer** (inside a `DeleteBranchAction`), NOT via `ON DELETE CASCADE` in the database. All parent entities use SoftDeletes. See Section 6 for FK constraint details.

### 3.3 Content Domain

| ID | User Story | Acceptance Criteria | Business Rules |
|----|------------|---------------------|----------------|
| NEWS-01 | Create article | Title <= 150, subtitle <= 200, content as JSONB (TipTap format), featured image required on publish | Author <= 100 characters (stored as author_id FK) |
| NEWS-02 | Edit article | All fields editable, image replacement supported | If no prior featured image exists, new upload mandatory |
| NEWS-03 | Delete article | Soft delete article, cascade delete article_images (DB level) | Physical image files removed from storage |
| NEWS-04 | View article detail | Public page with full content rendering | Increment `views_count` atomically |
| NEWS-05 | Landing page | 8 latest published articles, 6 latest active jobs | Ordered by `published_at DESC` |
| NEWS-06 | Search articles | Meilisearch full-text with filters (category, date, organization) | Manager-level: scoped to own organization |
| NEWS-07 | Article status transitions | draft -> published -> archived; archived -> published (republish) | `canTransitionTo()` enforced by `ArticleStatus` enum |
| CAT-01 | CRUD categories | Name max 30, description max 250 | 7 default categories seeded |
| CAT-02 | Delete category protection | Cannot delete category with articles associated | Verified via count check before delete (HTTP 422) |

#### HTML Sanitization Whitelist (Content Domain)

- **Allowed tags:** `p, strong, em, u, a, ul, ol, li, span, div, br`
- **Allowed attributes:** `class, href, target`
- **Stripped:** All `on*` event handlers, `style` attributes, `script` tags

**Note:** With JSONB/TipTap content format, HTML sanitization applies at render time (DOMPurify on frontend) and at the server sanitization layer before storage.

### 3.4 Jobs Domain

| ID | User Story | Acceptance Criteria | Business Rules |
|----|------------|---------------------|----------------|
| JOB-01 | Create job posting | Title max 100, salary as integer cents (BIGINT), status default `active` | Schedule max 100, contact_info max 100 |
| JOB-02 | Toggle job status | draft -> active -> paused -> closed | Editor scoped to own organization only |
| JOB-03 | View active jobs | Public listing, filtered by `status = 'active'` | Ordered by `created_at DESC` |

**Salary Transformation Rule** (from legacy VARCHAR):
- Legacy: `"15,000 - 20,000"` -> `salary_min_cents = 1_500_000`, `salary_max_cents = 2_000_000`
- `salary_display` stores the human-readable format for frontend rendering

### 3.5 Engagement Domain

| ID | User Story | Acceptance Criteria | Business Rules |
|----|------------|---------------------|----------------|
| CONTACT-01 | Submit contact form | name/last_name <= 60, email valid + <= 60, phone exactly 10 digits, message <= 1000 | Rate limited: 3 submissions per 15 minutes |
| CONTACT-02 | View contact messages | Manager/admin sees messages for own organization | Scoped by `organization_id = user.organization_id` |

### 3.6 Membership Domain

| ID | User Story | Acceptance Criteria | Business Rules |
|----|------------|---------------------|----------------|
| MEMBER-01 | Register as member | Public form with PII fields | CURP + RFC encrypted at rest via Eloquent encrypted casting |
| MEMBER-02 | View members | Admin/manager only, scoped to organization | PII decrypted only for authorized viewers |
| MEMBER-03 | Approve member requests | Manager reviews pending registrations | Status transitions: pending -> approved / rejected |

**PII Validation:**
- **CURP:** `/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/` (18 characters)
- **RFC:** `/^[A-ZN&]{3,4}\d{6}[A-Z0-9]{3}$/` (13 characters)
- **Municipality:** FK to `municipalities` table (NOT free text like legacy)

### 3.7 Analytics Domain

| ID | User Story | Acceptance Criteria | Business Rules |
|----|------------|---------------------|----------------|
| ANALYTICS-01 | Track page views | Record article_id, organization_id, ip_hash, viewed_at | 1 view per IP per article per 24 hours (deduplicated) |
| ANALYTICS-02 | Dashboard stats | Total views, top 5 articles by views, views over time chart | Accessible to super_admin and administrator |
| ANALYTICS-03 | Segmented analytics | Filter by organization, branch, category, time period (day/week/month) | Manager sees only own organization stats |

**Minimum Viable Metrics:**
- Articles: total views, top 5 by views, views over time
- Jobs: active vs closed ratio
- Members: registration trend
- Contact: messages received per period

---

## 4. Non-Functional Requirements

### 4.1 Performance

| Metric | Target |
|--------|--------|
| TTFB (cached pages) | < 200ms |
| P95 response time | < 500ms |
| Meilisearch query time | < 50ms |
| Image upload processing | < 3s |
| Landing page LCP | < 2.5s |
| Landing page CLS | < 0.1 |

### 4.2 Security

- OWASP Top 10 compliance (zero critical/high findings)
- CSRF on all forms (Laravel built-in)
- Rate limiting: 60 req/min authenticated, 20 req/min unauthenticated
- Login brute-force: max 5 attempts per 15 minutes
- Demo login: max 10 per IP per hour
- Contact form: max 3 submissions per 15 minutes
- Security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Strict-Transport-Security`
- HTTPS-only (Traefik handles TLS termination)
- PII encrypted at rest (CURP, RFC)

### 4.3 Accessibility

- WCAG 2.1 Level AA minimum
- Semantic HTML5 elements
- ARIA labels on all interactive elements
- Full keyboard navigation support
- Color contrast ratio >= 4.5:1

### 4.4 Internationalization

- Bilingual UI: Spanish (default) / English
- Language switcher persistent in session (`locale` shared prop)
- User-facing strings via React i18n context/hook
- Server-side validation messages via Laravel `lang/` files (es + en)
- All code, comments, variables, functions, classes: English
- All documentation: English

### 4.5 Browser Support

- Last 2 versions: Chrome, Firefox, Safari, Edge
- Mobile responsive (Tailwind default breakpoints + 3xl at 120rem)
- Dark/light mode via class strategy on `<html>` element

---

## 5. System Architecture

### 5.1 DDD Lite Domain Map

```
app/Domain/
  Identity/           User, UserRole enum, auth actions, policies
  Organization/       Organization, Branch, Representative, Director
  Content/            Article, ArticleImage, Category, ArticleStatus enum
  Jobs/               JobPosting, JobStatus enum
  Engagement/         ContactMessage
  Membership/         Member, MemberStatus enum, PII value objects
  Analytics/          PageView, DailySnapshot, stats actions
  Shared/             Municipality, value objects (Slug, Email, Money, Phone)
```

### 5.2 Architectural Rules (Non-Negotiable)

#### Rule 1: Anemic Controllers
Controllers receive a DTO, call an Action, return a response. **Maximum 15 lines.** No business logic, no validation, no database queries.

**Forbidden inside controllers:**
- Database queries (`DB::`, `Model::where(...)`, raw SQL)
- Mail / notification dispatch
- Direct model manipulation (`$model->save()`, `$model->update()`)
- Manual validation (`$request->validate()`, `Validator::make()`)
- Business logic conditionals (if/else that decides domain behavior)
- File system operations (Storage::, File::)
- Cache reads/writes
- Event dispatching (belongs in Actions)

```php
final class PublishArticleController
{
    public function __invoke(
        Article $article,
        PublishArticleData $data,
        PublishArticleAction $action,
    ): RedirectResponse {
        $published = $action->handle($article, $data);
        return redirect()->route('articles.show', $published)
            ->with('success', __('Article published.'));
    }
}
```

#### Rule 2: Spatie Laravel Data for ALL Validation (MANDATORY)

**`$request->validate()` and traditional `FormRequest` classes are STRICTLY PROHIBITED.**

All input validation, transformation, and type safety is handled exclusively through `Spatie\LaravelData\Data` DTOs:

```php
final class CreateArticleData extends Data
{
    public function __construct(
        #[Required, Max(150)]
        public string $title,

        #[Nullable, Max(200)]
        public ?string $subtitle,

        #[Required]
        public array $content,

        #[Required, Exists('categories', 'id')]
        public string $category_id,

        #[Nullable]
        public ?UploadedFile $featured_image,
    ) {}
}
```

DTOs are injected directly into controllers via Laravel's DI. Validation happens automatically before the controller method executes.

**Spatie v4 Breaking Change:** Use `collect()` not `collection()` for DTO collections. The `collection()` method was removed in v4.

```php
// CORRECT (v4)
$articles = CreateArticleData::collect($items);

// WRONG — throws in v4
$articles = CreateArticleData::collection($items);
```

**`#[TypeScript]` attribute is MANDATORY** on all DTOs exposed to the frontend. Run `php artisan typescript:transform` to regenerate `resources/js/Types/generated.d.ts`.

```php
#[TypeScript]
final class CreateArticleData extends Data { /* ... */ }
```

**Nested DTO composition** — use typed properties for complex structures:

```php
#[TypeScript]
final class CreateOrganizationData extends Data
{
    public function __construct(
        #[Required, Max(100)]
        public string $name,

        #[Required]
        public AddressData $address,

        /** @var Collection<int, CreateBranchData> */
        #[DataCollectionOf(CreateBranchData::class)]
        public Collection $branches,
    ) {}
}
```

**Custom `rules()` for dynamic/conditional validation:**

```php
public static function rules(ValidationContext $context): array
{
    return [
        'featured_image' => [
            Rule::requiredIf(fn () => $context->payload['status'] === 'published'),
        ],
    ];
}
```

**Automatic type casting** — Spatie Data auto-casts these types in constructor properties:

| PHP Type | Auto-Cast From |
|----------|---------------|
| `CarbonImmutable` | Date/datetime strings |
| `BackedEnum` | String/int backing values |
| `Money` (VO) | Integer cents (via custom cast) |
| `UploadedFile` | Multipart file upload |
| Nested `Data` | Array/object payload |

#### Rule 3: Actions as Units of Work

One Action = one business operation. Always transactional (`DB::transaction()`) when touching multiple tables. Type-safe: `DTO in -> Entity out`.

**Naming conventions:**

| Operation | Class Name Pattern | Example |
|-----------|-------------------|---------|
| Create | `Create{Entity}Action` | `CreateArticleAction` |
| Update | `Update{Entity}Action` | `UpdateOrganizationAction` |
| Delete | `Delete{Entity}Action` | `DeleteBranchAction` |
| Domain verb | `{Verb}{Entity}Action` | `PublishArticleAction` |
| Bulk | `{Verb}{Entities}Action` | `PurgeExpiredSessionsAction` |

**Dependency Injection rules:**
- Inject dependencies via **constructor only** (never method injection except for `handle()` parameters)
- **No facades** inside Actions — except `DB::transaction()` and `Event::dispatch()`
- Other Actions can be injected as constructor dependencies (within same domain)
- Cross-domain communication is via **Events only** — never import an Action from another domain

**Cross-domain rule:** Models CAN be referenced across domains (Eloquent relationships require it). Actions CANNOT — they are domain-private. If Domain A needs to trigger behavior in Domain B, Domain A dispatches an Event and Domain B listens.

```php
final class CreateArticleAction
{
    public function __construct(
        private readonly HtmlSanitizerService $sanitizer,
    ) {}

    public function handle(CreateArticleData $data): Article
    {
        return DB::transaction(function () use ($data): Article {
            $article = Article::create([...]);
            ArticleCreated::dispatch($article);
            return $article->fresh(['author', 'category']);
        });
    }
}
```

#### Rule 4: Domain Events for Side Effects

Side effects (cache invalidation, search indexing, notifications) are handled via domain events, never inline in Actions.

### 5.3 Component Diagram

```
┌──────────────────────────────────────────────────────────────────────┐
│                          FRONTEND                                     │
│  React 19 + TypeScript 5.9 + Tailwind v4 (Oxide)                    │
│  Pages/ Components/ Layouts/                                          │
└────────────────────────────┬─────────────────────────────────────────┘
                             │ Inertia.js 2.0
┌────────────────────────────┴─────────────────────────────────────────┐
│                        LARAVEL 12                                     │
│  ┌──────────┐  ┌──────────────┐  ┌──────────────┐                   │
│  │Controllers│→ │   Actions    │→ │   Models     │                   │
│  │ (anemic) │  │(transactional)│  │ (Eloquent)   │                   │
│  └──────────┘  └──────┬───────┘  └──────┬───────┘                   │
│       ↑               │                  │                            │
│    DTOs (Spatie)    Events           Observers                        │
│                       │                  │                            │
│                       ↓                  ↓                            │
│              ┌────────────┐     ┌────────────────┐                   │
│              │  Listeners │     │  Scout (Search) │                   │
│              └────────────┘     └───────┬────────┘                   │
└─────────────────────────────────────────┼────────────────────────────┘
                                          │
         ┌────────────┬───────────────────┼──────────────┐
         │            │                   │              │
         ▼            ▼                   ▼              ▼
  ┌────────────┐ ┌─────────┐    ┌─────────────┐  ┌───────────┐
  │ PostgreSQL │ │ Valkey   │    │ Meilisearch │  │  Storage  │
  │     18     │ │  8.0     │    │   latest    │  │ (S3/local)│
  │            │ │cache/queue│   │ full-text   │  │  images   │
  └────────────┘ │ sessions │    └─────────────┘  └───────────┘
                 └─────────┘
```

### 5.4 Directory Structure

```
app/
├── Domain/
│   ├── Identity/
│   │   ├── Models/User.php
│   │   ├── Enums/UserRole.php
│   │   ├── Data/LoginData.php, CreateUserData.php
│   │   ├── Actions/AuthenticateUserAction.php, CreateUserAction.php
│   │   ├── Policies/UserPolicy.php
│   │   ├── Exceptions/InvalidCredentialsException.php
│   │   └── Events/UserLoggedIn.php
│   │
│   ├── Organization/
│   │   ├── Models/Organization.php, Branch.php, Representative.php, Director.php
│   │   ├── Data/CreateOrganizationData.php, CreateBranchData.php
│   │   ├── Actions/CreateOrganizationAction.php, DeleteBranchAction.php
│   │   ├── Enums/RepresentativeShift.php
│   │   ├── Rules/UniqueDirectorPerOrganizationRule.php
│   │   └── Exceptions/CannotDeleteBranchException.php
│   │
│   ├── Content/
│   │   ├── Models/Article.php, ArticleImage.php, Category.php
│   │   ├── Data/CreateArticleData.php, PublishArticleData.php, SearchArticlesData.php
│   │   ├── Actions/CreateArticleAction.php, PublishArticleAction.php, SearchArticlesAction.php
│   │   ├── Enums/ArticleStatus.php
│   │   ├── Events/ArticlePublished.php
│   │   ├── Exceptions/CannotPublishArticleException.php
│   │   └── Services/HtmlSanitizerService.php
│   │
│   ├── Jobs/
│   │   ├── Models/JobPosting.php
│   │   ├── Data/CreateJobPostingData.php
│   │   ├── Actions/CreateJobPostingAction.php
│   │   └── Enums/JobStatus.php
│   │
│   ├── Engagement/
│   │   ├── Models/ContactMessage.php
│   │   ├── Data/SubmitContactData.php
│   │   └── Actions/SubmitContactAction.php
│   │
│   ├── Membership/
│   │   ├── Models/Member.php
│   │   ├── Data/RegisterMemberData.php
│   │   ├── Actions/RegisterMemberAction.php, ApproveMemberAction.php
│   │   ├── Enums/MemberStatus.php
│   │   └── ValueObjects/Curp.php, Rfc.php
│   │
│   ├── Analytics/
│   │   ├── Models/PageView.php, DailySnapshot.php
│   │   ├── Data/OrganizationStatsData.php
│   │   ├── Actions/TrackPageViewAction.php, GetOrganizationStatsAction.php
│   │   └── Services/SegmentedStatsService.php
│   │
│   └── Shared/
│       ├── Models/Municipality.php
│       ├── ValueObjects/Slug.php, Email.php, Money.php, Phone.php
│       └── Traits/HasUlid.php
│
├── Infrastructure/
│   ├── Search/MeilisearchService.php
│   ├── Storage/ImageStorageService.php
│   └── Notifications/
│
├── Http/
│   ├── Controllers/
│   │   ├── LandingController.php
│   │   ├── Auth/LoginController.php, DemoLoginController.php
│   │   ├── Admin/ArticleController.php, CategoryController.php
│   │   ├── Admin/OrganizationController.php, BranchController.php
│   │   ├── Admin/RepresentativeController.php, DirectorController.php
│   │   ├── Admin/JobController.php, ContactController.php
│   │   ├── Admin/MemberController.php, UserController.php
│   │   ├── Admin/AnalyticsController.php
│   │   ├── Public/ArticleController.php, JobController.php
│   │   ├── Public/ContactController.php, MemberController.php
│   │   └── SearchController.php
│   │
│   └── Middleware/
│       ├── EnsureRoleMiddleware.php
│       ├── EnsureOrganizationScopeMiddleware.php
│       ├── DemoSessionMiddleware.php
│       ├── SecurityHeadersMiddleware.php
│       └── TrackPageViewMiddleware.php
│
resources/
├── js/
│   ├── app.tsx
│   ├── bootstrap.ts
│   ├── Components/
│   ├── Pages/
│   ├── Layouts/
│   ├── Types/
│   ├── Utils/
│   ├── Contexts/ (ThemeContext, LocaleContext)
│   ├── Hooks/
│   └── tests/
│       └── setup.ts
├── css/
│   └── app.css (Tailwind v4 theme)
└── locales/
    ├── es.json
    └── en.json
```

### 5.5 Value Object & Type Safety Standards

#### Value Object Rules

All value objects are `final readonly class` with constructor validation. If the object is constructed, it is valid — no separate validation step needed.

```php
declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use InvalidArgumentException;

final readonly class Email
{
    public function __construct(
        public string $value,
    ) {
        $normalized = mb_strtolower(trim($this->value));
        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email: {$this->value}");
        }
        // Reassignment via reflection not possible on readonly — validate and store normalized
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

**Immutability:** Value objects never expose setters. To "change" a value, create a new instance. Equality is by value, not by identity.

#### Money Value Object (Integer Cents — NEVER Float)

```php
final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency = 'MXN',
    ) {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative');
        }
    }

    /** Factory: Money::fromPesos(150.00) → 15000 cents */
    public static function fromPesos(float $amount): self
    {
        return new self((int) round($amount * 100));
    }

    public function toPesos(): float
    {
        return $this->cents / 100;
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents, $this->currency);
    }

    public function format(): string
    {
        return '$' . number_format($this->toPesos(), 2);
    }
}
```

**Rule:** All monetary values in the database are stored as `BIGINT` (integer cents). The `Money` VO wraps cents and provides safe arithmetic. **Never use `float` for money.**

#### Phone Value Object

```php
final readonly class Phone
{
    public function __construct(
        public string $value,
    ) {
        if (! preg_match('/^\d{10}$/', $this->value)) {
            throw new InvalidArgumentException("Phone must be exactly 10 digits: {$this->value}");
        }
    }
}
```

#### Slug Value Object

```php
final readonly class Slug
{
    public function __construct(
        public string $value,
    ) {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->value)) {
            throw new InvalidArgumentException("Invalid slug format: {$this->value}");
        }
    }
}
```

#### Type Safety Rules (PHP)

| Rule | Enforcement |
|------|-------------|
| `declare(strict_types=1)` on every PHP file | Pest arch test |
| Explicit return types on **every** method | Larastan level 9 |
| `readonly` properties wherever possible | Code review + convention |
| Constructor Property Promotion always | Code review + convention |
| No `mixed` type — ever | Larastan level 9 rejects `mixed` |
| Collection generics: `Collection<int, Article>` | PHPDoc + Larastan |
| `final` classes by default in domain code | Pest arch test |
| ULIDs (UUIDv7) for all primary keys | Migration convention |

### 5.6 Service vs Action vs Job

| Concept | Purpose | State Mutation? | Execution | Example |
|---------|---------|-----------------|-----------|---------|
| **Service** | Stateless logic, calculations, external API wrappers | No (read-only / pure) | Synchronous | `HtmlSanitizerService`, `SegmentedStatsService` |
| **Action** | Single business operation that changes state | Yes (create/update/delete) | Synchronous | `CreateArticleAction`, `PublishArticleAction` |
| **Job** | Deferred/async background work | May mutate | Asynchronous (Valkey queue) | `ProcessImageJob`, `GenerateDailySnapshotJob` |

**When to use which:**
- If it **reads without writing** → Service
- If it **writes as a direct user action** → Action (wrapped in `DB::transaction()`)
- If it **can be deferred** (email, image resize, report generation) → Job
- If in doubt between Action and Job → start as Action; extract to Job only when latency becomes a concern

**Infrastructure layer** (`app/Infrastructure/`): External service integrations (Meilisearch, S3, SMTP) live here, not inside domain code. Domain Actions depend on Infrastructure services via constructor injection.

---

## 6. Data Model

### 6.1 Foreign Key Philosophy: Restrict + SoftDeletes

**Based on "La Biblia de Laravel" Junior Trap #6: Never cascade on historical content.**

If a branch closes, we do NOT want to lose years of published articles and analytics data. The rule:

| Scenario | FK Strategy | Reason |
|----------|-------------|--------|
| Historical data (articles, jobs, contacts, analytics) | `onDelete('restrict')` | Protect audit trail and content |
| True child entities (article_images → article) | `onDelete('cascade')` | Meaningless without parent |
| Optional references (member → organization) | `onDelete('set null')` | Nullable FK |
| Catalog references (organization → municipality) | `onDelete('restrict')` | Protect referential integrity |

All parent entities (`organizations`, `branches`, `directors`, `representatives`, `articles`, `job_postings`, `members`) use `SoftDeletes`. Deletion of a branch with articles must be handled at the **application layer** (inside `DeleteBranchAction`) which soft-deletes the branch and its associated articles within a transaction.

### 6.2 Entity-Relationship Diagram

```
                            ┌──────────────────┐
                            │  municipalities   │
                            │──────────────────│
                            │ id (ULID PK)     │
                            │ name             │
                            │ state            │
                            └────────┬─────────┘
                                     │ 1
                                     │
                                     │ N (restrict)
                            ┌────────┴─────────┐
                            │  organizations   │
                            │──────────────────│
                    ┌───────│ id (ULID PK)     │───────┐
                    │       │ municipality_id   │       │
                    │       │ director_id (UQ)  │───┐   │
                    │       │ name, slug        │   │   │
                    │       │ logo_path         │   │   │
                    │       └──────────────────┘   │   │
                    │ 1                            │   │ 1
                    │                         0..1 │   │
                    │ N (restrict)                  │   │ N (restrict)
            ┌───────┴───────┐              ┌───────┴───┴──────┐
            │   branches    │              │    directors     │
            │───────────────│              │──────────────────│
        ┌───│ id (ULID PK)  │              │ id (ULID PK)     │
        │   │ organization_id│              │ organization_id  │
        │   │ name, location│              │ first/last_name  │
        │   └───────────────┘              │ photo_path       │
        │ 1                                └──────────────────┘
        │
        │ N (restrict)
┌───────┴────────────┐
│  representatives   │
│────────────────────│
│ id (ULID PK)       │
│ organization_id    │
│ branch_id          │
│ first/last_name    │
│ shift, coordinator │
│ photo_path         │
└────────────────────┘

                            ┌──────────────────┐
                            │     users        │
                            │──────────────────│
                            │ id (ULID PK)     │
                            │ organization_id  │
                            │ username, email  │
                            │ password, role   │
                            └────────┬─────────┘
                                     │
                                     │ author_id / created_by
         ┌───────────────────────────┼───────────────────────┐
         │                           │                       │
         │ N                         │ N                     │ N
┌────────┴────────┐         ┌────────┴──────────┐   ┌───────┴─────────┐
│    articles     │         │   job_postings    │   │contact_messages │
│─────────────────│         │───────────────────│   │─────────────────│
│ id (ULID PK)    │──┐      │ id (ULID PK)      │   │ id (ULID PK)    │
│ organization_id │  │      │ organization_id   │   │ organization_id │
│ branch_id       │  │      │ branch_id         │   │ branch_id       │
│ category_id     │  │      │ title, description│   │ name, email     │
│ author_id       │  │      │ salary_*_cents    │   │ phone, message  │
│ title, slug     │  │      │ status            │   └─────────────────┘
│ content (JSONB) │  │      └───────────────────┘
│ status          │  │
│ views_count     │  │
│ published_at    │  │
└─────────────────┘  │ 1
                     │
                     │ N (cascade)
            ┌────────┴─────────┐
            │  article_images  │
            │──────────────────│
            │ id (ULID PK)     │
            │ article_id       │
            │ path, sort_order │
            └──────────────────┘

┌──────────────────┐      ┌──────────────────┐
│     members      │      │   page_views     │
│──────────────────│      │──────────────────│
│ id (ULID PK)     │      │ id (ULID PK)     │
│ organization_id  │      │ article_id       │
│ curp (encrypted) │      │ organization_id  │
│ rfc (encrypted)  │      │ ip_hash          │
│ municipality_id  │      │ viewed_at        │
│ status           │      └──────────────────┘
└──────────────────┘
```

### 6.3 Table Specifications

#### 6.3.1 `municipalities`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | ULIDv7 |
| `name` | `VARCHAR(100)` | NOT NULL | |
| `state` | `VARCHAR(100)` | NOT NULL | |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |

**Indexes:** `name` (for lookups)

#### 6.3.2 `organizations`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `municipality_id` | `CHAR(26)` | FK → municipalities(id) `RESTRICT` | |
| `director_id` | `CHAR(26)` | FK → directors(id) `SET NULL`, NULLABLE, UNIQUE | 1:1 relationship |
| `name` | `VARCHAR(100)` | NOT NULL | |
| `slug` | `VARCHAR(120)` | NOT NULL, UNIQUE | Auto-generated from name |
| `logo_path` | `VARCHAR(255)` | NULLABLE | |
| `registered_at` | `DATE` | NOT NULL | |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |
| `deleted_at` | `TIMESTAMP` | NULLABLE | SoftDeletes |

**Indexes:** `municipality_id`, `registered_at`, `slug` (unique)

#### 6.3.3 `branches`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) `RESTRICT` | |
| `name` | `VARCHAR(100)` | NOT NULL | |
| `location` | `VARCHAR(100)` | NOT NULL | |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |
| `deleted_at` | `TIMESTAMP` | NULLABLE | SoftDeletes |

**Indexes:** `organization_id`

#### 6.3.4 `directors`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) `RESTRICT`, UNIQUE | 1:1 enforced at DB |
| `first_name` | `VARCHAR(100)` | NOT NULL | |
| `last_name` | `VARCHAR(100)` | NOT NULL | |
| `photo_path` | `VARCHAR(255)` | NULLABLE | |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |
| `deleted_at` | `TIMESTAMP` | NULLABLE | SoftDeletes |

**Indexes:** `organization_id` (unique)

#### 6.3.5 `representatives`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) `RESTRICT` | |
| `branch_id` | `CHAR(26)` | FK → branches(id) `RESTRICT` | |
| `first_name` | `VARCHAR(100)` | NOT NULL | |
| `last_name` | `VARCHAR(100)` | NOT NULL | |
| `shift` | `representative_shift` ENUM | NOT NULL | morning, evening, night |
| `is_coordinator` | `BOOLEAN` | DEFAULT false | |
| `photo_path` | `VARCHAR(255)` | NULLABLE | |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |
| `deleted_at` | `TIMESTAMP` | NULLABLE | SoftDeletes |

**Indexes:** `organization_id`, `branch_id`, `[organization_id, branch_id]`

#### 6.3.6 `users`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) `RESTRICT` | |
| `username` | `VARCHAR(100)` | NOT NULL, UNIQUE | Login credential |
| `email` | `VARCHAR(255)` | UNIQUE, NULLABLE | Optional |
| `password` | `VARCHAR(255)` | NOT NULL | bcrypt |
| `role` | `user_role` ENUM | NOT NULL, DEFAULT 'editor' | super_admin, administrator, manager, editor |
| `name` | `VARCHAR(100)` | NULLABLE | Display name |
| `avatar_path` | `VARCHAR(255)` | NULLABLE | |
| `email_verified_at` | `TIMESTAMP` | NULLABLE | |
| `remember_token` | `VARCHAR(100)` | NULLABLE | |
| `last_login_at` | `TIMESTAMP` | NULLABLE | |
| `last_login_ip` | `VARCHAR(45)` | NULLABLE | IPv4/IPv6 |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |
| `deleted_at` | `TIMESTAMP` | NULLABLE | SoftDeletes |

**Indexes:** `organization_id`, `role`, `[organization_id, role]`, `username` (unique), `email` (unique)

#### 6.3.7 `categories`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `name` | `VARCHAR(30)` | NOT NULL | |
| `slug` | `VARCHAR(50)` | NOT NULL, UNIQUE | |
| `description` | `VARCHAR(250)` | NULLABLE | |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |

**Indexes:** `slug` (unique)

#### 6.3.8 `articles`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) **`RESTRICT`** | Historical data protected |
| `branch_id` | `CHAR(26)` | FK → branches(id) **`RESTRICT`** | Historical data protected |
| `category_id` | `CHAR(26)` | FK → categories(id) `RESTRICT` | |
| `author_id` | `CHAR(26)` | FK → users(id) `RESTRICT` | |
| `title` | `VARCHAR(150)` | NOT NULL | |
| `slug` | `VARCHAR(180)` | NOT NULL, UNIQUE | SEO-friendly URLs |
| `subtitle` | `VARCHAR(200)` | NULLABLE | |
| `content` | `JSONB` | NOT NULL | TipTap/ProseMirror format |
| `signature` | `VARCHAR(200)` | NULLABLE | |
| `featured_image_path` | `VARCHAR(255)` | NULLABLE | Required on publish, nullable for drafts |
| `status` | `article_status` ENUM | NOT NULL, DEFAULT 'draft' | draft, published, archived |
| `meta_title` | `VARCHAR(200)` | NULLABLE | SEO |
| `meta_description` | `TEXT` | NULLABLE | SEO |
| `views_count` | `BIGINT UNSIGNED` | DEFAULT 0 | |
| `published_at` | `TIMESTAMP` | NULLABLE | Set on publish |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |
| `deleted_at` | `TIMESTAMP` | NULLABLE | SoftDeletes |

**Indexes:** `organization_id`, `branch_id`, `category_id`, `author_id`, `published_at`, `slug` (unique), `[status, published_at]`, `[organization_id, published_at]`

#### 6.3.9 `article_images`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `article_id` | `CHAR(26)` | FK → articles(id) **`CASCADE`** | True child: meaningless without parent |
| `path` | `VARCHAR(300)` | NOT NULL | |
| `sort_order` | `SMALLINT UNSIGNED` | DEFAULT 0 | |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |

**Indexes:** `article_id`

#### 6.3.10 `job_postings`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) **`RESTRICT`** | Historical data protected |
| `branch_id` | `CHAR(26)` | FK → branches(id) **`RESTRICT`** | Historical data protected |
| `created_by` | `CHAR(26)` | FK → users(id) `RESTRICT` | |
| `title` | `VARCHAR(100)` | NOT NULL | |
| `description` | `TEXT` | NOT NULL | |
| `schedule` | `VARCHAR(100)` | NOT NULL | |
| `contact_info` | `VARCHAR(100)` | NOT NULL | |
| `salary_min_cents` | `BIGINT` | NULLABLE | Integer cents |
| `salary_max_cents` | `BIGINT` | NULLABLE | Integer cents |
| `salary_display` | `VARCHAR(50)` | NULLABLE | Human-readable range |
| `status` | `job_status` ENUM | NOT NULL, DEFAULT 'active' | draft, active, paused, closed |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |
| `deleted_at` | `TIMESTAMP` | NULLABLE | SoftDeletes |

**Indexes:** `organization_id`, `branch_id`, `status`, `[organization_id, status, created_at]`

#### 6.3.11 `contact_messages`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) `RESTRICT` | |
| `branch_id` | `CHAR(26)` | FK → branches(id) `RESTRICT` | |
| `first_name` | `VARCHAR(60)` | NOT NULL | |
| `last_name` | `VARCHAR(60)` | NOT NULL | |
| `email` | `VARCHAR(60)` | NOT NULL | |
| `phone` | `VARCHAR(10)` | NOT NULL | Exactly 10 digits |
| `message` | `TEXT` | NOT NULL | Max 1000 chars (app-level) |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |

**Indexes:** `organization_id`, `[organization_id, created_at]`

#### 6.3.12 `members`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) `SET NULL`, NULLABLE | |
| `curp` | `TEXT` | NOT NULL | **Encrypted at rest** (Eloquent encrypted casting) |
| `rfc` | `TEXT` | NOT NULL | **Encrypted at rest** |
| `first_name` | `VARCHAR(100)` | NOT NULL | |
| `last_name_paternal` | `VARCHAR(100)` | NOT NULL | |
| `last_name_maternal` | `VARCHAR(100)` | NOT NULL | |
| `date_of_birth` | `DATE` | NOT NULL | |
| `municipality_id` | `CHAR(26)` | FK → municipalities(id) `RESTRICT` | |
| `address` | `TEXT` | NOT NULL | |
| `postal_code` | `VARCHAR(5)` | NOT NULL | |
| `neighborhood` | `VARCHAR(100)` | NOT NULL | |
| `phone` | `VARCHAR(10)` | NULLABLE | |
| `mobile` | `VARCHAR(10)` | NOT NULL | |
| `is_affiliated` | `BOOLEAN` | DEFAULT false | |
| `status` | `member_status` ENUM | NOT NULL, DEFAULT 'pending' | pending, approved, rejected |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |
| `deleted_at` | `TIMESTAMP` | NULLABLE | SoftDeletes |

**Indexes:** `organization_id`, `municipality_id`, `status`

#### 6.3.13 `daily_snapshots`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) `RESTRICT` | |
| `date` | `DATE` | NOT NULL | |
| `articles_count` | `INTEGER` | DEFAULT 0 | |
| `jobs_count` | `INTEGER` | DEFAULT 0 | |
| `members_count` | `INTEGER` | DEFAULT 0 | |
| `contact_messages_count` | `INTEGER` | DEFAULT 0 | |
| `page_views_count` | `INTEGER` | DEFAULT 0 | |
| `created_at` | `TIMESTAMP` | | |
| `updated_at` | `TIMESTAMP` | | |

**Indexes:** `[organization_id, date]` (unique composite)

#### 6.3.14 `page_views`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `CHAR(26)` ULID | PRIMARY KEY | |
| `article_id` | `CHAR(26)` | FK → articles(id) `SET NULL`, NULLABLE | |
| `organization_id` | `CHAR(26)` | FK → organizations(id) `RESTRICT` | |
| `ip_hash` | `VARCHAR(64)` | NOT NULL | SHA-256 hashed IP |
| `user_agent` | `VARCHAR(255)` | NULLABLE | |
| `viewed_at` | `TIMESTAMP` | NOT NULL | |

**Indexes:** `article_id`, `organization_id`, `viewed_at`, `[article_id, ip_hash, viewed_at]` (dedup)

### 6.4 FK Constraint Summary

| Table | Column | References | onDelete |
|-------|--------|------------|----------|
| organizations | municipality_id | municipalities(id) | **RESTRICT** |
| organizations | director_id | directors(id) | SET NULL |
| branches | organization_id | organizations(id) | **RESTRICT** |
| directors | organization_id | organizations(id) | **RESTRICT** |
| representatives | organization_id | organizations(id) | **RESTRICT** |
| representatives | branch_id | branches(id) | **RESTRICT** |
| users | organization_id | organizations(id) | **RESTRICT** |
| articles | organization_id | organizations(id) | **RESTRICT** |
| articles | branch_id | branches(id) | **RESTRICT** |
| articles | category_id | categories(id) | **RESTRICT** |
| articles | author_id | users(id) | **RESTRICT** |
| article_images | article_id | articles(id) | **CASCADE** |
| job_postings | organization_id | organizations(id) | **RESTRICT** |
| job_postings | branch_id | branches(id) | **RESTRICT** |
| job_postings | created_by | users(id) | **RESTRICT** |
| contact_messages | organization_id | organizations(id) | **RESTRICT** |
| contact_messages | branch_id | branches(id) | **RESTRICT** |
| members | organization_id | organizations(id) | SET NULL |
| members | municipality_id | municipalities(id) | **RESTRICT** |
| daily_snapshots | organization_id | organizations(id) | **RESTRICT** |
| page_views | article_id | articles(id) | SET NULL |
| page_views | organization_id | organizations(id) | **RESTRICT** |

**Only `article_images` uses CASCADE** — it is the only true child entity that is meaningless without its parent.

---

## 7. Route Design

### 7.1 Public Routes (no auth required)

| Method | URI | Controller | Description |
|--------|-----|------------|-------------|
| GET | `/` | `LandingController@index` | Landing page (8 articles, 6 jobs) |
| GET | `/articles/{slug}` | `Public\ArticleController@show` | Article detail (increments views) |
| GET | `/jobs` | `Public\JobController@index` | Active job listings |
| GET | `/jobs/{id}` | `Public\JobController@show` | Job detail |
| GET | `/search` | `SearchController@index` | Meilisearch search page |
| POST | `/contact` | `Public\ContactController@store` | Submit contact form |
| POST | `/membership/register` | `Public\MemberController@store` | Member registration |
| GET | `/login` | `Auth\LoginController@create` | Login page |
| POST | `/login` | `Auth\LoginController@store` | Authenticate |
| POST | `/demo-login` | `Auth\DemoLoginController@store` | Demo session |
| POST | `/logout` | `Auth\LoginController@destroy` | Destroy session |

**Validation rule:** All POST endpoints receive a Spatie LaravelData DTO. No `$request->validate()`.

### 7.2 Admin Routes — Editor+ (editor, manager, administrator, super_admin)

| Method | URI | Controller | Description |
|--------|-----|------------|-------------|
| GET | `/admin/dashboard` | `Admin\DashboardController@index` | Role-aware dashboard |
| GET | `/admin/articles` | `Admin\ArticleController@index` | Article list |
| GET | `/admin/articles/create` | `Admin\ArticleController@create` | Create form |
| POST | `/admin/articles` | `Admin\ArticleController@store` | Store article |
| GET | `/admin/articles/{id}/edit` | `Admin\ArticleController@edit` | Edit form |
| PUT | `/admin/articles/{id}` | `Admin\ArticleController@update` | Update article |
| DELETE | `/admin/articles/{id}` | `Admin\ArticleController@destroy` | Soft delete |
| POST | `/admin/articles/{id}/publish` | `Admin\ArticleController@publish` | Publish article |
| POST | `/admin/articles/{id}/archive` | `Admin\ArticleController@archive` | Archive article |
| GET | `/admin/categories` | `Admin\CategoryController@index` | Category list |
| POST | `/admin/categories` | `Admin\CategoryController@store` | Create category |
| PUT | `/admin/categories/{id}` | `Admin\CategoryController@update` | Update category |
| DELETE | `/admin/categories/{id}` | `Admin\CategoryController@destroy` | Delete (if no articles) |
| GET | `/admin/jobs` | `Admin\JobController@index` | Job list |
| POST | `/admin/jobs` | `Admin\JobController@store` | Create job |
| PUT | `/admin/jobs/{id}` | `Admin\JobController@update` | Update job |
| DELETE | `/admin/jobs/{id}` | `Admin\JobController@destroy` | Soft delete |

### 7.3 Admin Routes — Manager+ (manager, administrator, super_admin)

| Method | URI | Controller | Description |
|--------|-----|------------|-------------|
| Resource | `/admin/branches` | `Admin\BranchController` | Full CRUD |
| Resource | `/admin/representatives` | `Admin\RepresentativeController` | Full CRUD |
| GET | `/admin/contacts` | `Admin\ContactController@index` | View messages |
| GET | `/admin/members` | `Admin\MemberController@index` | View members |
| POST | `/admin/members/{id}/approve` | `Admin\MemberController@approve` | Approve registration |
| POST | `/admin/members/{id}/reject` | `Admin\MemberController@reject` | Reject registration |

### 7.4 Admin Routes — Administrator+ (administrator, super_admin)

| Method | URI | Controller | Description |
|--------|-----|------------|-------------|
| Resource | `/admin/organizations` | `Admin\OrganizationController` | Full CRUD |
| Resource | `/admin/directors` | `Admin\DirectorController` | Full CRUD |
| Resource | `/admin/users` | `Admin\UserController` | Full CRUD |
| Resource | `/admin/municipalities` | `Admin\MunicipalityController` | Full CRUD |

### 7.5 Admin Routes — Super Admin only

| Method | URI | Controller | Description |
|--------|-----|------------|-------------|
| GET | `/admin/analytics` | `Admin\AnalyticsController@index` | Analytics dashboard |
| GET | `/admin/analytics/export` | `Admin\AnalyticsController@export` | Export data |

### 7.6 API Endpoints (Inertia Partial Reloads)

| Method | URI | Response | Description |
|--------|-----|----------|-------------|
| GET | `/api/branches?organization_id=X` | JSON | Branch dropdown by organization |
| GET | `/api/categories` | JSON | Category list for selects |
| GET | `/api/municipalities` | JSON | Municipality list for selects |

---

## 8. Frontend Architecture

### 8.1 Page Component Tree

```
resources/js/Pages/
├── Landing/Index.tsx
├── Auth/Login.tsx
├── Dashboard/Index.tsx
├── Articles/
│   ├── Index.tsx, Create.tsx, Edit.tsx
│   └── Show.tsx (public)
├── Categories/Index.tsx
├── Jobs/
│   ├── Index.tsx, Create.tsx, Edit.tsx
│   └── Show.tsx (public)
├── Organizations/Index.tsx, Create.tsx, Edit.tsx
├── Branches/Index.tsx, Create.tsx, Edit.tsx
├── Representatives/Index.tsx
├── Directors/Index.tsx, Create.tsx, Edit.tsx
├── Contacts/Index.tsx
├── Members/Index.tsx
├── Analytics/Index.tsx
├── Search/Index.tsx
├── Membership/Register.tsx
└── Errors/404.tsx
```

### 8.2 Layout Components

| Layout | Use | Features |
|--------|-----|----------|
| `AuthenticatedLayout.tsx` | All `/admin/*` pages | Sidebar nav, header with user menu, dark mode toggle, language switcher |
| `GuestLayout.tsx` | Login, public article/job detail | Minimal navbar with logo, dark mode toggle |
| `LandingLayout.tsx` | Landing page | Hero section, featured content grid, footer |

### 8.3 Shared Components

| Component | Domain | Description |
|-----------|--------|-------------|
| `ArticleCard.tsx` | Content | Responsive card with image, status badge, category tag, reading time |
| `JobCard.tsx` | Jobs | Card with title, salary range, status badge |
| `ContactForm.tsx` | Engagement | Public contact form with validation |
| `DataTable.tsx` | Shared | Sortable, filterable table with pagination |
| `Pagination.tsx` | Shared | Page navigation for lists |
| `SearchInput.tsx` | Content | Debounced search input connected to Meilisearch |
| `RichTextEditor.tsx` | Content | TipTap editor for JSONB content |
| `ImageUploader.tsx` | Shared | Drag-and-drop with MIME validation preview |
| `DarkModeToggle.tsx` | Shared | Toggle button, persists to localStorage |
| `LanguageSwitcher.tsx` | Shared | ES/EN toggle, persists to session |
| `Badge.tsx` | Shared | Status/category badges with color variants |
| `Button.tsx` | Shared | Primary, secondary, danger, ghost variants |
| `Modal.tsx` | Shared | Confirmation dialogs, form modals |
| `Alert.tsx` | Shared | Flash message display (success, error, warning) |

### 8.4 State Management

| State | Mechanism | Scope |
|-------|-----------|-------|
| Auth user, flash messages | Inertia shared props | Global (every page) |
| Theme (dark/light) | `ThemeContext` + localStorage | Global |
| Locale (es/en) | `LocaleContext` + session | Global |
| Form state | Inertia `useForm()` | Per page |
| Server data | Inertia page props | Per page |

**No Redux/Zustand needed.** Inertia.js handles all server state synchronization.

### 8.5 TypeScript Types

- Auto-generated from Spatie DTOs: `php artisan typescript:transform`
- Manual Inertia page prop types in `resources/js/Types/`
- Ziggy route types for type-safe `route()` calls
- Strict mode enabled: `"strict": true` in `tsconfig.json`

### 8.6 i18n

- Translation files: `resources/js/locales/es.json`, `resources/js/locales/en.json`
- React hook: `useLocale()` from `LocaleContext`
- Usage: `const { t } = useLocale(); <h1>{t('dashboard.title')}</h1>`
- Server-side: `lang/es/`, `lang/en/` for validation messages

---

## 9. Integration Points

### 9.1 Meilisearch

**Docker service:** `meilisearch:latest` on port 7700

**Index configuration** (`config/scout.php`):

```php
'meilisearch' => [
    'host' => env('MEILISEARCH_HOST', 'http://meilisearch:7700'),
    'key' => env('MEILISEARCH_KEY'),
    'index-settings' => [
        'articles' => [
            'filterableAttributes' => [
                'organization_id', 'category_slug', 'status', 'published_at',
            ],
            'sortableAttributes' => [
                'published_at', 'views_count', 'title',
            ],
            'searchableAttributes' => [
                'title', 'subtitle', 'excerpt', 'content_text',
                'category_name', 'author_name',
            ],
            'rankingRules' => [
                'words', 'typo', 'proximity', 'attribute',
                'sort', 'exactness', 'published_at:desc',
            ],
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => ['oneTypo' => 5, 'twoTypos' => 9],
            ],
            'synonyms' => [
                'empleo' => ['trabajo', 'vacante', 'puesto'],
                'empresa' => ['organizacion', 'compania', 'corporativo'],
                'news' => ['article', 'post', 'noticia'],
            ],
        ],
    ],
],
```

**Indexable model:** Only `Article` is searchable. Only published articles are indexed (`shouldBeSearchable()` checks `status === published`).

**CRITICAL:** Always use `->query(fn($q) => $q->with([...]))` to prevent N+1 queries after Meilisearch returns IDs.

### 9.2 Valkey 8.0

| Purpose | Config key | TTL |
|---------|-----------|-----|
| Sessions | `SESSION_DRIVER=valkey` | 120 min |
| Cache: categories | `categories.all` | 1 hour |
| Cache: municipalities | `municipalities.all` | 24 hours |
| Cache: landing page | `landing.articles`, `landing.jobs` | 5 min |
| Queue: search indexing | `scout` queue | — |
| Queue: analytics aggregation | `analytics` queue | — |

### 9.3 File Storage

**Structure:**

```
storage/app/public/
├── articles/{YYYY}/{MM}/{ulid}.{ext}
├── organizations/{org_ulid}/logo.{ext}
├── directors/{director_ulid}/photo.{ext}
├── representatives/{rep_ulid}/photo.{ext}
├── avatars/{user_ulid}.{ext}
└── placeholders/
    ├── avatar.svg
    └── logo.svg
```

**Upload rules:**
- Max file size: 20MB
- Allowed MIME types: `image/jpeg`, `image/png`, `image/webp`, `image/gif`
- Validation via `finfo` (real MIME check, not extension-based)
- Development: local disk. Production: S3-compatible driver.

### 9.4 Docker Services

| Service | Image | Port | Network |
|---------|-------|------|---------|
| `app` | PHP 8.5 + Laravel 12 | 8000 | web, data |
| `postgres` | PostgreSQL 18 | 5432 | data |
| `valkey` | Valkey 8.0 | 6379 | data |
| `meilisearch` | meilisearch:latest | 7700 | data |
| `scheduler` | Same as app | — | data |
| `queue-worker` | Same as app | — | data |

---

## 10. Security Protocol

### 10.1 Authentication Flow

1. User submits credentials via `LoginData` DTO
2. `AuthenticateUserAction` verifies bcrypt hash
3. Session regenerated (`session()->regenerate()`)
4. `last_login_at` and `last_login_ip` updated
5. Redirect to `/admin/dashboard`

**Session config:**
- Driver: Valkey
- Lifetime: 120 minutes
- Cookie: HttpOnly, Secure, SameSite=Lax
- Name: `corporate_session`

### 10.2 RBAC Permission Matrix

| Resource | super_admin | administrator | manager | editor |
|----------|:-----------:|:-------------:|:-------:|:------:|
| Organizations CRUD | All | — | — | — |
| Branches CRUD | All | All | Own org | — |
| Representatives CRUD | All | All | Own org | — |
| Directors CRUD | All | All | — | — |
| Municipalities CRUD | All | All | — | — |
| Users CRUD | All | Own org | — | — |
| Articles CRUD | All | All | Own org | Own org |
| Articles Publish | All | All | Own org | — |
| Categories CRUD | All | All | All | — |
| Jobs CRUD | All | All | Own org | Own org |
| Contacts Read | All | All | Own org | — |
| Members CRUD | All | All | Own org | — |
| Analytics | All | Own org | — | — |

"Own org" = `user.organization_id == resource.organization_id`

### 10.3 Middleware Stack

| Middleware | Purpose | Applied to |
|-----------|---------|------------|
| `EnsureRoleMiddleware` | Verify user has required role level | All admin routes |
| `EnsureOrganizationScopeMiddleware` | Verify user can access this org's data | Manager/editor routes |
| `DemoSessionMiddleware` | Check TTL, restrict destructive ops | All authenticated routes |
| `SecurityHeadersMiddleware` | Add security response headers | All routes |
| `TrackPageViewMiddleware` | Record analytics | Public article detail |

### 10.4 Rate Limiting

| Endpoint | Limit | Window | Key |
|----------|-------|--------|-----|
| `POST /login` | 5 attempts | 15 minutes | IP + username |
| `POST /demo-login` | 10 attempts | 1 hour | IP |
| `POST /contact` | 3 submissions | 15 minutes | IP |
| `POST /membership/register` | 5 submissions | 1 hour | IP |
| Authenticated API | 60 requests | 1 minute | User ID |
| Unauthenticated | 20 requests | 1 minute | IP |

### 10.5 PII Protection

| Data | Storage | Access |
|------|---------|--------|
| Member CURP | Eloquent `encrypted` cast | Decrypted only for admin/manager of same org |
| Member RFC | Eloquent `encrypted` cast | Same as CURP |
| Page view IP | SHA-256 hash (one-way) | Never reversible |
| User password | bcrypt hash | Never stored in plaintext |

---

## 11. Testing Strategy

### 11.1 Backend: Pest PHP

**Coverage targets:**

| Scope | Target |
|-------|--------|
| `app/Domain/*/Actions/` | >= 80% |
| `app/Domain/*/Models/` | >= 70% |
| `app/Domain/*/Enums/` | 100% |
| `app/Http/Controllers/` | >= 70% |
| Overall | >= 70% |

**Test types:**
- **Unit tests:** Actions, Value Objects, Enum transitions
- **Feature tests:** Controller endpoints, auth flows, demo mode
- **Database tests:** Migration correctness, FK constraints, seed integrity

### 11.2 Pest Architecture Tests (DDD Boundary Enforcement)

**File:** `tests/Architecture/DomainBoundaryTest.php`

Architecture tests run in CI and **FAIL the build** if any domain imports from another domain directly:

```php
test('Identity domain only uses Shared')
    ->expect('App\Domain\Identity')
    ->toOnlyUse([
        'App\Domain\Shared',
        'Illuminate',
        'Spatie\LaravelData',
    ]);

test('Content domain only uses Shared')
    ->expect('App\Domain\Content')
    ->toOnlyUse([
        'App\Domain\Shared',
        'Illuminate',
        'Spatie\LaravelData',
        'Laravel\Scout',
    ]);

test('Organization domain only uses Shared')
    ->expect('App\Domain\Organization')
    ->toOnlyUse([
        'App\Domain\Shared',
        'Illuminate',
        'Spatie\LaravelData',
    ]);

test('Jobs domain only uses Shared')
    ->expect('App\Domain\Jobs')
    ->toOnlyUse([
        'App\Domain\Shared',
        'Illuminate',
        'Spatie\LaravelData',
    ]);

test('Engagement domain only uses Shared')
    ->expect('App\Domain\Engagement')
    ->toOnlyUse([
        'App\Domain\Shared',
        'Illuminate',
        'Spatie\LaravelData',
    ]);

test('Membership domain only uses Shared')
    ->expect('App\Domain\Membership')
    ->toOnlyUse([
        'App\Domain\Shared',
        'Illuminate',
        'Spatie\LaravelData',
    ]);

test('Analytics domain only uses Shared')
    ->expect('App\Domain\Analytics')
    ->toOnlyUse([
        'App\Domain\Shared',
        'Illuminate',
        'Spatie\LaravelData',
    ]);

test('controllers are anemic — no Eloquent imports')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Http\Request',
    ]);

test('DTOs are the only validation mechanism')
    ->expect('App\Http')
    ->not->toUse('Illuminate\Foundation\Http\FormRequest');

test('all domain classes are final')
    ->expect('App\Domain')
    ->classes()
    ->toBeFinal();

test('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();
```

**Cross-domain communication is via Events only**, never direct imports.

### 11.3 Frontend: Vitest + React Testing Library

**Coverage targets:**

| Scope | Target |
|-------|--------|
| `resources/js/Components/` | >= 60% |
| `resources/js/Utils/` | >= 80% |

**Test config:** Vitest shares Vite config. Environment: `jsdom`. Setup file cleans up DOM after each test.

### 11.4 Key Test Scenarios

| # | Scenario | Type | Domain |
|---|----------|------|--------|
| 1 | Category deletion blocked when articles exist | Feature | Content |
| 2 | Branch deletion soft-deletes articles when no representatives | Feature | Organization |
| 3 | Branch deletion blocked when representatives exist | Feature | Organization |
| 4 | Super admin uniqueness enforced (only one at a time) | Unit | Identity |
| 5 | ArticleStatus valid transitions (draft->published, published->archived) | Unit | Content |
| 6 | ArticleStatus invalid transitions rejected (draft->archived blocked) | Unit | Content |
| 7 | Demo session expires after 30 minutes | Feature | Identity |
| 8 | Organization scope enforcement (manager cannot access other org data) | Feature | Organization |
| 9 | CURP format validation passes/fails correctly | Unit | Membership |
| 10 | RFC format validation passes/fails correctly | Unit | Membership |
| 11 | HTML content sanitization strips event handlers | Unit | Content |
| 12 | Search returns only published articles with eager loading | Feature | Content |

### 11.5 Action Test Template (Arrange-Act-Assert)

Every Action must have at least one test following this structure:

```php
it('creates an article with valid data', function (): void {
    // Arrange
    $organization = Organization::factory()->create();
    $category = Category::factory()->create();
    $author = User::factory()->for($organization)->create();
    $data = CreateArticleData::from([
        'title' => 'Test Article',
        'content' => ['type' => 'doc', 'content' => []],
        'category_id' => $category->id,
    ]);

    // Act
    $article = app(CreateArticleAction::class)->handle($data);

    // Assert
    expect($article)
        ->toBeInstanceOf(Article::class)
        ->title->toBe('Test Article')
        ->status->toBe(ArticleStatus::Draft);

    $this->assertDatabaseHas('articles', ['title' => 'Test Article']);
});
```

**Test naming convention:** `it('{verbs} with {condition}')` — e.g., `it('blocks deletion when articles exist')`.

### 11.6 CI Pipeline

```bash
# Run in order — any failure stops the pipeline
composer test                       # Pest suite (includes arch tests)
composer analyse                    # Larastan level 9
composer format -- --test           # Pint (check only)
npm run test:run                    # Vitest (single run)
npm run typecheck                   # tsc --noEmit
php artisan typescript:transform    # Verify no TS drift
```

### 11.7 Static Analysis Configuration

#### PHPStan / Larastan (`phpstan.neon`)

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app/
    level: 9
    checkMissingIterableValueType: true
    checkGenericClassInNonGenericObjectType: true
    reportUnmatchedIgnoredErrors: true
```

**Level 9 enforces:** No `mixed` types, explicit return types, typed collections (`Collection<int, Article>`), strict comparison operators.

#### Laravel Pint (`pint.json`)

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "final_class": true,
        "void_return": true
    }
}
```

### 11.8 Dev Dependencies

| Package | Purpose |
|---------|---------|
| `pestphp/pest` | Test framework |
| `pestphp/pest-plugin-arch` | Architecture tests |
| `larastan/larastan` | PHPStan for Laravel (level 9) |
| `laravel/pint` | Code formatter |
| `barryvdh/laravel-ide-helper` | IDE autocompletion for models, facades, meta |
| `spatie/laravel-typescript-transformer` | DTO → TypeScript type generation |

Run `php artisan ide-helper:models --nowrite` after migrations to generate `_ide_helper_models.php`.

---

## 12. Migration Strategy (ETL)

### 12.1 Legacy Connection

**File:** `config/database.php`

```php
'mysql_legacy' => [
    'driver' => 'mysql',
    'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
    'port' => env('LEGACY_DB_PORT', '3306'),
    'database' => env('LEGACY_DB_DATABASE', 'cms_legacy'),
    'username' => env('LEGACY_DB_USERNAME', 'root'),
    'password' => env('LEGACY_DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'strict' => false,
],
```

Read-only Legacy models in `app/Models/Legacy/` with `protected $connection = 'mysql_legacy'`.

### 12.2 Migration Order (dependency-safe)

1. `municipalities`
2. `organizations` (depends on municipalities)
3. `branches` (depends on organizations)
4. `directors` (depends on organizations)
5. `categories`
6. `users` (depends on organizations)
7. `representatives` (depends on organizations, branches)
8. `articles` (depends on organizations, branches, categories, users)
9. `article_images` (depends on articles)
10. `job_postings` (depends on organizations, branches, users)
11. `contact_messages` (depends on organizations, branches)
12. `members` (depends on organizations, municipalities)

### 12.3 Field Transformation Table

| Legacy Column | Transformation | Modern Column |
|---------------|----------------|---------------|
| `Id_*` (INT AUTO_INCREMENT) | Generate ULID, store in idMap | `id` (CHAR 26) |
| `Sindicato_*` (INT FK) | Lookup in idMap['organizations'] | `organization_id` |
| `Planta_*` (INT FK) | Lookup in idMap['branches'] | `branch_id` |
| `Nombre_Sindicato` | Direct copy | `name` |
| `Titulo_Noticia_Sindicato` | Trim + limit 150 | `title` |
| `Contenido_Noticia_Sindicato` | Parse HTML -> TipTap JSONB | `content` |
| `Salario_Trabajo` (VARCHAR) | Parse "15,000 - 20,000" -> cents | `salary_min_cents`, `salary_max_cents` |
| `Estatus_Trabajo` (TINYINT 0/1) | Map: 1->active, 0->closed | `status` |
| `Turno_Delegado` (TINYINT 1/2/3) | Map: 1->morning, 2->evening, 3->night | `shift` |
| `Rol_Usuario` (TINYINT 1/2/3) | Map: 1->administrator, 2->manager, 3->editor | `role` |
| `Curp_Afiliado` (VARCHAR) | Encrypt via Eloquent | `curp` |
| `Rfc_Afiliado` (VARCHAR) | Encrypt via Eloquent | `rfc` |
| `Municipio_Afiliado` (VARCHAR text) | Lookup municipality by name -> FK | `municipality_id` |

### 12.4 Artisan Command

```bash
php artisan migrate:legacy {entity} {--dry-run} {--chunk=100}

# Entities: municipalities, organizations, branches, directors, categories,
#           users, representatives, articles, jobs, contacts, members, all

# Examples:
php artisan migrate:legacy all --dry-run      # Preview
php artisan migrate:legacy articles --chunk=50 # Migrate articles in batches of 50
```

---

## 13. Demo Mode Specification

### 13.1 Architecture

Demo mode allows recruiters and visitors to explore all features without registration.

**Seeded demo users:**

| Username | Role | Organization |
|----------|------|-------------|
| `demo-admin` | administrator | "Empresa Principal" |
| `demo-manager` | manager | "Empresa Principal" |
| `demo-editor` | editor | "Empresa Principal" |

### 13.2 Session Flags

```php
$_SESSION = [
    'is_demo' => true,
    'demo_expires_at' => now()->addMinutes(30)->timestamp,
    'demo_session_id' => Str::uuid(),
];
```

### 13.3 DemoSessionMiddleware

On every request:
1. Check `is_demo && now() > demo_expires_at` -> destroy session, redirect to login
2. If demo session is active, inject `demo_session_id` into all write operations
3. Block destructive operations:
   - Cannot delete organizations or municipalities
   - Cannot change other users' passwords
   - Cannot promote to super_admin
4. **CAN** create/edit/delete articles, jobs, contacts (tagged as ephemeral)

### 13.4 Ephemeral Data

- All records created during demo sessions are tagged with `demo_session_id` column (nullable on relevant tables)
- Scheduled command: `php artisan demo:cleanup` runs every 15 minutes via Laravel Scheduler
- Purges records where `demo_session_id IS NOT NULL AND created_at < now() - 30 minutes`
- Baseline seed data (`demo_session_id = NULL`) is never touched

### 13.5 Rate Limiting

- 10 demo logins per IP per hour
- Implemented via `RateLimiter::for('demo-login', ...)`

### 13.6 Baseline Seed Data

- 10 organizations with fictional corporate names
- 2-3 branches per organization
- 1 director per organization
- 3-5 representatives per branch
- 7 article categories (see Appendix D)
- 20-30 sample articles across organizations
- 10-15 job postings
- Sample contact messages
- 5-10 member registrations

---

## 14. Deployment Architecture

### 14.1 Docker Compose (Production)

Services: `app`, `postgres`, `valkey`, `meilisearch`, `scheduler`, `queue-worker`

All services connected to `global_data_private` Docker network for inter-service communication and `web` network for Traefik exposure.

### 14.2 Traefik Configuration

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.corporate-cms.rule=Host(`cms.carlosgardea.com`)"
  - "traefik.http.routers.corporate-cms.tls=true"
  - "traefik.http.routers.corporate-cms.tls.certresolver=letsencrypt"
  - "traefik.http.services.corporate-cms.loadbalancer.server.port=8000"
```

### 14.3 VPS Deployment

| Attribute | Value |
|-----------|-------|
| VPS IP | 195.35.37.195 |
| VPS user | deployer_vps |
| VPS path | `/opt/omnibus/projects/corporate-cms/` |
| Health check | `GET /up` |

### 14.4 CI/CD (GitHub Actions)

Trigger: push to `main` branch

```
1. checkout
2. composer install --no-dev
3. npm ci && npm run build
4. composer test (Pest + Arch tests)
5. composer analyse (Larastan level 9)
6. npm run test:run (Vitest)
7. Deploy via SSH:
   - git pull
   - composer install --no-dev --optimize-autoloader
   - php artisan migrate --force
   - php artisan config:cache
   - php artisan route:cache
   - php artisan view:cache
   - php artisan optimize
   - php artisan scout:sync-index-settings
   - php artisan queue:restart
```

### 14.5 Environment Variables

See Appendix C for the complete `.env.example`.

---

## 15. Phased Delivery Plan

### Phase 1: Foundation + Auth + Content (MVP)

**Scope:** Laravel scaffold, auth system, article CRUD, landing page, dark/light mode, ES/EN

**Acceptance criteria:**
- [ ] Login and demo login functional with 30-min TTL
- [ ] 4-level RBAC enforced on all routes
- [ ] Articles CRUD with TipTap editor and JSONB storage
- [ ] Categories CRUD with deletion protection
- [ ] Landing page: 8 articles + 6 jobs
- [ ] Dark/light mode toggle persistent
- [ ] Language switcher (ES/EN)
- [ ] Pest arch tests passing in CI

### Phase 2: Organization + Jobs + Engagement

**Scope:** Full entity management, job postings, contact form

**Acceptance criteria:**
- [ ] Organizations, branches, representatives, directors CRUD
- [ ] Cascade rules enforced (application-level soft deletes)
- [ ] Job postings with salary in cents
- [ ] Contact form with rate limiting
- [ ] Organization scope middleware working
- [ ] All FK constraints verified

### Phase 3: Membership + Search + Analytics

**Scope:** Member registration, Meilisearch integration, analytics dashboard

**Acceptance criteria:**
- [ ] Member registration with CURP/RFC encryption
- [ ] Meilisearch full-text search with filters
- [ ] Analytics dashboard with segmented views
- [ ] Page view tracking with IP deduplication

### Phase 4: ETL + Demo Polish + Deploy

**Scope:** Legacy data migration, demo mode refinement, production deployment

**Acceptance criteria:**
- [ ] `migrate:legacy all` runs successfully
- [ ] Demo mode with ephemeral data and 15-min cleanup
- [ ] Deployed to `cms.carlosgardea.com`
- [ ] Lighthouse >= 90 on all categories
- [ ] Portfolio `available: true` after verification

---

## 16. Appendices

### Appendix A: Enum Definitions

#### `UserRole`

```php
enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMINISTRATOR = 'administrator';
    case MANAGER = 'manager';
    case EDITOR = 'editor';

    public function label(): string { /* ... */ }
    public function color(): string { /* Tailwind color class */ }
    public function level(): int
    {
        return match($this) {
            self::SUPER_ADMIN => 4,
            self::ADMINISTRATOR => 3,
            self::MANAGER => 2,
            self::EDITOR => 1,
        };
    }
    public function canManageUsers(): bool { return $this->level() >= 3; }
    public function canPublishArticles(): bool { return $this->level() >= 2; }
    public function canViewAnalytics(): bool { return $this->level() >= 3; }
}
```

#### `ArticleStatus`

```php
enum ArticleStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    public function canTransitionTo(self $new): bool
    {
        return match($this) {
            self::DRAFT => in_array($new, [self::PUBLISHED, self::ARCHIVED]),
            self::PUBLISHED => $new === self::ARCHIVED,
            self::ARCHIVED => $new === self::PUBLISHED,
        };
    }
    public function label(): string { /* ... */ }
    public function color(): string { /* ... */ }
    public function badgeColor(): string { /* ... */ }
}
```

#### `JobStatus`

```php
enum JobStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case CLOSED = 'closed';

    public function canTransitionTo(self $new): bool { /* ... */ }
    public function label(): string { /* ... */ }
    public function color(): string { /* ... */ }
}
```

#### `RepresentativeShift`

```php
enum RepresentativeShift: string
{
    case MORNING = 'morning';
    case EVENING = 'evening';
    case NIGHT = 'night';

    public function label(): string { /* ... */ }
}
```

#### `MemberStatus`

```php
enum MemberStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string { /* ... */ }
    public function color(): string { /* ... */ }
}
```

### Appendix B: Validation Rules

#### `CurpFormat`

```php
final class CurpFormat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', strtoupper($value))) {
            $fail(__('validation.curp_format'));
        }
    }
}
```

#### `RfcFormat`

```php
final class RfcFormat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!preg_match('/^[A-ZN&]{3,4}\d{6}[A-Z0-9]{3}$/', strtoupper($value))) {
            $fail(__('validation.rfc_format'));
        }
    }
}
```

#### `MexicanPhone`

```php
final class MexicanPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!preg_match('/^\d{10}$/', $value)) {
            $fail(__('validation.mexican_phone'));
        }
    }
}
```

### Appendix C: Environment Variables (.env.example)

```env
# Application
APP_NAME="Corporate CMS"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://cms.carlosgardea.com
APP_LOCALE=es
APP_FALLBACK_LOCALE=en

# Database (PostgreSQL 18)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=corporate_cms
DB_USERNAME=deployer
DB_PASSWORD=

# Legacy Database (MySQL — for ETL only)
LEGACY_DB_HOST=mysql-legacy
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=cms_legacy
LEGACY_DB_USERNAME=root
LEGACY_DB_PASSWORD=

# Session
SESSION_DRIVER=valkey
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

# Cache
CACHE_STORE=valkey

# Queue
QUEUE_CONNECTION=valkey

# Valkey
VALKEY_HOST=valkey
VALKEY_PORT=6379
VALKEY_PASSWORD=null

# Meilisearch
SCOUT_DRIVER=meilisearch
SCOUT_QUEUE=true
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=

# Storage
FILESYSTEM_DISK=public

# Branding
BRAND_NAME="Corporate CMS"
BRAND_PRIMARY_COLOR="#DD00FF"
```

### Appendix D: Seed Data Manifest

#### Organizations (10)

| Name | Municipality |
|------|-------------|
| Empresa Principal | Chihuahua |
| Grupo Alamos | Chihuahua |
| Corporativo Norte | Juarez |
| Servicios Integrados del Valle | Chihuahua |
| Red Empresarial Frontera | Juarez |
| Consorcio Sierra Madre | Chihuahua |
| Enlace Comercial Pacifico | Juarez |
| Proyectos Industriales MX | Chihuahua |
| Alianza Corporativa Sur | Juarez |
| Grupo Desarrollo Central | Chihuahua |

#### Municipalities (2)

| ID | Name | State |
|----|------|-------|
| 1 | Chihuahua | Chihuahua |
| 2 | Juarez | Chihuahua |

#### Categories (7)

| Name (ES) | Slug | Description |
|-----------|------|-------------|
| Anuncios Oficiales | official-announcements | Corporate communications and official statements |
| Acuerdos Corporativos | corporate-agreements | Business agreements and corporate decisions |
| Eventos | events | Corporate events and activities |
| Capacitaciones | training | Training programs and workshops |
| Comunicados | press-releases | Press releases and public communications |
| Beneficios | benefits | Employee benefits and programs |
| Actividades | activities | Community and internal activities |

### Appendix E: Glossary (Legacy to Modern)

| Legacy Term (ES) | Legacy Column | Modern Term (EN) | Modern Column |
|-------------------|---------------|-------------------|---------------|
| Sindicato | `sindicato` | Organization | `organizations` |
| Planta | `planta` | Branch | `branches` |
| Delegado | `delegado` | Representative | `representatives` |
| Secretario General | `secretario_general` | Director | `directors` |
| Noticia | `noticia_sindicato` | Article | `articles` |
| Imagen Noticia | `imagen_noticia_sindicato` | Article Image | `article_images` |
| Categoria Noticia | `categoria_noticia` | Category | `categories` |
| Trabajo | `trabajo` | Job Posting | `job_postings` |
| Contacto | `contacto` | Contact Message | `contact_messages` |
| Afiliado | `afiliado` | Member | `members` |
| Usuario | `usuario` | User | `users` |
| Super Usuario | `susuario` | Super Admin | `UserRole::SUPER_ADMIN` |
| Municipio | `municipio` | Municipality | `municipalities` |

---

## SKILL.md Recommendations

When the Laravel project is scaffolded, the following SKILL.md files are recommended:

1. **`SKILL-DDD-ACTIONS.md`** — Reference for the Action pattern: anatomy, naming convention, transaction scope, testing template. Prevents drift from the DDD Lite architecture.

2. **`SKILL-MEILISEARCH.md`** — Meilisearch integration guide: index config, `toSearchableArray()` template, `shouldBeSearchable()` pattern, eager loading requirement, `scout:import` commands.

3. **`SKILL-DEMO-MODE.md`** — Demo mode implementation guide: middleware structure, ephemeral data tagging, cleanup command, rate limiting, baseline seed protection.

4. **`SKILL-PII-HANDLING.md`** — PII encryption and handling: Eloquent encrypted casting setup, CURP/RFC validation rules, IP hashing, scan commands before push.

These files codify institutional knowledge and prevent pattern drift across development sessions.

---

**End of Document**

*This specification is the Single Source of Truth for Corporate CMS (Plataforma Empresarial). All code must conform to this document. Any architectural change must be reflected here before implementation.*
