# omnibus-cms-sindical

Laravel 12 rebuild of the CROC CMS (Proyecto A).
Chapter 3.1–3.3 of the Biblia de Laravel.

## Domains
- Production: cms.carlosgardea.com
- Staging:    cms.carlosgardea.cloud
- VPS path:   /opt/omnibus/projects/cms-sindical/

## Business Domains (DDD Lite)
- Identity      → Users, Roles, Auth
- Organization  → Unions, Plants, Delegates, Secretaries
- Content       → Articles, Categories, Images
- Engagement    → Subscribers, Newsletters, Contacts
- Shared        → Email VO, ULID VO, Slug VO

## Key Packages
- spatie/laravel-data v4           → DTOs
- spatie/laravel-typescript-transformer → TS generation
- laravel/scout + meilisearch-php  → Full-text search
- tiptap (frontend)                → Rich text editor

## Database
PostgreSQL 18 via global_data_private Docker network.
DB name: cms_sindical

## Legacy reference
Schema and business rules: ~/coding/omnibus-legacy/croc-cms/
Blueprints: ANALISIS-LEGACY-MIGRACION-CMS.md + MASTER-BLUEPRINT-CMS.md
Note: blueprints say Laravel 11 + PostgreSQL 16 — ignore, we use Laravel 12 + PostgreSQL 18

## Artisan workflow
```bash
php artisan migrate:fresh --seed   # dev reset
php artisan scout:import           # re-index Meilisearch
php artisan typescript:transform   # regenerate TS types
composer test                      # Pest suite
composer analyse                   # Larastan level 9
```

## Deploy
```bash
# Staging
ssh deployer_vps@195.35.37.195 "cd /opt/omnibus/projects/cms-sindical && git pull && php artisan migrate --force && php artisan optimize"
```

## Demo Mode Philosophy (applies to ALL OMNIBUS projects)
Every deployed project must be explorable without friction by recruiters.

Standard behavior across all projects:
- Guest/visitor mode: full feature access without registration
- Ephemeral data: session data expires after 30 min of inactivity or on session end
- Session isolation: each visitor operates in their own data sandbox
- Fixed demo dataset: seeded baseline that is never modified (read-only reference data)
- Periodic reset: a scheduled command restores demo data every 30 minutes

This is architecture intent — implement it when building each project.
The whitelabel (legacy) systems implement a simpler version via cron + seed reset.
The Laravel rebuilds implement it properly via:
  - DemoSessionMiddleware → creates isolated tenant/session context
  - Scheduled command: php artisan demo:reset (runs every 30 min)
  - Demo data never mixes with other sessions

Session isolation priority: HIGH for Laravel rebuilds, NICE-TO-HAVE for whitelabels.
