# Plan 002 — Content Domain: Article + Category CRUD (the HOW)

> **Phase:** Plan. Turns `spec.md` into architecture + the exact files to touch.
> **Gated on:** CLAUDE.md §No-negociables + SPEC §5.2/§11.2 arch rules.
> **Build order:** enum/migrations/models/factories/seeder → DTOs/sanitizer/Actions/
> exceptions → controllers/routes/exception-render → pages/i18n → tests. Backend
> before frontend; tests last (or alongside).

## 1. Architecture overview

```
HTTP (Inertia)                              Domain (Illuminate\Http-free)
─────────────                               ─────────────────────────────
Admin\CategoryController                    Domain\Content\Data\{Category,Article,PublishArticle}Data
  index/store/update/destroy                Domain\Content\Actions\{Create,Update,Delete}CategoryAction
Admin\ArticleController                      Domain\Content\Actions\{Create,Update,Delete}ArticleAction
  index/create/store/edit/update/destroy     Domain\Content\Actions\{Publish,Unpublish}ArticleAction
  publish/archive                            Domain\Content\Enums\ArticleStatus (#[TypeScript], state machine)
Public\ArticleController@show               Domain\Content\Services\SanitizesContent (stored-XSS defense)
                                            Domain\Content\Exceptions\{CategoryInUse,InvalidArticleTransition}Exception
bootstrap/app.php  (render the 2 domain      Domain\Content\Models\{Article,ArticleImage,Category}
  exceptions → 302 + field error / 422 JSON) app\Models\User (author_id target; cross-domain model ref)
```

**Rule compliance:** Actions return models / void, throw domain exceptions or
`ValidationException`; they never import `Illuminate\Http`. Controllers are anemic
(DTO→Action→response, `Auth::user()` for the author — facade, not `Request`). DTOs are
the only validation mechanism. The enum owns transition logic; the sanitizer owns the
XSS defense. The two domain exceptions render to 302+field-error (web) / 422 (JSON) in
`bootstrap/app.php`, exactly like UNIGES `InvalidStatusTransitionException` +
`CatalogInUseException`.

## 2. Data model & migrations (reversible, dependency-safe order)

> **ID type:** `$table->id()` **bigint** (NOT ULID) — matches the live `users.id`
> (slice 001) so `author_id` FKs resolve, and matches the UNIGES reference. Deviation A.

Migration order (after the slice-001 `0001_*` migrations):
**categories → articles → article_images.** Timestamps chosen so they run last.

### `create_categories_table` (SPEC §6.3.7 — NO SoftDeletes)
```php
Schema::create('categories', function (Blueprint $table): void {
    $table->id();
    $table->string('name', 30);
    $table->string('slug', 50)->unique();
    $table->string('description', 250)->nullable();
    $table->timestamps();
});
// down(): Schema::dropIfExists('categories');
```

### `create_articles_table` (SPEC §6.3.8 — SoftDeletes; org/branch DEFERRED)
```php
Schema::create('articles', function (Blueprint $table): void {
    $table->id();
    // organization_id / branch_id DEFERRED to the Organization slice (no FK target yet).
    $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
    $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
    $table->string('title', 150);
    $table->string('slug', 180)->unique();
    $table->string('subtitle', 200)->nullable();
    $table->jsonb('content');                              // TipTap doc (sanitized on store)
    $table->string('signature', 200)->nullable();
    $table->string('featured_image_path', 255)->nullable(); // required ON PUBLISH (Action-enforced)
    $table->string('status', 20)->default(ArticleStatus::Draft->value);
    $table->string('meta_title', 200)->nullable();
    $table->text('meta_description')->nullable();
    $table->unsignedBigInteger('views_count')->default(0);
    $table->timestamp('published_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['status', 'published_at']);
    $table->index('category_id');
    $table->index('author_id');
    $table->index('published_at');
});
// down(): Schema::dropIfExists('articles');
```

### `create_article_images_table` (SPEC §6.3.9 — the only CASCADE, §6.4)
```php
Schema::create('article_images', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
    $table->string('path', 300);
    $table->unsignedSmallInteger('sort_order')->default(0);
    $table->timestamps();
    $table->index('article_id');
});
// down(): Schema::dropIfExists('article_images');
```

Notes:
- Import `use App\Domain\Content\Enums\ArticleStatus;` in the articles migration for the
  default (single source), mirroring how slice 001 used `UserRole` in the users migration.
- A DB CHECK on `status` is optional; the Eloquent cast + arch guard it. Skip for portability.
- The `article_images` **cascade** is the one place §6.4 allows it (true child). Article
  soft-delete does NOT remove image rows (soft delete ≠ DELETE), so NEWS-03's "cascade
  delete article_images" is satisfied at the moment of a **force/physical** delete; this
  slice's `DeleteArticleAction` soft-deletes (audit trail) and removes the physical
  featured file. (Document: gallery image-row lifecycle is finalized with the media slice.)

## 3. Files to create / touch

### Domain — Content
| Path | Action | Notes |
|------|--------|-------|
| `app/Domain/Content/Enums/ArticleStatus.php` | create | backed enum, `#[TypeScript]`, state machine |
| `app/Domain/Content/Models/Category.php` | create | `newFactory()`, `articles()` HasMany, PHPDoc, NO SoftDeletes |
| `app/Domain/Content/Models/Article.php` | create | `newFactory()`, SoftDeletes, `content`→array cast, `status`→enum cast, `author()`/`category()` belongsTo, `images()` HasMany, PHPDoc |
| `app/Domain/Content/Models/ArticleImage.php` | create | `newFactory()`, `article()` belongsTo, PHPDoc |
| `app/Domain/Content/Data/CategoryData.php` | create | Spatie Data, `#[TypeScript]`, `final` |
| `app/Domain/Content/Data/ArticleData.php` | create | Spatie Data, `#[TypeScript]`, `final`, `content` structure rule |
| `app/Domain/Content/Data/PublishArticleData.php` | create | empty DTO (bodyless publish) |
| `app/Domain/Content/Services/SanitizesContent.php` | create | `final`, whitelist walk over the TipTap tree |
| `app/Domain/Content/Actions/CreateCategoryAction.php` | create | slug assert + create |
| `app/Domain/Content/Actions/UpdateCategoryAction.php` | create | slug unique-ignore-self |
| `app/Domain/Content/Actions/DeleteCategoryAction.php` | create | `isReferenced()`→`CategoryInUseException` |
| `app/Domain/Content/Actions/CreateArticleAction.php` | create | sanitize+slug+create, `(ArticleData,$author)` |
| `app/Domain/Content/Actions/UpdateArticleAction.php` | create | sanitize+slug-ignore-self+update |
| `app/Domain/Content/Actions/DeleteArticleAction.php` | create | soft delete + drop physical file |
| `app/Domain/Content/Actions/PublishArticleAction.php` | create | precondition + state machine |
| `app/Domain/Content/Actions/UnpublishArticleAction.php` | create | published→draft via state machine |
| `app/Domain/Content/Exceptions/CategoryInUseException.php` | create | `RuntimeException`, message-only |
| `app/Domain/Content/Exceptions/InvalidArticleTransitionException.php` | create | `::between($from,$to)` factory |

### Models / factories / seeder / migrations
| Path | Action | Notes |
|------|--------|-------|
| `database/factories/CategoryFactory.php` | create | name/slug/description |
| `database/factories/ArticleFactory.php` | create | draft default + `published()`/`archived()` states; valid TipTap `content`; `for(User)`/`for(Category)` |
| `database/factories/ArticleImageFactory.php` | create | path/sort_order |
| `database/seeders/CategorySeeder.php` | create | 7 default categories (Appendix D) |
| `database/seeders/DatabaseSeeder.php` | edit | call `CategorySeeder` |
| `database/migrations/..._create_categories_table.php` | create | §2 |
| `database/migrations/..._create_articles_table.php` | create | §2 |
| `database/migrations/..._create_article_images_table.php` | create | §2 |

### HTTP
| Path | Action | Notes |
|------|--------|-------|
| `app/Http/Controllers/Admin/ArticleController.php` | create | anemic; index/create/store/edit/update/destroy/publish/archive |
| `app/Http/Controllers/Admin/CategoryController.php` | create | anemic; index/store/update/destroy |
| `app/Http/Controllers/Public/ArticleController.php` | create | anemic; show (sanitized render) |
| `bootstrap/app.php` | edit | render `CategoryInUseException` + `InvalidArticleTransitionException` |
| `routes/web.php` | edit | article/category routes (role gates) + public show |

### Frontend / i18n
| Path | Action | Notes |
|------|--------|-------|
| `resources/js/Pages/Articles/Index.tsx` | create | list + status badges |
| `resources/js/Pages/Articles/Create.tsx` | create | editor form |
| `resources/js/Pages/Articles/Edit.tsx` | create | editor form + publish/unpublish/archive |
| `resources/js/Pages/Articles/Show.tsx` | create | public read-only, DOMPurify render |
| `resources/js/Pages/Categories/Index.tsx` | create | catalog list + inline CRUD |
| `resources/js/Components/RichTextEditor.tsx` | create | TipTap → JSONB |
| `resources/js/Components/ImageUploader.tsx` | create | single featured image (gallery deferred) |
| `resources/js/Components/Badge.tsx` | create | status/category badge (if not present) |
| `lang/es.json` | edit | `articles.*` + `categories.*` + `article_status.*` keys |
| `lang/en.json` | edit | same keys |
| `resources/js/types/generated.d.ts` | regenerate | via `typescript:transform` (do NOT hand-edit) |

### Tests
| Path | Action | Notes |
|------|--------|-------|
| `tests/Unit/Content/ArticleStatusTest.php` | create | transition matrix (no DB) |
| `tests/Unit/Content/SanitizesContentTest.php` | create | XSS whitelist (script/on*/style/js-href) |
| `tests/Feature/Content/CategoryCrudTest.php` | create | create/slug-unique/delete-blocked/delete-ok |
| `tests/Feature/Content/ArticleCrudTest.php` | create | create-draft/update/slug/soft-delete+cascade |
| `tests/Feature/Content/ArticleValidationTest.php` | create | 302-not-422; content-structure; category exists |
| `tests/Feature/Content/ArticlePublishTest.php` | create | requires-image/requires-content/happy/transitions |
| `tests/Feature/Content/ArticleStoredXssTest.php` | create | persisted row + rendered page both clean |
| `tests/Feature/Content/ContentRoleGateTest.php` | create | guest 302; editor allowed CRUD, 403 on publish; manager publish ok |
| `tests/Feature/Content/ContentPropsTest.php` | create | prop contracts for each page |
| `tests/Feature/Content/ContentLangKeyTest.php` | create | all flashed/thrown keys + status labels es/en |
| `resources/js/Pages/Articles/__tests__/Edit.test.tsx` | create | Vitest: renders editor, surfaces server error |
| `tests/Arch/ArchitectureTest.php` | edit | add Content-only-uses-Shared (+ `Laravel\Scout` whitelisted, unused) |

## 4. Key design decisions

- **`Article`/`Category`/`ArticleImage` live in `app/Domain/Content/Models/`** (unlike
  `User`, which stays in `app/Models` as the framework auth model). They are plain
  `Illuminate\Database\Eloquent\Model`, `final`, with `newFactory()` (the UNIGES
  `Department` pattern) so the `toBeFinal` arch rule + Larastan L9 stay green.
- **PHPDoc on models for L9:** `@property`/`@property-read`; non-nullable belongsTo
  (`author`, `category`) are NOT `|null`; the SoftDeletes-bearing relations and the
  `images` HasMany follow the LESSONS guard. `content` is `@property array<string,mixed>`.
- **One route-agnostic DTO per entity** (UNIGES convention): uniqueness is asserted in
  the Action (`whereKeyNot` on update), NOT a DTO `Unique` attribute — so `CategoryData`
  and `ArticleData` each serve both store and update without rejecting a row's own slug.
- **Slug derivation:** when the DTO's `slug` is null/blank, derive `Str::slug(title|name)`
  in the Action; validate it matches the slug format; assert uniqueness (ignore-self on
  update). (Optionally back it with the Shared `Slug` VO once that lands; not required
  this slice.)
- **`content` structure validation:** `ArticleData` validates `content` is a non-empty
  `array` shaped `{ type: 'doc', content: array }` via a custom `rules()` entry (or an
  `ArrayType`/closure rule). The deep node-whitelist is the **sanitizer's** job, not the
  DTO's — the DTO guards shape, `SanitizesContent` guards safety.
- **Publish precondition in the Action, not the DTO:** `PublishArticleData` is empty
  because the precondition (featured image present + non-empty content) depends on the
  PERSISTED article, not the request body. `PublishArticleAction::handle(Article,$data)`
  asserts it, then runs the state machine.
- **State machine = the enum.** `ArticleStatus::canTransitionTo()` is the SSOT;
  `PublishArticleAction`/`UnpublishArticleAction` call it inside `DB::transaction()` and
  throw `InvalidArticleTransitionException::between($from,$to)` on an illegal move —
  mirroring UNIGES `GraduationStateMachine`/`ApproveDocumentAction`. (A dedicated
  `ArticleStateMachine` class is OPTIONAL; the enum's method is sufficient here since the
  graph is small — keep it on the enum unless it grows.)
- **Sanitize-on-store is authoritative (Deviation C).** `SanitizesContent` runs in
  Create/Update **before** persistence; the DB never holds unsafe content. The show page
  additionally runs DOMPurify (defense-in-depth) and NEVER does raw
  `dangerouslySetInnerHTML` over unsanitized HTML. A test asserts BOTH the persisted row
  and the rendered HTML are clean.
- **Graceful restrict-delete** (UNIGES `DeleteDepartmentAction` + `CatalogInUseException`
  + the `bootstrap/app.php` render): `DeleteCategoryAction.isReferenced()` pre-checks
  `Article::where('category_id', …)->exists()` and throws BEFORE any DELETE, so the
  restrict FK is never tripped (no 500). Rendered as 302 + `category` field error.
- **TS generated file is types-only** — type-only import of `ArticleStatus`/`ArticleData`/
  `CategoryData` in the pages; never value-import the enum (breaks the Vite build). Run
  `php artisan typescript:transform` after adding the `#[TypeScript]` enum/DTOs.
- **Arch rule addition:** `Content domain only leans on Shared, Models, Illuminate,
  Spatie\LaravelData, Spatie\TypeScriptTransformer` + `Laravel\Scout` whitelisted (unused
  this slice, ready for Search) + `->ignoring('__')` for the translation helper.

## 5. Risks & mitigations

| Risk | Mitigation |
|------|-----------|
| New migration breaks the green baseline | dependency-safe order (categories→articles→article_images); `migrate:fresh --seed` in plan-verify; `down()` drops in reverse |
| `author_id`/`category_id` type mismatch | bigint `$table->id()` + `foreignId()` matches `users.id` (Deviation A) |
| Stored XSS slips through | whitelist-walk sanitizer (allow-list, not block-list) on store + DOMPurify on render; dedicated `ArticleStoredXssTest` asserts persisted row AND rendered page are clean for script/on*/style/js-href |
| `content` JSONB malformed | DTO shape rule (`{type:'doc',content:[]}`) + cast to array; sanitizer tolerates/normalizes unknown nodes by dropping them |
| Category delete trips restrict FK → 500 | `isReferenced()` pre-check throws `CategoryInUseException` before DELETE; rendered to 302 |
| Illegal status transition → 500 | enum guard + `InvalidArticleTransitionException` rendered to 302/422 in `bootstrap/app.php` |
| Publish without image silently allowed | precondition asserted in `PublishArticleAction`; `articles.error.publish_requires_image` test |
| Lang key drift | `ContentLangKeyTest` asserts every flashed/thrown key + each `article_status.*` resolves in es+en |
| Editor publishing (RBAC leak) | publish/unpublish/archive routes gated `role:manager`; `ContentRoleGateTest` asserts editor→403 on publish |
| TS value-import of the enum breaks build | type-only import; tsc catches it |

## 6. Verification (read-only steps the build may run; NOT pest/migrate on shared DB)

- `composer analyse` (Larastan L9) — read-only, allowed.
- `tsc --noEmit` / `php artisan typescript:transform` — read-only/codegen, allowed.
- **Do NOT** run `pest` / `migrate` against the shared dev DB from the generating agent;
  CI / the human gate runs the full suite. Tests are written to be green under
  `migrate:fresh --seed` on PostgreSQL 18.
```
