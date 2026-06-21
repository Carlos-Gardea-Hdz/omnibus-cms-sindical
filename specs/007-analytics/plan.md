# Plan 007 — Analytics Domain (the HOW) — THE LAST CMS DOMAIN

> **Phase:** Plan. Turns `spec.md` into architecture + the exact files to touch.
> **Gated on:** CLAUDE.md §No-negociables + SPEC §6.4 FK rules + §10.2/§10.5 + §11.2 arch.
> **Build order:** migrations (page_views + daily_snapshots) → models → TimePeriod enum → factories/seed →
> filter DTO → RecordPageViewAction → wire the increment into Public\ArticleController@show → metric
> Services → admin controller/route → page/i18n → tests (the dedup+atomic-increment, the single-query
> assertion, and the org-isolation crown LAST).
> Backend before frontend; `RecordPageViewAction` (the cheap write) + the org-scoped metric Services are the
> spine.

## 1. Architecture overview

```
HTTP (Inertia)                                Domain (Illuminate\Http-free) + App\Support
─────────────                                 ───────────────────────────────────────────
Public\ArticleController@show  ──record──►    Domain\Analytics\Actions\RecordPageViewAction
  (slice-002 show; try/catch fail-soft;          (24h dedup EXISTS → no-op | DB::transaction:
   passes Article + sha256(ip) + UA)              $article->increment('views_count') + PageView::insert)
Admin\AnalyticsController@index  ──read──►     Domain\Analytics\Services\ArticleAnalyticsService
  (administrator+, org.scope,                     (totalViews / topArticlesByViews / viewsOverTime)
   AnalyticsFilterData, index ONLY)            Domain\Analytics\Services\EngagementAnalyticsService
                                                  (jobsActiveVsClosed / memberRegistrationTrend / contactMessagesPerPeriod)
                                              Domain\Analytics\Enums\TimePeriod (Day/Week/Month + truncUnit())
                                              Domain\Analytics\Data\AnalyticsFilterData (#[TypeScript], all nullable)
                                              Domain\Analytics\Models\PageView (OrganizationScope; append-only; viewed_at clock)
                                              Domain\Analytics\Models\DailySnapshot (OrganizationScope; shipped, job DEFERRED)
                                              App\Support\OrganizationScope / OrganizationContext (slice 003 — REUSED, READ confinement)
                                              REUSED MODELS: Content\Article, Organization\{Organization,Branch},
                                                Jobs\JobPosting, Membership\Member, Engagement\ContactMessage
UNTOUCHED (confirm, Decision H): DeleteArticleAction (SET NULL is a DB cascade; likely no app-level change)
```

**Rule compliance:** `RecordPageViewAction` returns `void`, wraps its mutation in `DB::transaction`, never
imports `Illuminate\Http` (the `ip_hash`/UA arrive as plain scalars from the controller). The metric Services
are read-only (query + map, no transaction). Controllers anemic (public `show` = guarded Action call + render;
admin `index` = DTO+services→render). The filter DTO is the only validation. NO domain exception, NO
`bootstrap/app.php` edit (the tracking path is fail-soft via a controller try/catch — Decision B; the only
thrown thing is a real DB fault, swallowed at the call site).

### KEY DIFFERENCES vs prior slices (do NOT blindly copy)

- **A WRITE with NO DTO + NO public route (vs slices 005/006 public submits).** The view is recorded
  server-side from the already-resolved Article — no payload, no Spatie DTO, no new public route (Decision A).
- **An atomic counter + append-only event table (vs every prior CRUD table).** `views_count` is bumped with
  `increment()` (race-safe); `page_views` is append-only (NO SoftDeletes, NO `created_at`/`updated_at` — the
  `viewed_at` event time is the clock).
- **READ via Services, not Actions (the UNIGES Reporting convention).** The metric reads are `…Service`
  classes (query+map); the one write is an Action (Decision I).
- **The TimePeriod enum DOES get the `enums are string-backed` arch rule (vs Engagement, which had none).**
- **Aggregate reads NEVER `withoutGlobalScope` (the UNIGES "would leak real counts" lesson) — the scope IS
  the org isolation; fail-CLOSED is already in OrganizationScope.**
- **No `encrypted` cast (vs slice-005 Member CURP/RFC); PII handled by SHA-256-hashing the IP at the edge
  and storing ONLY aggregates in props (§10.5).**

## 2. Data model & migrations (reversible, dependency-safe)

> **ID type:** `$table->id()` **bigint** (slice-003 Deviation) — matches every prior CMS id.

### 2a. `page_views` (§6.3.14)

**File:** `database/migrations/2026_06_20_000800_create_page_views_table.php`
(timestamp AFTER `…000700_create_contact_messages` — last data table; FK targets `articles`/`organizations`
already migrated by `…000200`/`…000401`).

```php
Schema::create('page_views', function (Blueprint $table): void {
    $table->id();
    // SET NULL: a view survives its article's hard-delete as an anonymized aggregate (§6.4).
    $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
    // RESTRICT + NOT NULL: a view always belongs to an org (derived from the article at record time).
    $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
    $table->string('ip_hash', 64);        // SHA-256 of (raw IP + APP_KEY salt); raw IP NEVER stored (§10.5)
    $table->string('user_agent', 255)->nullable();
    $table->timestamp('viewed_at');       // the event clock — NO timestamps()/softDeletes (append-only)

    $table->index('article_id');
    $table->index('organization_id');
    $table->index('viewed_at');
    $table->index(['article_id', 'ip_hash', 'viewed_at']);   // the 24h dedup composite (§6.3.14)
});
// down(): Schema::dropIfExists('page_views');
```

Indexes per §6.3.14: `article_id`, `organization_id`, `viewed_at`, `[article_id, ip_hash, viewed_at]`. NO
`timestamps()`/`softDeletes()` — append-only event data; `viewed_at` is the SSOT clock.

### 2b. `daily_snapshots` (§6.3.13 — SHIP table+model, populating job DEFERRED — Decision F option (i))

**File:** `database/migrations/2026_06_20_000801_create_daily_snapshots_table.php`

```php
Schema::create('daily_snapshots', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
    $table->date('date');
    $table->integer('articles_count')->default(0);
    $table->integer('jobs_count')->default(0);
    $table->integer('members_count')->default(0);
    $table->integer('contact_messages_count')->default(0);
    $table->integer('page_views_count')->default(0);
    $table->timestamps();
    $table->unique(['organization_id', 'date']);   // §6.3.13 unique composite
});
// down(): Schema::dropIfExists('daily_snapshots');
```

> The table + model SHIP (schema completeness, §6.3.13 honored, zero runtime cost). The MVP dashboard reads
> LIVE aggregates off the source tables; the nightly `RollDailySnapshotsJob` that upserts these rows is
> DEFERRED to a scaling phase (spec §"Out of scope"). **If the human prefers the leaner Decision-F option
> (ii)** (defer the whole table), DROP this migration + the model + its factory — the dashboard is
> unaffected (it never reads from snapshots in the MVP).

### `articles.views_count` — NO new migration

The column already exists (`…000200_create_articles_table` line 36:
`$table->unsignedBigInteger('views_count')->default(0)`), cast `'views_count' => 'integer'` on the Article
model. This slice WIRES the increment (the slice-002 hand-off) — no schema change to `articles`.

## 3. Enum — `TimePeriod` (Decision: this slice HAS an enum; the arch rule applies)

`app/Domain/Analytics/Enums/TimePeriod.php` — `final`, backed `: string`, no magic strings:

```php
enum TimePeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function truncUnit(): string   // the Postgres date_trunc unit — NO magic string in the query
    {
        return $this->value;              // 'day' | 'week' | 'month' map 1:1 to date_trunc units
    }

    public function labelKey(): string    // server i18n key (lang/*.json)
    {
        return "analytics.period.{$this->value}";
    }

    public static function default(): self
    {
        return self::Month;
    }
}
```
`#[TypeScript]` on it (type-only import in the page). The `date_trunc($period->truncUnit(), …)` call uses the
enum, never a literal.

## 4. Models

### 4a. `app/Domain/Analytics/Models/PageView.php`
`final`, `HasFactory`, **NO `SoftDeletes`**, **NO `timestamps`** (`public $timestamps = false;` — the table
has only `viewed_at`, no `created_at`/`updated_at`). `booted()` registers
`self::addGlobalScope(new OrganizationScope)` (the dashboard aggregates over it confined). Casts: `'viewed_at'
=> 'datetime'`. Full `@property` PHPDoc for PHPStan L10:
`@property int $id`, `@property int|null $article_id`, `@property int $organization_id`, `@property string
$ip_hash`, `@property string|null $user_agent`, `@property \Illuminate\Support\Carbon $viewed_at`;
`@property-read Article|null $article` (SET NULL → **nullable**), `@property-read Organization $organization`
(RESTRICT NOT NULL → never `|null`).

```php
public $timestamps = false;

protected $fillable = ['article_id', 'organization_id', 'ip_hash', 'user_agent', 'viewed_at'];

protected function casts(): array
{
    return ['viewed_at' => 'datetime'];
}

protected static function booted(): void
{
    self::addGlobalScope(new OrganizationScope);
}
```
Relations: `article()` (`belongsTo` Article — nullable), `organization()` (`belongsTo` Organization).
`newFactory()` → `PageViewFactory::new()`. Cross-domain MODEL refs `Content\Models\Article` +
`Organization\Models\Organization` (arch-allow-listed).

### 4b. `app/Domain/Analytics/Models/DailySnapshot.php` (if Decision-F option (i))
`final`, `HasFactory`, `OrganizationScope` in `booted()`, casts `'date' => 'date'` + the five counts
`'integer'`. `@property` PHPDoc (`@property-read Organization $organization` non-null). `$fillable` = the org
id, date, and five counts. Relation `organization()` belongsTo. `newFactory()` → `DailySnapshotFactory::new()`.

## 5. Validation — the filter DTO (Spatie Data, `#[TypeScript]`)

`app/Domain/Analytics/Data/AnalyticsFilterData.php` — `final`, `readonly`, the ADMIN dashboard filter; ALL
optional/nullable (the dashboard renders unfiltered by default):

```php
#[Nullable, Exists('organizations', 'id')] public ?int $organization_id,
#[Nullable, Exists('branches', 'id')]      public ?int $branch_id,
#[Nullable, Exists('categories', 'id')]    public ?int $category_id,
#[Nullable] public ?TimePeriod $period,     // null → TimePeriod::default() (Month) in the services
```
NO server-owned write field (READ-ONLY dashboard — nothing to forge). `#[TypeScript]` generates the filter
shape. The PUBLIC tracking path has NO DTO (Decision A — server-derived record).

## 6. Actions (`DB::transaction`, Illuminate\Http-free)

`app/Domain/Analytics/Actions/RecordPageViewAction.php` — the SOLE write; cheap, race-safe, deduped:

```php
final class RecordPageViewAction
{
    private const DEDUP_HOURS = 24;

    public function handle(Article $article, string $ipHash, ?string $userAgent): void
    {
        // 24h dedup (§3.7 ANALYTICS-01) — ONE indexed EXISTS on [article_id, ip_hash, viewed_at].
        // Scope-free is NOT needed here: this runs on the PUBLIC path which is UNCONFINED, so the
        // OrganizationScope is a no-op; the EXPLICIT article_id + ip_hash predicate IS the dedup.
        $alreadyViewed = PageView::query()
            ->where('article_id', $article->id)
            ->where('ip_hash', $ipHash)
            ->where('viewed_at', '>=', now()->subHours(self::DEDUP_HOURS))
            ->exists();

        if ($alreadyViewed) {
            return;   // no-op — neither the increment nor the insert fire (dedup, BOTH effects gated together)
        }

        DB::transaction(function () use ($article, $ipHash, $userAgent): void {
            // Atomic UPDATE … views_count = views_count + 1 — race-safe (NEVER read-modify-write).
            $article->increment('views_count');

            PageView::query()->create([
                'article_id' => $article->id,
                'organization_id' => $article->organization_id, // server-derived from the article (no payload)
                'ip_hash' => $ipHash,
                'user_agent' => $userAgent,
                'viewed_at' => now(),
            ]);
        });
    }
}
```
> `$article->organization_id` is `int|null` on the Article (the slice-002 org retrofit made it nullable —
> Deviation C). `page_views.organization_id` is NOT NULL. **EDGE:** if an article has a null
> `organization_id` (a legacy/unscoped row), the insert would violate NOT NULL. Plan resolves: the PUBLIC
> `show` only serves PUBLISHED articles, which in practice carry an org; BUT to be safe the Action GUARDS —
> if `$article->organization_id === null`, it records NO page_view (return early) but STILL increments
> `views_count` (the column bump is org-agnostic). **LOCK this guard** so a null-org article never 500s the
> insert. (The increment is the universal counter; the page_views row needs an org — skip the row, keep the
> count.) Confirm Article.organization_id nullability during implement and keep the guard.

The Action MAY throw a real DB fault; the CALLER swallows it (Decision B). It is pure/testable — the
dedup/increment tests call `handle` directly.

## 7. The increment SITE — `Public\ArticleController@show` (NEWS-04 wiring, Decision C)

Edit the slice-002 `show` to record AFTER resolving the published article, fail-soft:

```php
public function show(string $article, RecordPageViewAction $record): Response
{
    $article = Article::query()
        ->withoutGlobalScope(OrganizationScope::class)
        ->where('slug', $article)
        ->where('status', ArticleStatus::Published)
        ->whereNotNull('published_at')
        ->with(['category:id,name', 'author:id,name'])
        ->firstOrFail();

    // NEWS-04 (the slice-002 DEFERRED views_count, wired). Fail-soft: a tracking fault NEVER
    // 500s the article render (Decision B). IP is SHA-256-hashed (+ APP_KEY salt) — raw IP NEVER stored (§10.5).
    try {
        $record->handle(
            $article,
            hash('sha256', request()->ip().config('app.key')),
            substr((string) request()->userAgent(), 0, 255),
        );
    } catch (\Throwable $e) {
        Log::warning('page_view tracking failed', ['article_id' => $article->id, 'exception' => $e->getMessage()]);
    }

    return Inertia::render('Articles/Show', [ /* …existing snake_case prop contract, unchanged… */ ]);
}
```
> Still anemic: the real logic is the existing lookup + render + one guarded Action call. `request()->ip()`
> / `request()->userAgent()` are used in the CONTROLLER (allowed — controllers may touch the request via the
> `request()` helper; the arch rule forbids `Illuminate\Http\Request` as a TYPE-HINT/import, not the helper).
> The hash is computed in the controller so the Action receives a plain `string $ipHash` (the Action stays
> Http-free + PII-free of the raw IP). **The `config('app.key')` salt** makes the hash deployment-stable (the
> 24h dedup needs the same input→same hash) without being a global rainbow-table target.

## 8. Metric Services (`App\Domain\Analytics\Services` — read-only, ONE grouped query per metric, mixed-cast-safe, scope-respecting — Decision I)

> Mirror UNIGES `TerminalEfficiencyService`: `selectRaw` aggregates, `count(*) filter (where … = ?)` with
> PARAMETERIZED enum bindings, `date_trunc(?, …)` time buckets, a private `toInt(mixed): int` guard, NEVER
> `withoutGlobalScope`. Each public method = ONE query.

### 8a. `ArticleAnalyticsService`
```php
public function totalViews(): int
{
    // ONE scoped aggregate. sum() over the org-scoped Article — a confined admin sums only their org.
    return $this->toInt(Article::query()->sum('views_count'));
}

/** @return list<array{id:int,title:string,views_count:int}> */
public function topArticlesByViews(int $limit = 5): array
{
    return Article::query()
        ->orderByDesc('views_count')
        ->limit($limit)
        ->get(['id', 'title', 'views_count'])
        ->map(fn (Article $a): array => [
            'id' => (int) $a->id,
            'title' => $a->title,
            'views_count' => (int) $a->views_count,
        ])->all();
}

/** @return list<array{bucket:string,total:int}> */
public function viewsOverTime(TimePeriod $period, ?int $articleId, ?int $categoryId): array
{
    // ONE grouped query over the org-scoped PageView. date_trunc unit from the enum (no magic string).
    // category filter joins articles (the page_view's article's category).
    return PageView::query()
        ->when($articleId !== null, fn ($q) => $q->where('page_views.article_id', $articleId))
        ->when($categoryId !== null, fn ($q) => $q
            ->join('articles', 'articles.id', '=', 'page_views.article_id')
            ->where('articles.category_id', $categoryId))
        ->selectRaw("date_trunc(?, viewed_at)::date as bucket", [$period->truncUnit()])
        ->selectRaw('count(*) as total')
        ->groupBy('bucket')
        ->orderBy('bucket')
        ->get()
        ->map(fn ($row): array => [
            'bucket' => (string) $row->getAttribute('bucket'),
            'total' => $this->toInt($row->getAttribute('total')),
        ])->all();
}
```
> NOTE the `branch_id` filter (§3.7 "filter by … branch"): a `PageView` has no `branch_id`; filter via the
> article's branch — `->join('articles', …)->where('articles.branch_id', $branchId)` when `branch_id` is
> present (same join shape as the category filter). Plan adds the `?int $branchId` param symmetrically.

### 8b. `EngagementAnalyticsService`
```php
/** @return array{active:int,closed:int} */
public function jobsActiveVsClosed(): array
{
    // ONE query, two filtered counts — parameterized enum bindings (no magic strings).
    $row = JobPosting::query()
        ->selectRaw('count(*) filter (where status = ?) as active', [JobStatus::Active->value])
        ->selectRaw('count(*) filter (where status = ?) as closed', [JobStatus::Closed->value])
        ->first();

    return [
        'active' => $row === null ? 0 : $this->toInt($row->getAttribute('active')),
        'closed' => $row === null ? 0 : $this->toInt($row->getAttribute('closed')),
    ];
}

/** @return list<array{bucket:string,total:int}> */
public function memberRegistrationTrend(TimePeriod $period): array
{
    return Member::query()
        ->selectRaw('date_trunc(?, created_at)::date as bucket', [$period->truncUnit()])
        ->selectRaw('count(*) as total')
        ->groupBy('bucket')->orderBy('bucket')
        ->get()->map(fn ($r): array => [
            'bucket' => (string) $r->getAttribute('bucket'),
            'total' => $this->toInt($r->getAttribute('total')),
        ])->all();
}

/** @return list<array{bucket:string,total:int}> */
public function contactMessagesPerPeriod(TimePeriod $period): array
{ /* identical shape over ContactMessage.created_at */ }
```
Each service has its own private `toInt(mixed): int` (`is_numeric($v) ? (int) $v : 0`) + `toStringValue`.
All five source models are org-scoped → every aggregate is confined automatically. **NEVER
`withoutGlobalScope`.**

## 9. Controller + route

`app/Http/Controllers/Admin/AnalyticsController.php` (anemic, index ONLY):
```php
public function index(
    AnalyticsFilterData $filters,
    ArticleAnalyticsService $articles,
    EngagementAnalyticsService $engagement,
): Response {
    $period = $filters->period ?? TimePeriod::default();

    return Inertia::render('Admin/Analytics/Index', [
        'metrics' => [
            'total_views' => $articles->totalViews(),
            'top_articles' => $articles->topArticlesByViews(5),
            'views_over_time' => $articles->viewsOverTime($period, null, $filters->category_id),
            'jobs' => $engagement->jobsActiveVsClosed(),
            'member_trend' => $engagement->memberRegistrationTrend($period),
            'contact_trend' => $engagement->contactMessagesPerPeriod($period),
        ],
        'filters' => [
            'organization_id' => $filters->organization_id,
            'branch_id' => $filters->branch_id,
            'category_id' => $filters->category_id,
            'period' => $period->value,
        ],
        'options' => [ /* org/branch/category selects — see §10 */ ],
    ]);
}
```
> The global `OrganizationScope` confines an administrator automatically. The `?organization_id=` filter only
> narrows an UNCONFINED super_admin (Decision D) — for a confined administrator it is a no-op. If the
> `organization_id` filter is to actively narrow a super_admin, apply it inside the services via a
> `->where('organization_id', …)` when present — plan threads `$filters->organization_id` into each service
> method OR (simpler) sets the OrganizationContext from the filter for a super_admin; **plan picks threading
> an explicit `?int $organizationId` filter param into the services** (no context mutation in a controller —
> keeps the scope spine clean). Lock during implement.

**Route** (`routes/web.php`) — gate at `role:administrator` + `org.scope` (Decision E):
```php
Route::middleware(['auth', 'role:administrator', 'org.scope'])->group(function (): void {
    // …existing municipalities/directors…
    Route::get('/admin/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics.index');
});
```
> Joins the EXISTING `role:administrator` + `org.scope` group (alongside municipalities/directors). The
> `/admin/analytics/export` route is DEFERRED (spec §"Out of scope"). The PUBLIC tracking rides the existing
> `GET /articles/{article}` — NO new public route.

## 10. Inertia page + prop contract (snake_case)

`resources/js/Pages/Admin/Analytics/Index.tsx` — admin-shell READ-ONLY dashboard. Total-views stat card;
top-5-articles table; views-over-time + member-trend + contact-trend bar series (minimal SVG/CSS — Decision
G, no charting lib); jobs active-vs-closed mini-stat; the filter form (org [super_admin only] / branch /
category / period selects that GET-reload with query params via `router.get(route('admin.analytics.index'),
{...})`). Magenta theme, dark/light, bilingual via the locale hook. `TimePeriod` TS enum imported **type-only**.

**Exact prop shape** (the CONTRACT — the prop-contract test pins it):
```
metrics: {
  total_views: number;
  top_articles: Array<{ id: number; title: string; views_count: number }>;
  views_over_time: Array<{ bucket: string; total: number }>;
  jobs: { active: number; closed: number };
  member_trend: Array<{ bucket: string; total: number }>;
  contact_trend: Array<{ bucket: string; total: number }>;
};
filters: {
  organization_id: number | null;
  branch_id: number | null;
  category_id: number | null;
  period: 'day' | 'week' | 'month';
};
options: {
  organizations: Array<{ id: number; name: string }>;   // populated only for super_admin (empty for a confined admin)
  branches: Array<{ id: number; name: string }>;
  categories: Array<{ id: number; name: string }>;
};
```
> `options.organizations` is non-empty only for an unconfined super_admin (a confined administrator is
> org-pinned — the org select is hidden/empty). The branch/category option lists are reference data read once
> (no N+1). The dashboard surfaces ONLY aggregates — never an individual `ip_hash`/`user_agent` (PII).

## 11. Factories + seed (FICTIONAL — PII-safe)

`database/factories/PageViewFactory.php`: default `article_id` via `Article::factory()` + `organization_id`
from that article (consistent pair); `ip_hash` = `hash('sha256', fake()->ipv4())` (a HASH of a faker IP — no
real PII, no raw IP); `user_agent` = `fake()->userAgent()` truncated 255; `viewed_at` = `fake()
->dateTimeBetween('-60 days', 'now')`. States: `forArticle(Article)` (article_id + its organization_id),
`viewedAt(CarbonInterface)`, `dedupClash(PageView $other)` (same `article_id`/`ip_hash`, `viewed_at` within
24h of `$other` — for the dedup test). `DailySnapshotFactory` (if shipped): faker counts, a unique
`[organization_id, date]`. Seed a handful of FICTIONAL page_views (and bump a few articles' `views_count`
consistently) so the demo dashboard renders non-empty. **Scan for real PII before commit (the ip_hash is a
hash of a FAKER ip — never a real one).**

## 12. The Delete Actions — confirm Article delete mode (Decision H)

`page_views.article_id` is FK **SET NULL** (§6.4). Plan reads the Article model + `DeleteArticleAction`:
- If Article **soft-deletes** (likely — it has `deleted_at` per §6.3.2 / the slice-002 model): a delete is an
  `UPDATE deleted_at`, NOT a SQL `DELETE`, so the SET-NULL never fires and page_views are UNTOUCHED (they
  keep pointing at the now-trashed article). ⇒ NO Delete Action change, NO new cross-domain edge. The
  regression test asserts: deleting an article with page_views succeeds (302, no 500) and the page_views
  survive with `article_id` intact.
- If Article **hard-deletes** (or has a force-delete path that page_views must survive): the DB SET-NULL
  anonymizes the page_views (`article_id` → null, `organization_id` intact) automatically — still NO
  app-level cascade needed (the FK does it). The regression asserts the `article_id` is null post-delete and
  the views survive.
**Confirm the actual mode during implement and write the matching assertion. Likely NO Delete Action edit and
NO arch allow-list change** (the FK SET NULL is DB-level; Content's DeleteArticleAction does not need to
reference `Analytics\Models\PageView`). If, during implement, a Delete Action IS found to need a PageView
reference, ADD the arch allow-list edge to the Content rule + the regression test (the slice-004 lesson).

## 13. i18n keys (BOTH lang/es.json + lang/en.json — server side)

NEW server keys (the `TimePeriod::labelKey()` values):
`analytics.period.day`, `analytics.period.week`, `analytics.period.month`.
Suggested copy:
- ES: `día` / `semana` / `mes` · EN: `Day` / `Week` / `Month`.
A lang-key resolution test asserts each exists in BOTH `lang/es.json` + `lang/en.json`. The DASHBOARD UI copy
(card titles, axis labels, "Top 5 articles", "Views over time", "Active vs closed", "Registration trend") goes
in `resources/locales/{es,en}` (frontend `t()`), NOT `lang/*.json`.

## 14. Tests (the dedup+atomic-increment, the single-query assertion, and the org-isolation crown LAST)

Under `tests/Feature/Analytics/` (no Unit tests beyond a tiny `TimePeriod` enum unit test for `truncUnit()`/
`default()` — that one is pure, goes in `tests/Unit/Analytics/`; everything touching `__()`/DB/the container
is FEATURE). The exact falsifiable list is in the CONTRACT §"Test list". The arch test gets the Analytics
boundary rule (SPEC §11.2 STUB BROADENED — Decision J) + `never depends on HTTP` + `Analytics
actions/data/services are final` + **`Analytics enums are string-backed`** (TimePeriod IS an enum — this rule
APPLIES, unlike Engagement). Confirm during implement which arch edges are actually used (drop unused). The
Content rule's allow-list is UNCHANGED unless Decision H finds a DeleteArticleAction→PageView ref (it should
not).

## 15. Files to touch (summary)

NEW:
`app/Domain/Analytics/Models/PageView.php`,
`app/Domain/Analytics/Models/DailySnapshot.php` (Decision-F option (i)),
`app/Domain/Analytics/Enums/TimePeriod.php`,
`app/Domain/Analytics/Data/AnalyticsFilterData.php`,
`app/Domain/Analytics/Actions/RecordPageViewAction.php`,
`app/Domain/Analytics/Services/ArticleAnalyticsService.php`,
`app/Domain/Analytics/Services/EngagementAnalyticsService.php`,
`app/Http/Controllers/Admin/AnalyticsController.php`,
`database/migrations/2026_06_20_000800_create_page_views_table.php`,
`database/migrations/2026_06_20_000801_create_daily_snapshots_table.php` (Decision-F option (i)),
`database/factories/PageViewFactory.php`,
`database/factories/DailySnapshotFactory.php` (Decision-F option (i)),
`resources/js/Pages/Admin/Analytics/Index.tsx`,
plus the test files (see CONTRACT).

EDIT:
`app/Http/Controllers/Public/ArticleController.php` (the NEWS-04 increment wiring — the fail-soft Action call
in `show`),
`routes/web.php` (admin `GET /admin/analytics` in the `role:administrator` + `org.scope` group),
`lang/es.json` + `lang/en.json` (the 3 `analytics.period.*` keys),
`resources/locales/{es,en}` (the dashboard UI copy),
the demo seeder (fictional page_views + consistent views_count bumps),
`tests/Arch/ArchitectureTest.php` (the Analytics boundary block).

NOT TOUCHED (Decision H — confirm): `app/Domain/Content/Actions/DeleteArticleAction.php`, `bootstrap/app.php`
(no exception render — the tracking path is fail-soft via the controller try/catch).
