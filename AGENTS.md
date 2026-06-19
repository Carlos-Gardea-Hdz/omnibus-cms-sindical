# AGENTS.md — Corporate CMS (omnibus-cms)

> **Canonical agent rulebook for this repo.** Follow the agents.md standard.
> AI agents (and humans) read this first. It is intentionally short and points
> at the two authoritative sources instead of duplicating them — duplication is
> how drift starts.

## Authoritative sources (precedence order)

1. **`SPEC.md`** — the Single Source of Truth for *what this product is* (domains,
   data model, routes, security, demo mode, phased plan). Code conforms to SPEC,
   never the other way around. Read it before any architectural decision.
2. **`~/.claude/CLAUDE.md` + `~/dotfiles/claude/.claude/AGENTS.md`** (the personal
   OMNIBUS vault) — the *engineering law* (DDD-Lite, strict types, Spatie Data,
   Pest, PostgreSQL-only, pnpm-only, etc.). Global, applies to every OMNIBUS repo.
3. **This file** — repo-specific glue and the current honest state of the code.

If SPEC and the vault ever conflict on a Laravel rule, the vault wins on *how to
build*; SPEC wins on *what to build*. When in doubt, ASK — never invent schema.

## What this repo actually is, right now (2026-06-19)

A **Laravel 12 scaffold + one landing page**. The 8-domain CMS described in
`SPEC.md` is **specified, not yet built**. Do not assume any domain code, model,
migration, DTO, Action, or demo-mode machinery exists — it does not. See
`NEXT_STEPS.md` for the verified gap-to-done and merge-readiness checklist.

Concretely present:
- `app/Http/Controllers/LandingController.php` (returns empty `latestArticles` / `latestJobs`)
- `routes/web.php` (one route: `home`)
- `resources/js/Pages/Landing/Index.tsx` (bilingual ES/EN, magenta `#DD00FF`)
- Default Laravel migrations only (`users`, `cache`, `jobs`)
- 3 Pest tests (2 scaffold + 1 trivial landing render)

Not present yet: `app/Domain/`, the 14 SPEC tables, auth/RBAC, Spatie Data DTOs,
Meilisearch/Scout, TipTap, demo middleware, arch tests, PHPStan config.

## Repo facts

| Attribute | Value |
|-----------|-------|
| Commercial name | **Corporate CMS — Plataforma Empresarial** (Proyecto A) |
| Chapter | Biblia de Laravel 3.1–3.3 |
| GitHub repo slug | `omnibus-cms-sindical` (legacy slug; product is whitelabeled "Corporate CMS") |
| Production | `cms.carlosgardea.com` (not live yet) |
| Staging | `cms.carlosgardea.cloud` (landing-only shell, see below) |
| VPS path | `/opt/omnibus/projects/corporate-cms/` |
| Brand accent | magenta `#DD00FF` |

## Stack (target — per vault + SPEC)

PHP 8.5 · Laravel 12 · PostgreSQL 18 · Valkey/Redis · Meilisearch · React 19 +
TypeScript + Inertia 2 · Tailwind v4 · Pest · Docker + Traefik v3.

> **Known drift (must be reconciled before/while building Phase 1 — tracked in
> `NEXT_STEPS.md`):** `composer.json` currently pins `php: ^8.2` and ships **none**
> of the SPEC-mandated packages (`spatie/laravel-data`, `spatie/laravel-typescript-transformer`,
> `laravel/scout` + `meilisearch-php`, `mews/purifier`, larastan). There is no
> `phpstan.neon` and no `composer analyse|format|test` scripts. The composer
> `setup`/`dev` scripts still call `npm`/`npx` (violates the pnpm-only law). Do not
> treat the SPEC "Key Packages" list as installed — install them as you reach the
> phase that needs them, and bump `php` to `^8.5`.

## Engineering law (summary — full text in the vault)

- `declare(strict_types=1);` in every PHP file. `final` classes, `readonly`
  properties, constructor property promotion, explicit return types, no `mixed`.
- **DDD-Lite + Action Pattern**: code lives in `app/Domain/{Domain}/`; controllers
  are anemic (≤15 lines: DTO → Action → Response); one Action = one operation,
  `DB::transaction()` when it touches multiple tables.
- **Spatie Laravel Data ONLY** for validation/DTOs on the Inertia path — Form
  Requests and `$request->validate()` are prohibited here. (Package not yet
  installed; install before writing the first DTO.)
- Backed Enums with `label()`/`color()`/`level()`; money in integer cents; UUIDv7.
- **Pest arch tests** enforce DDD boundaries — they are part of the definition of
  done for Phase 1, not an afterthought.
- Dark/light mode + ES/EN bilingual UI are mandatory on every screen.
- **PII law (critical):** legacy CMS data may contain real CURP/RFC/personal data.
  Seeds and demo data are **fictional only**. Never commit `omnibus-legacy/` data.
- **pnpm, never npm.** `php`/composer config via `config()`, secrets in `.env` only.

## Quality gates (vault standard)

```bash
composer test        # Pest (must include arch tests once Domain exists)
composer analyse     # PHPStan + Larastan level 9 (SPEC) / level 10 (vault)  ← not configured yet
composer format      # Laravel Pint
pnpm exec tsc --noEmit
pnpm build
php artisan typescript:transform   # after Spatie Data + transformer are installed
```

Run them before any commit. Current honest status of these gates is recorded in
`NEXT_STEPS.md` (do not assume green — Pint and tsc currently fail; PHPStan is
unconfigured).

## Staging caveat

The `cms.carlosgardea.cloud` staging image is a **stopgap landing shell**: it runs
on **SQLite + file sessions + file cache + sync queue** via `php artisan serve`
(see `Dockerfile`), which is architecturally divergent from the SPEC target
(PostgreSQL 18 + Valkey + Meilisearch). "The CMS is on staging" overstates it —
only the landing page is. Replacing this with the real stack is Phase 4 work.

## Legacy reference

Schema / business rules: `~/coding/omnibus-legacy/cms/`
(`RESUMEN_PROYECTO.md`, `MASTER-BLUEPRINT-CMS.md`). The blueprints target Laravel 11
+ PostgreSQL 16; **we use Laravel 12 + PostgreSQL 18** — SPEC supersedes them.
