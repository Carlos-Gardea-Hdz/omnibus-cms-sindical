# Spec 002 — Content Domain: Article + Category CRUD (the CMS core value)

> **Phase:** Specify (the WHAT and WHY, not the HOW).
> **SSOT:** `SPEC.md` (§3.3 Content NEWS-01/02/03/07 + CAT-01/02 + the HTML
> Sanitization Whitelist, §5.1/§5.2 DDD + arch rules, §6.3.7/6.3.8/6.3.9 tables +
> §6.4 FK summary, §7.2 Editor+ routes, §10.2 RBAC matrix, §11.2 arch rules +
> §11.4 key scenarios, §1.4/§1.5 magenta theme, Appendix A `ArticleStatus`,
> Appendix D categories).
> **Reference impl (same stack):** `omnibus-uniges` — the Academic catalog CRUD
> (`DepartmentController` + `DepartmentData` + Create/Update/Delete Actions +
> `CatalogInUseException`) is the Category-CRUD + graceful-restrict-delete template;
> the Graduation `GraduationStateMachine` + `ApproveDocumentAction` + the
> `InvalidStatusTransitionException` exception-render registration are the
> publish/unpublish state-transition template.
> **Built on:** CMS slice 001 Identity (`UserRole` ladder, `EnsureRole` `role:<level>`
> gate, `RoleLandingRoute`, `app/Models/User` as the `author_id` target, the
> arch suite, the magenta theme + form primitives + `lang/{es,en}.json`).
> **Status:** DRAFT — awaiting human review gate before `/sdd-plan` / build.

## 1. Problem & why

Slice 001 built the auth spine; there is still **no content**. The CMS's entire
reason to exist (SPEC §15 Phase 1 MVP) is article management: a staff member writes
a rich-text article, attaches a featured image, and publishes it. This slice builds
the **Content domain core** — `Article` + `Category` + `ArticleImage`, their CRUD,
the draft→published state machine, and the load-bearing **stored-XSS defense** that
sanitizes TipTap JSONB content against the SPEC §3.3 whitelist before it is ever
persisted. Every later content-facing surface (landing page, public article show,
Meilisearch, analytics page-views) hangs off these three tables.

This is the first **multi-table, mutate-heavy** domain in the CMS: it exercises the
Action+DTO+transaction pattern, the backed-enum state machine, the
graceful-restrict-delete pattern, and a security-critical sanitization layer — all
of which the Identity slice did not need.

## 2. Scope

### In scope (Content MVP)

1. **`ArticleStatus` backed enum** (`app/Domain/Content/Enums/ArticleStatus.php`,
   `#[TypeScript]`) — `draft`, `published`, `archived` (Appendix A). Owns the strict
   state machine `canTransitionTo(self): bool` (draft→published|archived;
   published→archived; archived→published), `allowedTransitions(): array`,
   `labelKey()`, `color()`/`badgeColor()`. No magic strings downstream.

2. **`categories` table + `Category` model** (SPEC §6.3.7) — `name` (≤30), `slug`
   (unique, ≤50), `description` (nullable ≤250). **No `SoftDeletes`** (SPEC §6.3.7
   lists no `deleted_at`; categories are a hard-delete catalog protected by a
   restrict-in-use guard, exactly like UNIGES `Department`). 7 default categories
   seeded (Appendix D).

3. **`articles` table + `Article` model** (SPEC §6.3.8) — `title` (≤150), `slug`
   (unique, ≤180), `subtitle` (nullable ≤200), `content` JSONB (TipTap doc,
   cast `array`), `signature` (nullable ≤200), `featured_image_path` (nullable —
   **required only on publish**), `status` (`ArticleStatus`, default `draft`),
   `meta_title`/`meta_description` (nullable SEO), `views_count` (default 0),
   `published_at` (nullable), `author_id` FK→`users` **restrict**, `category_id`
   FK→`categories` **restrict**, `SoftDeletes`. `organization_id`/`branch_id` are
   **DEFERRED** (see Out of scope).

4. **`article_images` table + `ArticleImage` model** (SPEC §6.3.9) — `article_id`
   FK→`articles` **cascade** (the one true-child cascade per §6.4), `path` (≤300),
   `sort_order` (default 0). This slice creates the table + model + the cascade FK
   so NEWS-03 ("cascade delete article_images") is satisfiable; the **gallery upload
   UI is DEFERRED** (see Out of scope) — only the **featured image** is wired this
   slice (it lives on `articles.featured_image_path`, not in this table).

5. **DTOs (Spatie Data, `#[TypeScript]`, `final`)** — one route-agnostic DTO per
   entity serving both store and update (the UNIGES convention: uniqueness enforced
   in the Action, not a DTO `Unique` attribute, so the same DTO serves create +
   update without rejecting a row's own slug):
   - `CategoryData` — `name` (required ≤30), `slug` (nullable ≤50, derived from name
     when absent, validated against the slug format), `description` (nullable ≤250).
   - `ArticleData` — `title` (required ≤150), `slug` (nullable ≤180), `subtitle`
     (nullable ≤200), `content` (required `array` — the TipTap doc; **structure
     validated**: must be `{ type: 'doc', content: array }`), `category_id` (required,
     `Exists('categories','id')`), `signature`/`meta_title`/`meta_description`
     (nullable), `featured_image` (nullable `UploadedFile` — image mime, ≤20MB).
   - `PublishArticleData` — **empty/no-field DTO** (publish carries no body; the
     publish precondition — featured image present + non-empty content — is asserted
     in the Action, not the DTO, because it depends on the persisted Article state,
     not the request). The publish route is a bodyless POST.

6. **`SanitizesContent` service** (`app/Domain/Content/Services/SanitizesContent.php`,
   `final`) — the load-bearing stored-XSS defense. Walks the TipTap JSONB tree and
   strips anything outside the SPEC §3.3 whitelist: allowed marks/nodes map to the
   allowed tags `p, strong, em, u, a, ul, ol, li, span, div, br`; allowed attributes
   `class, href, target`; **every `on*` handler, every `style`, every `script`/`<` raw
   HTML node, and any `href` with a `javascript:`/`data:` scheme is removed.** Runs in
   `CreateArticleAction` + `UpdateArticleAction` **before persistence** (sanitize-on-
   the-way-IN, the primary defense), so the DB never holds an unsafe payload. The
   public show page additionally renders sanitized HTML; it MUST NOT inject raw,
   unsanitized user HTML (no `dangerouslySetInnerHTML` over unsanitized content).

7. **Actions (`final`, `DB::transaction` on multi-table writes, one operation each)**:
   - `CreateCategoryAction` / `UpdateCategoryAction` / `DeleteCategoryAction` — mirror
     UNIGES `*DepartmentAction`. Slug uniqueness asserted in the Action
     (`whereKeyNot` on update). `DeleteCategoryAction` is the **graceful restrict-delete**:
     an `isReferenced()` pre-check throws `CategoryInUseException` (CAT-02) BEFORE any
     DELETE, so the restrict FK is never tripped (→ 302 + field error, never 500).
   - `CreateArticleAction` / `UpdateArticleAction` — sanitize `content`, derive/validate
     slug (unique-ignore-self on update), persist; transactional (article + future
     image rows). Author is the authenticated user (`author_id`), passed in by the
     controller (the Action takes `(ArticleData, User $author)`), not read from a
     facade in the domain.
   - `DeleteArticleAction` — soft-delete the article; `article_images` cascade is at
     the DB level (NEWS-03). Physical featured-image file deletion is performed here
     (Storage).
   - `PublishArticleAction` / `UnpublishArticleAction` — the state machine. Publish
     asserts the precondition (featured image present + non-empty content) then
     `draft|archived → published`, sets `published_at`. Unpublish is
     `published → draft` (a deliberate CMS affordance; **see Deviation B** — SPEC §3.3
     NEWS-07 lists `published → archived` and `archived → published`; "unpublish back
     to draft" is the editor-facing verb this slice ships, gated by the state machine).
     Illegal transitions throw `InvalidArticleTransitionException` (→ graceful 302,
     never 500), registered in `bootstrap/app.php` beside the new
     `CategoryInUseException` handler.

8. **Anemic controllers (≤15 lines/method, DTO→Action→response)**:
   - `Admin\ArticleController` — `index`, `create`, `edit` (render Inertia),
     `store(ArticleData, CreateArticleAction)`, `update(Article, ArticleData,
     UpdateArticleAction)`, `destroy(Article, DeleteArticleAction)`,
     `publish(Article, PublishArticleData, PublishArticleAction)`,
     `archive`/unpublish wired to the unpublish/transition Action. `Auth::user()`
     supplies the author (facade in the controller, never `Illuminate\Http\Request`).
   - `Admin\CategoryController` — `index`, `store`, `update`, `destroy` (mirrors UNIGES
     `DepartmentController` exactly: `back()->with('success', …)`).

9. **Routes** (`routes/web.php`, all `->name()`, no closures) — under
   `['auth','role:editor']` per SPEC §7.2 (Articles + Categories are **Editor+**;
   the level gate from slice 001 already enforces the ladder). Article resource +
   `POST /admin/articles/{article}/publish` + `POST /admin/articles/{article}/archive`
   (unpublish/archive); Category `index/store/update/destroy`. The **public show page**
   (`GET /articles/{article:slug}` → `Public\ArticleController@show`) is included as a
   read-only render so the sanitization-render contract is testable; the
   `views_count` atomic increment + `TrackPageViewMiddleware` are **DEFERRED** to the
   Analytics slice (NEWS-04 note below).

   > **RBAC note (SPEC §10.2):** Categories CRUD is "All" for manager+ and `—` for
   > editor; Articles CRUD is "Own org" for editor+. This slice gates **both at
   > `role:editor`** (level-only, per slice 001's deferred org-scoping) and documents
   > that **(a)** editor-vs-manager on Categories and **(b)** org-scoping on Articles
   > are DEFERRED to the Organization slice. Publish is `role:manager+` per §10.2
   > (editors create/edit but do **not** publish) — see Gate decision D.

10. **Inertia 2 + React 19 + TS pages** (magenta theme, dark/light + ES/EN, snake_case
    props matching the controller payload exactly):
    - `Articles/Index` — paginated list (title, status badge, category, author,
      published_at, actions).
    - `Articles/Create` + `Articles/Edit` — the editor form: title/subtitle/slug,
      a TipTap `RichTextEditor` bound to the JSONB `content`, category select,
      featured-image uploader, SEO fields. Publish/unpublish/archive controls on Edit.
    - `Categories/Index` — list + inline create/edit/delete (mirrors the UNIGES catalog
      page).
    - `Public/ArticleShow` — read-only public render of a published article; renders
      the **sanitized** content (server already sanitized on store; the frontend
      additionally guards with DOMPurify per §3.3 — never raw `dangerouslySetInnerHTML`
      over unsanitized HTML).

11. **i18n keys** — `articles.*`, `categories.*`, `article_status.*` keys added to BOTH
    `lang/es.json` and `lang/en.json` — every `__()` key any Action/exception/controller
    flashes or throws (`articles.created/updated/deleted/published/unpublished`,
    `articles.error.publish_requires_image`, `articles.error.publish_requires_content`,
    `articles.error.invalid_transition`, `categories.created/updated/deleted`,
    `categories.error.slug_taken`, `categories.error.in_use`, `articles.error.slug_taken`)
    plus the `article_status.{draft,published,archived}` labels the enum's `labelKey()`
    resolves. Pure UI copy (field labels, buttons) is client-side i18n.

12. **Tests (Pest, PostgreSQL 18 — never SQLite; Feature for app-bound, Unit for pure):**
    See §3 acceptance scenarios; the exhaustive falsifiable list is in plan.md /
    the contract.

### Out of scope — DEFERRED (explicitly noted, not silently dropped)

- **`organization_id` + `branch_id` on `articles`** (SPEC §6.3.8 lists them, FK
  restrict) → need the **Organization domain** (no `organizations`/`branches` tables
  exist yet). They are **NOT added** this slice (adding nullable-no-FK columns now
  would contradict §6.4's restrict FK and force a re-migration). The Organization slice
  adds both columns + their restrict FKs and the org-scoping gate. Documented divergence.
- **Article gallery upload** (multiple `article_images` rows via an `ImageUploader`,
  `sort_order` management, the `ImageStorageService` / `ProcessImageJob` pipeline) →
  a later **media slice**. This slice creates the `article_images` table + model +
  cascade FK (so NEWS-03 is satisfiable) and wires only the **single featured image**
  (`articles.featured_image_path`). Heavy image processing (resize/variants) deferred.
- **NEWS-04 `views_count` atomic increment + `TrackPageViewMiddleware`** → the
  **Analytics slice**. The public show page renders this slice; view-tracking does not.
- **NEWS-05 landing page wiring** (8 latest published + 6 jobs) → the
  `LandingController` currently returns empty arrays; wiring real published articles is
  a small follow-up once `Article` exists (recommend folding into this slice's
  `Articles/Index`-adjacent work only if cheap; otherwise defer — Gate decision E).
- **NEWS-06 Meilisearch search** (`Searchable`, `toSearchableArray`, `SearchController`)
  → the **Search slice** (SPEC §9.1). `Article` is NOT made `Searchable` this slice
  (the arch rule already whitelists `Laravel\Scout` for when it is).
- **ULID primary keys** (SPEC §6 specifies `CHAR(26)` ULID) → **NOT adopted.** Slice
  001 shipped `users.id` as bigint (`$table->id()`), and `author_id` must match that
  type. This slice uses `$table->id()` bigint + `foreignId()` throughout for
  consistency with the live `users` table and the UNIGES reference. Documented
  divergence (Deviation A) — a program-wide ULID migration is a separate decision.

### Deviation from SPEC, flagged for the gate

- **A. bigint IDs, not ULID.** SPEC §6 specifies `CHAR(26)` ULID PKs; the built
  `users` table (slice 001) + UNIGES use `$table->id()` bigint. `author_id` must match
  `users.id`. → keep bigint program-wide consistency. **Gate decision A.**
- **B. Unpublish verb = `published → draft`.** SPEC §3.3 NEWS-07 lists
  `draft→published→archived` and `archived→published`. This slice ships an editor-facing
  **Unpublish (published→draft)** plus **Archive (published/draft→archived)** and
  **Republish (archived→published)**. The enum's `canTransitionTo` is the SSOT and
  permits draft→published, draft→archived, published→archived, published→draft,
  archived→published. → confirm the unpublish-to-draft affordance. **Gate decision B.**
- **C. Sanitize-on-store as the PRIMARY defense.** SPEC §3.3 says sanitization applies
  "at render time (DOMPurify on frontend) and at the server sanitization layer before
  storage." This slice makes **server-side sanitize-before-store** the authoritative
  defense (the DB never holds unsafe content); DOMPurify on render is the defense-in-
  depth second layer. → confirm. **Gate decision C.**
- **D. Publish gated at `role:manager`, create/edit at `role:editor`.** SPEC §10.2:
  "Articles Publish — editor: —" (editors may NOT publish). → publish/unpublish/archive
  routes gated `role:manager`; create/edit/store/update/destroy gated `role:editor`.
  **Gate decision D.**

## 3. Acceptance scenarios (When… Then)

1. **Create category.** *When* an editor+ posts a valid `CategoryData`, *then* the
   category is created (slug derived from name when omitted) and the response is a
   302 back with `categories.created`.
2. **Category slug unique.** *When* a category is created/updated with a slug another
   category already holds, *then* 302 back with a session error on `slug`
   (`categories.error.slug_taken`); on update a row keeps its OWN slug without error.
3. **Category delete blocked when articles exist (CAT-02).** *When* a category with at
   least one article is deleted, *then* `CategoryInUseException` → 302 back with a
   `category` field error (`categories.error.in_use`), the category is NOT deleted, and
   no 500/SQL error reaches the user.
4. **Category delete allowed when empty.** *When* a category with no articles is
   deleted, *then* it is removed and 302 back with `categories.deleted`.
5. **Create article (draft).** *When* an editor+ posts a valid `ArticleData` with no
   `featured_image`, *then* an `Article` is created with `status = draft`,
   `published_at = null`, slug derived/unique, content **sanitized**, and 302 back with
   `articles.created`.
6. **Article slug unique-ignore-self.** *When* an article is updated keeping its own
   slug, *then* no error; *when* it takes another article's slug, *then* 302 + `slug`
   error (`articles.error.slug_taken`).
7. **Web validation = 302, never 422.** *When* `ArticleData`/`CategoryData` rules fail
   (missing title, over-length, missing/invalid `content` structure, non-existent
   `category_id`), *then* Spatie Data surfaces a 302 redirect-back with session errors,
   never a 422 JSON response.
8. **Publish requires featured image (NEWS-01).** *When* a draft with NO
   `featured_image_path` is published, *then* `PublishArticleAction` refuses →
   302 + error (`articles.error.publish_requires_image`); the article stays `draft`.
9. **Publish requires non-empty content.** *When* a draft whose `content` doc has no
   body is published, *then* 302 + `articles.error.publish_requires_content`; stays draft.
10. **Publish happy path.** *When* a draft WITH a featured image and non-empty content is
    published, *then* `status = published`, `published_at` is set (≈now), 302 +
    `articles.published`.
11. **State transitions (NEWS-07).** *Valid:* draft→published, draft→archived,
    published→archived, published→draft (unpublish), archived→published (republish) all
    succeed via the enum/Action. *Invalid:* archived→draft, published→published (no-op
    rejected as illegal) throw `InvalidArticleTransitionException` → 302 +
    `articles.error.invalid_transition`, never 500.
12. **Soft delete + image cascade (NEWS-03).** *When* an article is deleted, *then* it
    is soft-deleted (`deleted_at` set, row retained), its `article_images` rows are
    DB-cascade-deleted, and the physical featured-image file is removed from storage.
13. **Role gating (SPEC §7.2/§10.2).** *When* a guest hits any `/admin/articles*` or
    `/admin/categories*` route, *then* 302 to login. *When* an authenticated editor hits
    create/edit/store, *then* 200/302 (allowed); *when* an editor hits the **publish**
    route, *then* 403 (publish is manager+). A manager+ publishing → allowed.
14. **STORED-XSS neutralized (NEWS-01, §3.3, §11.4 #11).** *When* an article is stored
    with a `content` doc carrying a `<script>`, an `onerror=`/`onclick=` handler, a
    `style` attribute, or an `href="javascript:…"`, *then* the **persisted** `content`
    contains none of them (script node dropped, `on*`/`style` stripped, dangerous
    `href` scheme removed) — asserted against the DB row AND against the rendered show
    page (no executable payload survives). The whitelist allows only
    `p/strong/em/u/a/ul/ol/li/span/div/br` + `class/href/target`.
15. **Prop contract.** *When* each page renders, *then* the Inertia component name +
    its props match the page's prop interface exactly (snake_case); no leaked server
    props beyond the declared shape.
16. **Lang keys resolve.** *When* every flashed/thrown `__()` key and each
    `article_status.{value}` label is resolved under `es` and `en`, *then* a non-empty,
    non-key string is returned in both locales.
17. **Content-domain isolation (arch, §11.2).** The Content domain only leans on
    Shared + Models + Illuminate + Spatie\LaravelData + Spatie\TypeScriptTransformer
    (Scout whitelisted for the future Search slice but unused now); never HTTP, never
    another domain. Strict types, final domain classes, anemic controllers, no
    FormRequest — all green.

## 4. Success criteria (definition of done for this slice)

- `composer format` (Pint) clean; `composer analyse` (Larastan **level 9**) green;
  `composer test` (Pest, incl. the extended arch suite + the new unit/feature tests on
  PostgreSQL 18) green.
- `php artisan typescript:transform` emits `ArticleStatus`, `ArticleData`,
  `CategoryData`, `PublishArticleData` into `resources/js/types/generated.d.ts`;
  `tsc --noEmit` and Vitest pass.
- `php artisan migrate:fresh --seed` applies cleanly on PostgreSQL 18: `categories`,
  `articles`, `article_images` created with the documented FK rules
  (articles→users/categories restrict, article_images→articles cascade); slugs unique;
  7 categories seeded; `down()` reverses cleanly in dependency-safe order.
- Article CRUD + publish/unpublish/archive is a working round trip in the UI; the
  TipTap editor stores JSONB; the featured-image upload works; the public show page
  renders sanitized content; category delete is gracefully blocked when in use.
- **The stored-XSS test proves a `<script>`/`onerror` payload is neutralized** in both
  the persisted row and the rendered page.
- No real PII anywhere; demo seed content is fictional; `.gitignore` still blocks
  db/secrets/uploads.

## 5. Constitution (§No-negociables) this slice must satisfy

`declare(strict_types=1)` everywhere · explicit return types · `final` domain classes ·
`readonly` + constructor promotion on DTOs/VOs · backed `ArticleStatus` enum with
`canTransitionTo()/allowedTransitions()/labelKey()/color()` · Spatie Data DTOs only
(no FormRequest, no `$request->validate()`) · Actions one-operation, `DB::transaction`
on multi-table writes · anemic controllers (≤15 lines, DTO→Action→response) · domain
never imports `Illuminate\Http` · web validation = 302 + session errors, never 422 ·
stored-XSS sanitization on store + safe render (never raw unsanitized
`dangerouslySetInnerHTML`) · migrations reversible, FK-restrict + SoftDeletes (cascade
only on `article_images`) · JSONB cast to array + structure validated · Pest with arch
tests + a Content-domain isolation rule · PostgreSQL for DB tests · dark/light +
bilingual UI · every flashed/thrown `__()` key present in both `lang/es.json` and
`lang/en.json` (+ a resolution test).

## 6. Open questions for the gate

- **A. bigint IDs (not ULID) — confirm.** `author_id` must match the live bigint
  `users.id`. Recommend bigint program-wide. Confirm (or schedule a separate ULID
  migration before more domains land).
- **B. Unpublish = published→draft — confirm.** SPEC NEWS-07 lists archive/republish;
  this slice adds an editor unpublish-to-draft. Recommend include. Confirm.
- **C. Sanitize-on-store as the authoritative defense — confirm.** DB never holds
  unsafe HTML; DOMPurify on render is defense-in-depth. Confirm.
- **D. Publish gated manager+, create/edit editor+ — confirm.** Per §10.2 editors may
  not publish. Confirm the split (or gate publish at editor for the demo's sake).
- **E. Fold NEWS-05 landing wiring into this slice or defer?** Recommend a tiny follow-up
  (wire 8 latest published into `LandingController`) once `Article` exists; defer if it
  bloats the slice. Confirm.
- **F. `signature` / `meta_title` / `meta_description` in MVP?** SPEC §6.3.8 lists them
  nullable. Recommend land the columns now (cheap, schema-stable) and surface
  `signature` + SEO fields in the editor form. Confirm or defer the UI to a polish slice.
