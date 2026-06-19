# NEXT_STEPS.md — Corporate CMS (omnibus-cms)

> Handoff / closeout doc for chapter 3.1–3.3. Mirrors the UNIGES convention:
> three sections — what's verified, what's missing, and the concrete next steps.
> **Last verified:** 2026-06-19, branch `feature/laravel-rebuild` (`79cba31`),
> against the running `omnibus-cms-laravel.test-1` container. Read alongside
> `SPEC.md` (SSOT) and `AGENTS.md` (rulebook).

---

## 1. Verified at this point (evidence-based)

**Git state**
- On `feature/laravel-rebuild`, clean working tree, pushed (`...origin/feature/laravel-rebuild`).
- **0 behind / 4 ahead** of `origin/main`. Not merged. No open PR confirmed
  (`gh` CLI is not installed in this environment; verify on GitHub).
- Parallel branch `feature/foundation-scaffold` (`bc19570`) also exists and is pushed.

**What is actually built** (the whole `app/` tree, verbatim)
- `app/Http/Controllers/LandingController.php` — renders `Landing/Index` with
  hardcoded `'latestArticles' => []`, `'latestJobs' => []`.
- `app/Http/Controllers/Controller.php`, `app/Http/Middleware/HandleInertiaRequests.php`,
  `app/Models/User.php`, `app/Providers/AppServiceProvider.php` — all default scaffold.
- `routes/web.php` — one route: `Route::get('/', LandingController::class)->name('home')`.
- `resources/js/Pages/Landing/Index.tsx` — bilingual ES/EN landing, magenta `#DD00FF`.
- Migrations: **only** the 3 Laravel defaults (`users`, `cache`, `jobs`).
- Seeders: only `DatabaseSeeder.php` (default).

**Quality gates (run 2026-06-19 in-container)**

| Gate | Result | Evidence |
|------|--------|----------|
| **Pint `--test`** | ❌ FAIL (2 issues) | `bootstrap/app.php` (`fully_qualified_strict_types`, `ordered_imports`) and `tests/Pest.php` (`fully_qualified_strict_types`, `single_line_after_imports`) — both default scaffold files. 31 files scanned. |
| **PHPStan / Larastan** | ⚠️ NOT CONFIGURED | No `phpstan.neon*`; larastan not a dependency. SPEC §11.7 requires level 9 (vault wants level 10). Cannot run. |
| **Pest (`php artisan test`)** | ✅ PASS | 3 tests / 12 assertions: `Unit\ExampleTest`, `Feature\ExampleTest` (both scaffold), `Feature\LandingTest` (renders the Inertia Landing component). **No arch tests.** |
| **`tsc --noEmit`** | ❌ FAIL (1 error) | `resources/js/app.tsx:12` `TS2769: No overload matches this call` — the `import.meta.glob('./Pages/**/*.tsx')` lazy loaders don't match the CSR `resolve` overload. Pre-existing. |
| **`pnpm build`** | ✅ PASS (per prior recon) | Vite 7, ~614 modules, `app.js` ~316 kB / gzip ~100 kB. |
| **`pnpm lint`** | — N/A | No `lint` script / ESLint configured. |

**Docs reconciliation done in this closeout (2026-06-19)**
- Created **`AGENTS.md`** as the canonical agent rulebook (agents.md standard),
  with the package/PHP-version drift called out honestly instead of stated as fact.
- Slimmed **`CLAUDE.md`** to a non-drifting pointer (no rules of its own).
- Rewrote **`README.md`** (was a one-line `# omnibus-cms-sindical` stub that
  contradicted the "Corporate CMS" whitelabel).
- Created this **`NEXT_STEPS.md`**.
- `SPEC.md` left untouched (it remains the SSOT).

**Staging** — real but minimal: `Dockerfile` runtime stage is `php:8.5-cli-alpine`
serving via `php artisan serve` on **SQLite + file session + file cache + sync
queue**. It serves only the landing page, not the (unbuilt) CMS.

---

## 2. Known gaps / issues

**Product (vs. SPEC) — essentially everything past the scaffold**
- ❌ No `app/Domain/` at all. None of the 8 domains (Identity, Organization,
  Content, Jobs, Engagement, Membership, Analytics, Shared) exist.
- ❌ None of the **14 SPEC tables** (§6.3): `organizations`, `branches`, `directors`,
  `representatives`, `categories`, `articles`, `article_images`, `job_postings`,
  `contact_messages`, `members`, `daily_snapshots`, `page_views`, `municipalities`.
- ❌ No auth, no 4-level RBAC, no demo login (SPEC §3, §10).
- ❌ No Article/Category CRUD, no TipTap, no JSONB content, no HTML sanitizer.
- ❌ `LandingController` returns hardcoded empty arrays — not wired to real data.
- ❌ No Spatie Data DTOs, no Pest arch tests, no dark/light toggle, no ES/EN switcher
  wiring beyond static landing copy.
- ❌ Demo mode (SPEC §13) entirely unimplemented: no `DemoSessionMiddleware`,
  no `demo:cleanup` command/scheduler, no baseline seed. The landing "Explorar
  demo" CTA has no backend.
- ❌ No ETL (`migrate:legacy`).

Per SPEC §15, **Phase 1 (Foundation + Auth + Content MVP) is not started.** What
exists is effectively "Phase 0: scaffold + landing shell." Phases 2–4 are greenfield.

**Doc/config drift still in the code (docs now flag it; the code still needs fixing)**
- ⚠️ `composer.json` pins `php: ^8.2` — should be `^8.5` (vault law + Dockerfile use 8.5).
- ⚠️ SPEC-mandated packages **not installed**: `spatie/laravel-data`,
  `spatie/laravel-typescript-transformer`, `laravel/scout` + `meilisearch-php`,
  `mews/purifier`, larastan. So the "Spatie Data only / Larastan level 9" rules
  can't currently be honored.
- ⚠️ No `phpstan.neon`; no `composer analyse|format|test` custom scripts (only
  Laravel's default `test`), despite docs referencing all three.
- ⚠️ composer `setup`/`dev` scripts call `npm install` / `npm run build` / `npx`
  — violates the pnpm-only law (the hook blocks `npm` at the shell, so these
  scripts will fail as written).
- ⚠️ `package.json` has a stale `pnpm.onlyBuiltDependencies` key (pnpm no longer
  reads it there) and lacks SPEC §8/§11 frontend deps (TipTap, an i18n lib,
  Vitest/React Testing Library, ESLint).

**Quality gates**
- ❌ Pint fails on 2 scaffold files (cosmetic, one `pint` run fixes it).
- ❌ `tsc` fails on `app.tsx:12` (real typing issue).
- ⚠️ PHPStan can't run (unconfigured).
- ⚠️ Pest passes but only covers trivial/scaffold behavior; no arch tests.

**Staging architecture**
- ⚠️ SQLite/file/sync `artisan serve` shell diverges from the SPEC target
  (PostgreSQL 18 + Valkey + Meilisearch). Acceptable for a landing demo, but it
  must be replaced (Phase 4) before "CMS deployed" is true. Do not flip portfolio
  `available: true` for the Laravel version on the strength of this.

---

## 3. Next steps

### A. Reconcile config drift with reality (do first — cheap, unblocks gates)
- [ ] Bump `composer.json` `"php"` to `^8.5`.
- [ ] Replace `npm install` / `npm run build` / `npx concurrently` / `npm run dev`
      in the composer `setup` and `dev` scripts with their pnpm equivalents.
- [ ] Add `composer analyse` (PHPStan/Larastan), `composer format` (Pint), and a
      `composer test` that runs Pest with arch tests — match `AGENTS.md`/SPEC §11.
- [ ] Remove the stale `pnpm.onlyBuiltDependencies` key from `package.json`
      (move to `pnpm-workspace.yaml` or the correct location if still needed).

### B. Fix the two real gate failures (quick wins)
- [ ] Run `./vendor/bin/pint` to fix `bootstrap/app.php` + `tests/Pest.php` (then
      gate is green).
- [ ] Fix `resources/js/app.tsx:12` `TS2769` — type the `import.meta.glob` result
      or follow the current Laravel+Inertia 2 `resolvePageComponent` typing pattern
      so the CSR overload matches.

### C. Stand up tooling the DDD law depends on
- [ ] Install `spatie/laravel-data` + `spatie/laravel-typescript-transformer`;
      wire `php artisan typescript:transform`.
- [ ] Install larastan; add `phpstan.neon` at level 9 (SPEC) / 10 (vault) and get a
      clean baseline.
- [ ] Add Vitest / React Testing Library + ESLint (SPEC §11.3) and a `pnpm lint` script.

### D. Build SPEC Phase 1 — Foundation + Auth + Content (MVP)
- [ ] Create the `app/Domain/` tree per SPEC §5.4 (Identity, Organization, Content,
      Jobs, Engagement, Membership, Analytics, Shared).
- [ ] Add the **Pest arch tests** (SPEC §11.2) that enforce DDD boundaries — write
      these early; they are part of "done," not a follow-up.
- [ ] Write the 14 migrations (§6.3) with FK-restrict + SoftDeletes (cascade only on
      `article_images`). Verify each is reversible before running.
- [ ] Identity domain: User/Role model + 4-level RBAC (`UserRole` backed enum with
      `label()`/`color()`/`level()`), auth, and demo login (30-min TTL).
- [ ] Content domain: Article + Category + ArticleImage, TipTap JSONB content,
      `mews/purifier` sanitization, Article/Category CRUD via Actions + Spatie Data DTOs.
- [ ] Wire `LandingController` to real data (8 articles + 6 jobs per SPEC).
- [ ] Persistent dark/light toggle + ES/EN switcher across screens.
- [ ] Seed **fictional** demo data only (PII law — never legacy real data).

### E. Demo mode (SPEC §13) — can land with Phase 1 auth or just after
- [ ] `DemoSessionMiddleware` (isolated session context + TTL).
- [ ] `php artisan demo:cleanup` scheduled every 15 min; baseline seed never mutated;
      demo data tagged with `demo_session_id`; 10 logins/IP/hr rate limit.

### F. Phases 2–4 (greenfield — track in SPEC §15)
- [ ] Phase 2: Organization/Branches/Representatives/Directors, JobPostings (cents),
      ContactMessages + rate limiting, org-scope middleware.
- [ ] Phase 3: Member registration (CURP/RFC **encrypted**), Meilisearch/Scout search,
      analytics dashboard + page-view tracking with IP dedup.
- [ ] Phase 4: `migrate:legacy all` ETL; replace the SQLite staging shell with the
      real **PostgreSQL 18 + Valkey + Meilisearch** stack; deploy to
      `cms.carlosgardea.com`; Lighthouse ≥ 90; flip portfolio `available: true`
      only after the URL returns 200.

### G. Process / merge
- [ ] Decide the fate of `feature/foundation-scaffold` (merge useful infra into
      `feature/laravel-rebuild`, or delete it) — two parallel scaffold branches is
      a hazard. **Carlos's call.**
- [ ] Before opening a PR to `main`: at minimum land sections A + B (drift fixed,
      Pint + tsc green), so the merged baseline doesn't carry red gates.

---

## Merge-readiness summary

**Is `feature/laravel-rebuild` merge-ready to `main`?**

- **Feature-complete for chapter 3.1–3.3?** No — Phase 1 is not started; this is a
  scaffold + landing page, not a CMS.
- **Gates green?** No — Pint fails (cosmetic), `tsc` fails (real), PHPStan
  unconfigured. Pest passes but is trivial.

**Recommendation:** It is **fine to merge as an honest "scaffold + landing"
baseline** *after* fixing the red gates (sections A + B), so `main` carries a clean
starting point and the corrected docs — but it must **not** be presented as the CMS
being built or deployed. The substantive product work (sections C–F) happens on
feature branches afterward. If Carlos prefers `main` to stay at the previous
docs-only commit until Phase 1 lands, that is also valid — this is his call.
