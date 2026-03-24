# omnibus-cms

Laravel 12 rebuild of the Corporate CMS — Plataforma Empresarial (Proyecto A).
Chapter 3.1-3.3 of the Biblia de Laravel.

## Single Source of Truth
**SPEC.md** is the authoritative specification. All code must conform to it.
Read SPEC.md before making any architectural decision.

## Domains
- Production: cms.carlosgardea.com
- Staging:    cms.carlosgardea.cloud
- VPS path:   /opt/omnibus/projects/corporate-cms/

## Business Domains (DDD Lite)
- Identity      -> Users, Roles (4-level RBAC), Auth
- Organization  -> Organizations, Branches, Representatives, Directors
- Content       -> Articles (JSONB/TipTap), Categories, ArticleImages
- Jobs          -> JobPostings (salary in integer cents)
- Engagement    -> ContactMessages
- Membership    -> Members (PII encrypted: CURP, RFC)
- Analytics     -> PageViews, DailySnapshots
- Shared        -> Municipality, Value Objects (Slug, Email, Money, Phone)

## Architectural Rules (Non-Negotiable)
1. **Anemic Controllers** — max 15 lines. Receive DTO, call Action, return response.
2. **Spatie Laravel Data ONLY** — `$request->validate()` and FormRequests are PROHIBITED.
3. **Actions** — one class = one business operation. Always transactional with `DB::transaction()`.
4. **FK restrict + SoftDeletes** — never cascade on historical content. Only `article_images` uses cascade.
5. **Pest Arch Tests** — DDD boundaries enforced at CI level. Cross-domain imports fail the build.
6. **`declare(strict_types=1)`** — every PHP file, no exceptions.
7. **`final` classes** — by default in all domain code.
8. **Enums** — backed enums always, never magic strings.
9. **`readonly` properties** — wherever possible on all class properties.
10. **Constructor Property Promotion** — mandatory on all classes. No separate property declarations.
11. **Explicit return types** — on every method, no exceptions.
12. **No `mixed` type** — Larastan level 9 enforces. Use union types or generics instead.

## Key Packages
- spatie/laravel-data v4           -> DTOs + validation + TypeScript generation
- spatie/laravel-typescript-transformer -> TS type generation
- laravel/scout + meilisearch-php  -> Full-text search (articles only)
- tiptap (frontend)                -> Rich text editor (JSONB content)
- mews/purifier                    -> Server-side HTML sanitization
- barryvdh/laravel-ide-helper (dev) -> IDE autocompletion for models/facades

## Database
PostgreSQL 18 via global_data_private Docker network.
DB name: corporate_cms

## Legacy Reference
Schema and business rules: ~/coding/omnibus-legacy/cms/
Key docs: RESUMEN_PROYECTO.md + MASTER-BLUEPRINT-CMS.md
Note: blueprints say Laravel 11 + PostgreSQL 16 — we use Laravel 12 + PostgreSQL 18.
SPEC.md supersedes MASTER-BLUEPRINT when conflicts exist.

## Artisan Workflow
```bash
php artisan migrate:fresh --seed   # dev reset
php artisan scout:import "App\Domain\Content\Models\Article"
php artisan scout:sync-index-settings
php artisan typescript:transform   # regenerate TS types
composer test                      # Pest suite (includes arch tests)
composer analyse                   # Larastan level 9
composer format                    # Laravel Pint
```

## Deploy
```bash
ssh vps "cd /opt/omnibus/projects/corporate-cms && git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan optimize"
```

## Demo Mode Philosophy (applies to ALL OMNIBUS projects)
Every deployed project must be explorable without friction by recruiters.

Standard behavior across all projects:
- Guest/visitor mode: full feature access without registration
- Ephemeral data: session data expires after 30 min of inactivity or on session end
- Session isolation: each visitor operates in their own data sandbox
- Fixed demo dataset: seeded baseline that is never modified (read-only reference data)
- Periodic reset: a scheduled command restores demo data every 15 minutes

Implementation for this project:
- DemoSessionMiddleware -> creates isolated session context with TTL
- Scheduled command: php artisan demo:cleanup (runs every 15 min)
- Demo data tagged with demo_session_id, never touches baseline seed
- Rate limit: 10 demo logins per IP per hour
