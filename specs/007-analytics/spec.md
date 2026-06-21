# Spec 007 — Analytics Domain (public page-view tracking + org-scoped admin analytics dashboard)

> **Phase:** Specify (the WHAT and WHY, not the HOW). **THE LAST CMS DOMAIN.**
> **SSOT:** `SPEC.md` (§3.7 Analytics ANALYTICS-01 [track page views: record article_id, organization_id,
> ip_hash, viewed_at; **1 view per IP per article per 24h, deduplicated**] + ANALYTICS-02 [dashboard
> stats: total views, top-5 articles by views, views-over-time chart; super_admin + administrator] +
> ANALYTICS-03 [segmented: filter by organization/branch/category/time-period; **manager sees only own
> org**]; §3.7 Minimum Viable Metrics [Articles: total views / top-5 / over-time · Jobs: active-vs-closed
> ratio · Members: registration trend · Contact: messages received per period]; §3.3 **NEWS-04**
> ("Increment `views_count` atomically" — the slice-002 DEFERRED column, wired HERE); §6.3.14 `page_views`
> table + §6.3.13 `daily_snapshots` table; §6.4 FK summary [`page_views.article_id` **SET NULL** nullable,
> `page_views.organization_id` **RESTRICT**; `daily_snapshots.organization_id` **RESTRICT**]; §7.5
> `GET /admin/analytics` + `GET /admin/analytics/export`; §10.2 RBAC [**Analytics | super_admin All |
> administrator Own org | manager — | editor —**]; §10.3 `TrackPageViewMiddleware` (public article detail);
> §10.5 PII [**Page view IP → SHA-256 one-way hash, never reversible**]; §11.2 Analytics arch boundary
> [`App\Domain\Analytics` toOnlyUse Shared/Illuminate/Spatie — BROADENED here to the real edges];
> §8.1 `Analytics/Index.tsx`; §1.4/§1.5 magenta theme [`#DD00FF` primary]).
> **Reference impl (same stack — built + reviewed):** the **UNIGES Reporting domain**
> (`omnibus-uniges/app/Domain/Reporting/Services/TerminalEfficiencyService.php`) — THE aggregate /
> grouped-count / `count(*) filter (where … = ?)` / time-bucket (`extract(year …)::int`) pattern with the
> **mixed-cast discipline** (`toInt()` = `is_numeric($v) ? (int) $v : 0`; the integer-rate SSOT;
> ONE grouped query per axis, NEVER `withoutGlobalScope`). This slice's read services mirror it 1:1.
> The org-scoping spine (`App\Support\OrganizationContext` + `App\Support\OrganizationScope` +
> `EnsureOrganizationScope` `org.scope` ordered BEFORE `SubstituteBindings`); **slice-002
> `Public\ArticleController@show`** (where `views_count` is incremented + a page-view recorded — the
> tracking SITE); **slice-005/006 PUBLIC-ANONYMOUS pattern** (the public tracking ping is unconfined +
> minimal-PII + never-500); **slice-006 `Admin\ContactController@index`** (the anemic org-scoped read the
> dashboard controller's structure echoes); the `DashboardController` (the admin-shell entry page).
> **Built on (DO NOT break — slices 001–006 suites are green):** slice 001 Identity (`UserRole` ladder,
> `EnsureRole` `role:<level>`), slice 002 Content (`Article` + its already-migrated `views_count` BIGINT
> DEFAULT 0 column [migration `…000200`], the `Public\ArticleController@show` read site, `ArticleStatus`,
> the org-scope retrofit on `Article`), slice 003 Organization (`Organization`/`Branch`, the org-scoping
> spine, the Delete Actions' restrict-FK pre-checks + application-level cascade), slice 004 Jobs (`JobStatus`
> Active/Closed), slice 005 Membership (`Member` + `status` + `created_at` registration trend), slice 006
> Engagement (`ContactMessage` + `created_at` "messages per period"), the arch suite, the magenta theme +
> `lang/{es,en}.json` + `resources/locales`.
> **Status:** DRAFT — awaiting human review gate before `/sdd-plan` / build.

## 1. Problem & why

Analytics is the CMS's **read-side measurement layer** and the LAST domain. SPEC §3.7 (ANALYTICS-01/02/03),
§6.3.14 (`page_views`), §6.3.13 (`daily_snapshots`), §7.5 (`/admin/analytics`), §10.3
(`TrackPageViewMiddleware`) and §10.5 (IP hashing) define a **two-sided, asymmetric** flow:

1. **ANALYTICS-01 — public WRITE (the cheap tracking ping).** When ANY visitor views a published article
   (`GET /articles/{slug}`, the slice-002 public show), the system **records the view**: it (a) **increments
   `articles.views_count` atomically** (the slice-002 DEFERRED NEWS-04 column, wired HERE) and (b) appends a
   `page_views` row (`article_id`, `organization_id`, `ip_hash`, `user_agent`, `viewed_at`) — **deduplicated
   to 1 view per IP per article per 24h** (§3.7 ANALYTICS-01). This is a HIGH-WRITE, latency-sensitive path
   on an anonymous public page: it must be CHEAP (an atomic increment + a single insert, or a no-op when
   deduped — NEVER a read-modify-write race, NEVER an N+1), carry **NO raw PII** (the IP is **SHA-256
   hashed one-way** before storage — §10.5, never reversible), and **NEVER 500/422 the article render** (a
   tracking failure must not break the page — fail-soft).

2. **ANALYTICS-02/03 — admin READ (the org-scoped dashboard).** An **administrator** (own-org) or
   **super_admin** (all orgs) views `/admin/analytics`: a dashboard of **aggregate metrics** —
   Articles (total views, top-5 by views, views-over-time), Jobs (active-vs-closed ratio), Members
   (registration trend), Contact (messages per period) — each computed by **ONE grouped aggregate query
   per metric** (the UNIGES Reporting pattern), **respecting `OrganizationScope`** so an administrator sees
   ONLY their org's numbers and super_admin sees all. Postgres aggregates arrive MIXED → every bucket is
   guarded (`is_numeric`/`(int)`). The dashboard is **READ-ONLY** (no mutations, no recording from here).

**This slice's whole point — get these right the FIRST time:**

- **The cheap, race-safe, fail-soft public tracking write.** The `views_count` bump uses Eloquent
  `increment()` (a single atomic `UPDATE … SET views_count = views_count + 1` — NOT `$a->views_count++;
  $a->save()`, which is a read-modify-write race that loses concurrent views). The `page_views` insert is a
  single `INSERT` gated by a 24h-dedup EXISTS check on `[article_id, ip_hash, viewed_at]` (the §6.3.14
  dedup index). The whole record path is wrapped so a tracking exception is swallowed (logged, fail-soft —
  the article still renders 200; **never a 500 on a view**). The IP is hashed **before** it ever touches the
  DB; **the raw IP is NEVER stored**.

- **The dedup invariant (ANALYTICS-01).** A 2nd view from the SAME ip_hash for the SAME article within 24h
  records NEITHER a new `page_views` row NOR a `views_count` increment (the increment and the row are
  **gated together** by the same dedup check — one view per IP per article per 24h, BOTH effects deduped
  consistently). A DIFFERENT IP, a DIFFERENT article, or the SAME IP after 24h DOES record.

- **The aggregate-read tenant isolation (the crown).** The dashboard's metric services query the org-scoped
  models (`Article`, `JobPosting`, `Member`, `ContactMessage`, `PageView`) under the global
  `OrganizationScope`, so a confined **administrator** of org A sees ONLY org-A numbers (falsifiable: an
  unscoped aggregate over all orgs exceeds the administrator's visible total). super_admin (unconfined) sees
  all. **NEVER `withoutGlobalScope`** in a metric service (the UNIGES "would leak real counts" lesson) —
  the scope IS the isolation. Fail-CLOSED for a confined-but-org-less viewer (`whereRaw('1=0')` already in
  `OrganizationScope`).

- **The single-query-per-metric performance contract.** Each metric = ONE grouped/filtered aggregate
  (`count(*)`, `count(*) filter (where …)`, `sum(views_count)`, `date_trunc('day'|'week'|'month', …)`),
  NEVER a per-row loop. The dashboard issues a small, fixed number of queries (one per metric + the
  pagination/scope overhead), asserted by a query-count test. The aggregation columns are INDEXED
  (`page_views.viewed_at`, `[article_id, ip_hash, viewed_at]`, `organization_id`; `articles.views_count`
  is read via `sum` over the already-indexed org scope).

- **The PII contract (§10.5).** `page_views.ip_hash` is a **SHA-256 one-way hash** (VARCHAR(64)) — the raw
  IP is hashed at the edge and never persisted; the hash is salted with `APP_KEY` so it is not a global
  rainbow-table target but is stable per-deployment for the 24h dedup window. `user_agent` is stored
  truncated to 255 chars (§6.3.14) for coarse analytics, NO other PII. The dashboard surfaces only
  AGGREGATES (counts/sums/buckets) — never an individual visitor's hash or UA. Fixtures are FICTIONAL.

- **The deferred `views_count` wiring (NEWS-04, the slice-002 hand-off).** Slice 002 added the
  `articles.views_count` BIGINT DEFAULT 0 column and EXPLICITLY left the increment "to the Analytics
  slice". THIS slice wires it: the increment fires on the public `Public\ArticleController@show` (atomic,
  deduped), and `views_count` is SURFACED in the admin analytics dashboard (the "top-5 articles by views"
  metric is `ORDER BY views_count DESC`) and may be surfaced in the admin article list. No schema change to
  `articles` is needed (the column already exists) — only the increment site + the read.

## 2. Scope

### In scope (Analytics MVP — public view-tracking + org-scoped admin dashboard)

1. **`page_views` table + `PageView` model** (SPEC §6.3.14) — `bigint` id (the established slice-003
   Deviation: all CMS ids are bigint, NOT the SPEC's ULID note); `article_id` (**NULLABLE**, FK **SET NULL**
   — a view survives its article's hard-delete as an anonymized aggregate, §6.4); `organization_id`
   (**NOT NULL**, FK **RESTRICT**); `ip_hash` VARCHAR(64) NOT NULL (SHA-256); `user_agent` VARCHAR(255)
   NULLABLE; `viewed_at` TIMESTAMP NOT NULL. **NO `timestamps()`** unless cheap — §6.3.14 lists only
   `viewed_at` (the event time IS `viewed_at`; do not add `created_at`/`updated_at` — the table is
   append-only event data, `viewed_at` is the SSOT clock). **NO `softDeletes()`** (append-only events are
   never soft-deleted). Indexes per §6.3.14: `article_id`, `organization_id`, `viewed_at`,
   `[article_id, ip_hash, viewed_at]` (the dedup composite). Registers `OrganizationScope` in `booted()`
   from birth (the dashboard aggregates over it confined). `final`, `HasFactory`, full `@property` PHPDoc
   (`@property-read Article|null $article` — SET NULL so **nullable**; `@property-read Organization
   $organization` — RESTRICT NOT NULL, never `|null`). `viewed_at` cast to `datetime`. REUSES
   `App\Domain\Content\Models\Article` + `App\Domain\Organization\Models\Organization` cross-domain MODEL
   refs (arch-allow-listed for Analytics).

2. **`daily_snapshots` table + `DailySnapshot` model** (SPEC §6.3.13) — `bigint` id;
   `organization_id` (**NOT NULL**, FK **RESTRICT**); `date` DATE NOT NULL; `articles_count`, `jobs_count`,
   `members_count`, `contact_messages_count`, `page_views_count` all INTEGER DEFAULT 0; `timestamps()`.
   Unique composite index `[organization_id, date]` (§6.3.13). **DEFERRED-BUILD (see Out of scope):** the
   table + model SHIP (schema completeness, the SPEC defines it), but the **aggregation JOB that populates
   it is DEFERRED** — the MVP dashboard reads LIVE aggregates directly off the source tables (cheap enough
   at CMS scale; `daily_snapshots` is a pre-aggregation optimization for a future high-volume phase).
   `final`, `HasFactory`, `OrganizationScope` in `booted()`, `date` cast to `date`, full `@property` PHPDoc.
   **LOCK in plan.md whether to ship the model now or DEFER the whole table** — Decision F.

3. **No new enum for the metrics, but a `TimePeriod` value enum for the dashboard filter** —
   `App\Domain\Analytics\Enums\TimePeriod: string` (`Day`/`Week`/`Month`), backed, with `label()` /
   `labelKey()` + a `truncUnit(): string` helper returning the Postgres `date_trunc` unit (`'day'`/
   `'week'`/`'month'`) for the views-over-time bucket (§3.7 "views over time", ANALYTICS-03 "time period
   day/week/month"). NO magic strings in the `date_trunc` call. The `JobStatus`/`MemberStatus` enums are
   REUSED (active-vs-closed ratio, registration trend) — no new metric enums.

4. **`AnalyticsFilterData` DTO** (Spatie Data, `#[TypeScript]`) — the ADMIN dashboard filter (ANALYTICS-03
   segmentation), ALL fields **nullable/optional** (the dashboard renders with no filter):
   - `organization_id` `#[Nullable, Exists('organizations','id')]` (only meaningful for an UNCONFINED
     super_admin narrowing the view; a confined administrator is already org-pinned by `OrganizationScope`
     — the filter is then a no-op, harmless)
   - `branch_id` `#[Nullable, Exists('branches','id')]` (§3.7 "filter by … branch")
   - `category_id` `#[Nullable, Exists('categories','id')]` (§3.7 "filter by … category")
   - `period` `#[Nullable]` `TimePeriod` (defaults to `Month` in the service if null)
   This DTO carries NO server-owned write field (the dashboard is READ-ONLY — there is nothing to forge).
   The public tracking path has **NO DTO** (it is a middleware/controller-side server-derived record, not a
   user-submitted payload — see Decision A).

5. **`RecordPageViewAction`** (`App\Domain\Analytics\Actions`, Illuminate\Http-free, the SOLE write path) —
   the CHEAP, race-safe, deduped recording op. Signature `handle(Article $article, string $ipHash, ?string
   $userAgent): void`. It:
   - runs the **24h dedup EXISTS check** on `[article_id, ip_hash, viewed_at >= now()-24h]` (the §6.3.14
     dedup index) — if a recent view by this `ip_hash` for this article exists, it is a **no-op** (returns
     early; NEITHER the increment NOR the insert fire);
   - else, in ONE `DB::transaction`: **`$article->increment('views_count')`** (atomic
     `UPDATE … views_count = views_count + 1` — race-safe; NEVER read-then-write) **and** inserts ONE
     `PageView` row (`article_id`, `organization_id` = `$article->organization_id`, `ip_hash`, `user_agent`,
     `viewed_at` = now()).
   - It NEVER throws on a tracking failure that should be fail-soft — the CALLER (the controller/middleware)
     wraps the call in a try/catch that logs + swallows so the article render never 500s (Decision B locks
     WHERE the swallow lives). The `organization_id` is taken from the article (server-derived, never from a
     payload — there is no payload). Returns `void` (a tracking ping has no response body).

6. **The increment SITE — `Public\ArticleController@show` (NEWS-04, the slice-002 hand-off).** The slice-002
   `show` method (which currently 404-guards published-only + renders) calls `RecordPageViewAction` AFTER it
   resolves the published article, passing the article + the SHA-256 `ip_hash` (computed from
   `request()->ip()` salted with `APP_KEY`) + the truncated UA. **DECISION (locked in plan.md, Decision C):
   record via the CONTROLLER call, NOT a separate `TrackPageViewMiddleware`** — the SPEC §10.3 names a
   `TrackPageViewMiddleware`, but the controller already resolves the exact published `Article` (with its
   `organization_id`), so recording there is simpler, avoids a double article lookup, and keeps the
   org-derivation trivial. The §10.3 middleware is noted as an alternative; the controller-call is locked
   for the MVP (a middleware would re-resolve the article — wasteful). The `show` controller stays anemic
   (the record is ONE `try { $action->handle(...) } catch { Log::warning(...) }` block + the existing
   render — still ≤15 lines of real logic; if it would exceed, the try/catch wrapper moves into the Action's
   caller-safe variant — plan picks the cleaner of the two).

7. **`ArticleAnalyticsService` + `EngagementAnalyticsService` (the metric read services)**
   (`App\Domain\Analytics\Services` — mirror UNIGES `TerminalEfficiencyService`, ONE grouped aggregate per
   metric, mixed-cast-guarded, scope-respecting, NEVER `withoutGlobalScope`):
   - **`ArticleAnalyticsService`** — (a) `totalViews(): int` = `Article::sum('views_count')` (scoped;
     guarded `toInt`); (b) `topArticlesByViews(int $limit = 5): list<array{id,title,views_count}>` =
     `Article::orderByDesc('views_count')->limit(5)->get(['id','title','views_count'])` mapped to snake_case
     (scoped — top-5 of the VIEWER's org); (c) `viewsOverTime(TimePeriod $period, ?int $articleId,
     ?int $categoryId): list<array{bucket,total}>` = ONE grouped query over `PageView` (scoped),
     `date_trunc($period->truncUnit(), viewed_at)` bucket + `count(*)` total, optionally filtered by
     article/category, ordered by bucket; mixed-cast every bucket.
   - **`EngagementAnalyticsService`** — (a) `jobsActiveVsClosed(): array{active:int,closed:int}` = ONE
     `count(*) filter (where status = ?)` query over `JobPosting` (scoped; bindings are `JobStatus::Active
     ->value` / `JobStatus::Closed->value` — parameterized, no magic strings); (b)
     `memberRegistrationTrend(TimePeriod $period): list<array{bucket,total}>` = ONE grouped
     `date_trunc(…, created_at)` + `count(*)` over `Member` (scoped); (c)
     `contactMessagesPerPeriod(TimePeriod $period): list<array{bucket,total}>` = ONE grouped
     `date_trunc(…, created_at)` + `count(*)` over `ContactMessage` (scoped). Every aggregate guarded.
   - A private `toInt(mixed): int` (`is_numeric ? (int) : 0`) on each service (the UNIGES helper, copied —
     NOT shared, to keep each service self-contained / arch-clean) and a `toStringValue(mixed): string` for
     the article title. **Catalog option lists** (organizations/branches/categories for the filter selects)
     come from a small `filterOptions()` method on one service OR the controller's existing catalog reads —
     plan picks the smallest (org/branch/category lists are reference data, read once, no N+1).

8. **`Admin\AnalyticsController`** (anemic, ≤15 lines/method) — `index(AnalyticsFilterData $filters,
   ArticleAnalyticsService $articles, EngagementAnalyticsService $engagement): Response` only (SPEC §7.5
   gives `/admin/analytics` `index`; `export` is DEFERRED — see Out of scope). It calls each metric method
   ONCE (one grouped query each), assembles the exact snake_case prop shape (§10 below), and renders
   `Admin/Analytics/Index`. The global `OrganizationScope` confines an administrator automatically (no manual
   `where`); the optional `?organization_id=` only narrows an UNCONFINED super_admin (Decision D). NO
   mutations.

9. **Routes** (SPEC §7.5) — admin `GET /admin/analytics` (`admin.analytics.index`). **Gate: `['auth',
   'role:administrator','org.scope']`** (Decision E — resolving the §7.5/§10.2/§3.7 tension below). The
   PUBLIC tracking has **NO new route** — it rides the EXISTING `GET /articles/{article}` (slice-002), so
   no public Analytics route ships. The `export` route is DEFERRED. All `->name()`d, no closures.

10. **`Admin/Analytics/Index.tsx` page** (§8.1) — the admin-shell READ-ONLY dashboard: total-views stat
    card, a top-5-articles table, a views-over-time chart, a jobs active-vs-closed mini-stat, a
    member-registration-trend chart, a contact-messages-per-period chart, and the segmentation filter
    (org [super_admin only] / branch / category / period selects that GET-reload the page with query
    params). Reuse an existing chart/table component if one exists; else a minimal SVG/CSS bar series (NO
    heavy charting dependency this slice — Decision G). Magenta theme (`#DD00FF`), dark/light, bilingual via
    the locale hook (`resources/locales`). Snake_case props; a prop-contract test pins the shape. The
    generated `TimePeriod` TS enum is imported **type-only**.

11. **`PageViewFactory`** (+ `DailySnapshotFactory` if the model ships) — **FICTIONAL data only** (faker
    `ip_hash` = `hash('sha256', fake()->ipv4())`, faker UA, a `viewed_at` within a recent window;
    `article_id`/`organization_id` via a consistent `Article::factory()` pair so the view's org matches its
    article's org). States: `forArticle(Article)` (sets `article_id` + `organization_id` from the article),
    `viewedAt(CarbonInterface)`, `dedupClash(PageView)` (same ip_hash/article within 24h — for the dedup
    test). Add a few FICTIONAL page-views to the demo seeder so the dashboard renders non-empty. **NO real
    IPs/PII — the ip_hash is a hash of a faker IP. Scan for PII before commit.**

12. **i18n** — every `__()` key in BOTH `lang/es.json` AND `lang/en.json` (server side). This slice's
    SERVER `__()` keys are minimal (the tracking path is silent — no flash; the dashboard renders data, not
    messages). Any server-side label key (e.g. a `TimePeriod::labelKey()` value
    `analytics.period.day`/`week`/`month`) ships in BOTH `lang/*.json` with a resolution test. The DASHBOARD
    UI copy (titles, axis labels, "Top 5 articles", "Views over time") lives in `resources/locales/{es,en}`
    (frontend `t()`), NOT `lang/*.json`. A lang-key resolution test covers the server keys.

13. **Tests** (the falsifiable list is the CONTRACT §"Test list"):
    - **the atomic increment on a public view** (a `GET /articles/{slug}` of a published article bumps that
      article's `views_count` by exactly 1 AND lands one `page_views` row with the article's org + a hashed
      ip + viewed_at; the increment is reflected when re-read);
    - **race-safety** (the increment uses `increment()` — assert via a concurrent-ish double call within the
      same request path that two DISTINCT-IP views land `views_count = 2`, no lost update; a read-modify-write
      impl would be flagged by the dedup-and-count assertions);
    - **the 24h dedup** (a 2nd view from the SAME ip_hash for the SAME article within 24h records NEITHER a
      new `page_views` row NOR a 2nd increment — `views_count` stays 1, one row; a DIFFERENT ip_hash OR the
      same ip after 24h DOES record — `views_count = 2`, two rows);
    - **the raw IP is NEVER stored** (the persisted `ip_hash` is a 64-char SHA-256 hash, NOT the dotted IP;
      no column holds the raw IP);
    - **the public ping NEVER 500s** (a forced tracking failure — e.g. the Action throws — still renders the
      article 200, the failure is logged + swallowed, fail-soft);
    - **aggregate correctness for a seeded scenario** (a fixed set of articles/views/jobs/members/contacts →
      the dashboard's total_views, top-5 ordering, jobs active/closed counts, and the over-time buckets match
      hand-computed expected values — including the mixed-cast guard: a 0-row bucket is `0`, never null);
    - **the single-query / no-N+1 assertion** (`DB::listen`/`assertQueryCount`: each metric is ONE grouped
      query — the dashboard issues a small fixed number of queries regardless of row count; a per-row loop
      would blow the count and FAIL);
    - **role gating** (guest → login; **editor → 403**, **manager → 403** [§10.2 manager `—`];
      administrator/super_admin → 200);
    - **org isolation (the crown — falsifiable):** a confined **administrator** of org A sees ONLY org-A
      numbers (total_views / top-5 / counts) — falsifiable against the unscoped aggregate
      (`PageView::withoutGlobalScope(OrganizationScope::class)->count()` and the all-orgs `sum(views_count)`
      EXCEED the administrator's visible totals); a super_admin sees ALL; the `?organization_id=` filter
      narrows the super_admin view;
    - **PII handling** (the dashboard surfaces ONLY aggregates — no individual ip_hash/UA in any prop; the
      `page_views` fixtures carry hashed-faker ips, no real PII);
    - **the prop-contract test** on `Admin/Analytics/Index` (the exact snake_case shape);
    - **the lang-key resolution** test (the server `analytics.period.*` keys exist in BOTH files);
    - **the arch boundary** (`App\Domain\Analytics` toOnlyUse the exact edges locked in plan.md — Shared,
      App\Support, Content\Models [Article], Organization\Models [Organization/Branch], Jobs\Models
      [JobPosting], Membership\Models [Member], Engagement\Models [ContactMessage], Illuminate, Spatie,
      Database\Factories, Carbon; `->ignoring(['__','now','request'])`) + the `never depends on HTTP` guard +
      `Analytics actions/data/services are final` + `Analytics enums are string-backed` (TimePeriod IS an
      enum — UNLIKE Engagement, this rule APPLIES here);
    - **the cross-slice SET-NULL regression** (deleting an article that has page_views: the article
      hard-delete [if any] SET-NULLs the `page_views.article_id` and the views survive anonymized — OR, if
      Article only soft-deletes, the page_views are untouched; plan confirms Article's delete mode and the
      correct assertion — Decision H).

### Out of scope / DEFERRED (with notes)

- **`/admin/analytics/export` (§7.5).** The SPEC lists an `export` route, but the MVP ships the dashboard
  READ only. **DEFERRED — a follow-on CSV/Excel export Action (the UNIGES `maatwebsite/excel` pattern); add
  `GET /admin/analytics/export` + an export Action when needed.** Noted in §7.5.
- **The `daily_snapshots` AGGREGATION JOB (pre-aggregation / the `analytics` queue, §6.3.13, the
  `TableArchitecture` §1423 `Queue: analytics aggregation`).** The MVP reads LIVE aggregates off the source
  tables (cheap at CMS scale — a few `count`/`sum`/`date_trunc` queries). The nightly job that rolls
  per-org daily counts into `daily_snapshots` is a high-volume optimization. **DEFERRED — the table + model
  may SHIP for schema completeness (Decision F), but the populating job + reading FROM the snapshot is
  DEFERRED to a scaling phase. Note: when added, it is a scheduled `RollDailySnapshotsJob` on the
  `analytics` queue that upserts `[organization_id, date]` rows.**
- **`TrackPageViewMiddleware` as a separate middleware (§10.3).** The MVP records via the controller call
  (Decision C — the controller already has the resolved published article + org). **DEFERRED/SUPERSEDED — if
  a future need arises to track non-article public pages, extract a middleware; for the article-view MVP the
  controller call is simpler and avoids a double lookup.**
- **A real-time / streaming analytics pipeline (ClickHouse / a columnar store / event streaming / a BI
  warehouse).** The global vault's `analytics-data.md` (ClickHouse/columnar) governs the FUTURE Titan
  project — **this CMS is Postgres-only.** Live Postgres aggregates + an optional `daily_snapshots`
  pre-aggregation are the contract. **DEFERRED — explicitly out of this stack (note for Titan, ch. 4.7).**
- **Per-visitor / session analytics, funnels, bounce rate, geo/IP-geolocation, device breakdown beyond the
  coarse `user_agent` string.** §3.7 defines aggregate content/job/member/contact metrics only. **DEFERRED.**
- **Encrypting / further-anonymizing `user_agent`.** §10.5 hashes only the IP; the UA is stored truncated
  (§6.3.14) for coarse analytics, surfaced only in aggregate (never per-row in a prop). **No change —
  §6.3.14 is the contract.**
- **A bot/crawler filter on the tracking ping.** §3.7 dedups by IP/24h; no bot exclusion is specified.
  **DEFERRED — a UA-based bot filter is a future refinement; the 24h dedup is the §3.7 abuse control.**
- **Rate-limiting the public view path.** The tracking ping rides the existing public `GET /articles/{slug}`
  (a read), deduped 1/IP/24h at the DB — there is no separate POST to throttle. **DEFERRED — the dedup IS
  the write-amplification control; add a `throttle` on the article read only if abuse is observed.**
- **ETL of legacy view-count data.** No legacy analytics mapping in §12. Greenfield. **DEFERRED to §12.**

## 3. Acceptance scenarios (When… Then…)

**ANALYTICS-01 — Public view tracking (the cheap, deduped, fail-soft WRITE)**
- When ANY visitor GETs `/articles/{slug}` of a PUBLISHED article, Then `articles.views_count` is
  incremented by exactly 1 (atomic) AND one `page_views` row is created (`article_id`, the article's
  `organization_id`, a 64-char SHA-256 `ip_hash`, the truncated `user_agent`, `viewed_at` = now()), and the
  article still renders 200.
- **Dedup:** When the SAME `ip_hash` GETs the SAME article AGAIN within 24h, Then NEITHER a new `page_views`
  row NOR a 2nd increment occurs (`views_count` stays 1, one row). When a DIFFERENT `ip_hash` views, OR the
  SAME `ip_hash` views after 24h, Then a new row + increment DO occur (`views_count = 2`).
- **No raw PII:** When a view is recorded, Then the stored `ip_hash` is a SHA-256 hash, NOT the raw dotted
  IP; the raw IP is NEVER persisted.
- **Fail-soft:** When the recording fails (the Action throws), Then the article STILL renders 200, the
  failure is logged, and NO 500/422 reaches the visitor.
- **Race-safety:** When two DISTINCT-IP views of the same article occur, Then `views_count = 2` (no lost
  update — the atomic `increment()`, never a read-modify-write).

**ANALYTICS-02/03 — Admin dashboard (the org-scoped aggregate READ, the crown)**
- When an **administrator** of org A GETs `/admin/analytics`, Then the metrics show ONLY org-A numbers
  (total_views, top-5 articles, jobs active/closed, member trend, contact trend) — falsifiable: the
  all-orgs unscoped aggregates EXCEED the administrator's visible totals.
- When a **super_admin** GETs `/admin/analytics`, Then ALL orgs' numbers appear (unconfined); the optional
  `?organization_id=` filter narrows the unconfined view to one org.
- **Segmentation:** When the dashboard is filtered by `branch_id`/`category_id`/`period`
  (day/week/month), Then each metric recomputes scoped to that filter (e.g. views-over-time buckets by the
  chosen period; the over-time series narrows to the chosen category/article).
- **Aggregate correctness:** For a fixed seeded scenario, Then total_views = the sum of the scoped articles'
  `views_count`, top-5 = the 5 highest-`views_count` scoped articles in DESC order, jobs active/closed = the
  scoped `count(*) filter` per status, and each over-time bucket = the scoped `count(*)` for that
  `date_trunc` window (a 0-row window is `0`, never null).
- **Single query per metric:** When the dashboard renders, Then each metric is ONE grouped query (no
  per-row N+1) — a query-count assertion holds regardless of row volume.
- The dashboard is READ-ONLY: there is no recording, mutation, or destructive control on it.

**Role gating (the §7.5/§10.2/§3.7 tension, RESOLVED — Decision E)**
- When a guest hits `/admin/analytics`, Then 302 → login.
- When an authenticated **editor** OR **manager** reaches `/admin/analytics`, Then a **403** (§10.2:
  Analytics manager `—`, editor `—` — the route group is `role:administrator`).
- When an **administrator** (own-org) or **super_admin** (all) reaches `/admin/analytics`, Then 200.

**PII safety**
- The dashboard surfaces ONLY aggregates (counts/sums/buckets) — never an individual `ip_hash` or
  `user_agent` in any prop. There is NO public route that returns analytics data.

**Cross-slice article-delete regression (Decision H)**
- When an article with page_views is deleted, Then per Article's delete mode: the `page_views.article_id`
  SET-NULLs (the view survives anonymized, `organization_id` intact) if Article hard-deletes, OR the
  page_views are untouched if Article soft-deletes — no 500 either way (plan confirms + the test asserts the
  correct branch).

## 4. Non-goals / invariants restated

- Domain code never imports `Illuminate\Http`; the cross-domain MODEL refs (`Content\Models\Article`,
  `Organization\Models\{Organization,Branch}`, `Jobs\Models\JobPosting`, `Membership\Models\Member`,
  `Engagement\Models\ContactMessage`) are OK (FK/aggregate MODEL refs, NOT Action calls); `App\Support`
  (OrganizationScope) is OK. The IP hashing uses `hash('sha256', …)` (an Illuminate/PHP function) — allowed.
- Controllers anemic (≤15 lines/method): the admin `index` is DTO+services→render; the public `show`
  records via a single guarded Action call + the existing render (Decision C — if the try/catch pushes it
  over, the swallow moves into a caller-safe Action method).
- `declare(strict_types=1)`, `final`, `readonly` DTO; the `TimePeriod` enum is backed (string) with no
  magic strings in `date_trunc` (use `$period->truncUnit()`).
- The public WRITE is CHEAP + RACE-SAFE + FAIL-SOFT: atomic `increment()` (never read-modify-write), a
  single deduped insert, NO PII (SHA-256 ip_hash only), NEVER a 500/422 on a view.
- Every metric is ONE grouped/filtered aggregate (NEVER an N+1); Postgres aggregates are MIXED → guarded
  (`is_numeric`/`(int)` per bucket); the aggregation columns are INDEXED; the read services NEVER
  `withoutGlobalScope` (the scope IS the isolation — UNIGES lesson).
- The admin READ is org-scoped (administrator = own-org via `OrganizationScope`, super_admin = all);
  fail-CLOSED for a confined-but-org-less viewer. NO WRITE confinement on the public path (anonymous, org
  derived from the resolved article).
- Migrations reversible; `page_views.article_id` **SET NULL** nullable, `.organization_id` **RESTRICT**;
  `daily_snapshots.organization_id` **RESTRICT** (if shipped). No SoftDeletes on `page_views` (append-only).
- Web validation (the dashboard filter) = 302 + session errors, never 422; Inertia props snake_case;
  generated `TimePeriod` TS enum imported type-only; UI copy in `resources/locales`, server keys in
  `lang/*.json`.
- The slices 001–006 suites stay green: the `views_count` increment is a pure column bump on the existing
  org-scoped Article (its READ is already confined); the metric services add no cross-domain Action edge;
  any Delete-Action change (Decision H) ships with a regression test + an arch allow-list update if a new
  cross-domain MODEL ref is added to Organization. `App\Support` ↛ `App\Domain` stays intact.
- Fixtures FICTIONAL (hashed faker IPs, no real PII); PII scanned before commit; the dashboard surfaces only
  aggregates.

## 5. Success = these are falsifiable and green

The **org-scoped aggregate-read crown** (a confined administrator of org A sees ONLY org-A numbers —
falsifiable against the unscoped all-orgs aggregate) and the **24h dedup + atomic increment** (a 2nd same-IP
view in 24h bumps NOTHING; a distinct-IP view bumps `views_count` atomically to 2 — falsifiable against a
naive read-modify-write or a non-deduped impl) are the load-bearing, must-be-RED-against-a-naive-impl tests.
The **single-query-per-metric** assertion (a query-count test — a per-row N+1 FAILS it), the
**no-raw-IP / fail-soft public ping** (the persisted ip is a SHA-256 hash; a tracking failure never 500s the
article), and the **role gate** (editor AND manager → 403; administrator/super_admin → 200) are the
performance, PII, and access crowns.

## 6. Decisions to lock in plan.md

- **A. The public tracking path has NO DTO + NO public route.** The view is recorded server-side from the
  ALREADY-resolved published `Article` (slice-002 `show`) — there is no user-submitted payload, so no Spatie
  DTO and no new public route. The `ip_hash`/`user_agent`/`viewed_at` are server-derived. Lock: tracking is
  a controller-side server record, not a validated submission.
- **B. WHERE the fail-soft swallow lives.** A tracking exception must NEVER 500 the article render. Lock:
  the `RecordPageViewAction::handle` does the work and MAY throw; the CALL SITE (the `show` controller)
  wraps it in `try { … } catch (\Throwable $e) { Log::warning(…); }` so the render proceeds. (Alternative: a
  `recordSafely()` Action wrapper that swallows internally — plan picks ONE; the controller-try keeps the
  Action pure/testable [the dedup/increment tests call `handle` directly and CAN assert it throws on a real
  fault], so the controller-try is preferred.)
- **C. Record via the CONTROLLER call, NOT a separate `TrackPageViewMiddleware` (supersedes §10.3 for MVP).**
  The `show` controller already resolves the exact published Article (+ org); recording there avoids a 2nd
  article lookup a middleware would need. §10.3's middleware is noted as the future option (for non-article
  pages). Lock: controller-call.
- **D. The dashboard org provenance.** A confined administrator is org-pinned by `OrganizationScope`
  (own-org, automatic — no manual `where`); a super_admin is unconfined and MAY pass `?organization_id=` to
  narrow. The `AnalyticsFilterData.organization_id` is `Nullable+Exists`, applied only when present (a no-op
  for a confined administrator). Lock: scope does the confinement; the filter narrows only the unconfined
  view.
- **E. The role gate — RESOLVE the §7.5 vs §10.2 vs §3.7 tension. Gate at `role:administrator` + `org.scope`.**
  §7.5 header says "Super Admin only"; §10.2 RBAC matrix says `Analytics | super_admin All | administrator
  Own org | manager — | editor —`; §3.7 ANALYTICS-02 says "super_admin and administrator", ANALYTICS-03
  mentions "Manager sees only own org stats". The MOST EXPLICIT, falsifiable source is the §10.2 matrix
  (administrator = Own-org, manager/editor = none), corroborated by §3.7 ANALYTICS-02. So the gate is
  **`['auth','role:administrator','org.scope']`** — administrator (own-org via scope) + super_admin (all);
  **manager AND editor → 403.** The §3.7 ANALYTICS-03 "manager" line and the §7.5 "super_admin only" header
  are the conflicting fragments; the §10.2 matrix governs (it is the canonical RBAC table the other slices
  already follow). **This is a SPEC tension flagged for human confirmation at the review gate** — if the
  human wants the §3.7 manager reading, the gate drops to `role:manager` (a one-line change) and the role
  test flips manager 403→200. Lock the `role:administrator` default + the flag.
- **F. `daily_snapshots` — SHIP the table+model now, or DEFER the whole table?** Two options: (i) ship the
  migration + `DailySnapshot` model for schema completeness (the SPEC §6.3.13 defines it) but DEFER the
  populating job + reading from it (the MVP reads live aggregates); (ii) DEFER the entire table to the
  scaling phase. **Plan picks (i) — ship the table + model + factory (schema completeness, zero runtime
  cost, the §6.3.13 contract honored) but read LIVE aggregates in the dashboard; the rolling job is the
  deferred piece.** Lock (i) unless the human prefers the leaner (ii) at the review gate. (Either way the
  MVP dashboard reads live, not from snapshots.)
- **G. The chart rendering — NO heavy charting dependency this slice.** Reuse an existing chart/table
  component if the admin shell has one; else render the over-time/trend series as a minimal SVG/CSS bar
  series in the page (the data is small fixed buckets). Lock: no new npm charting lib (supply-chain
  hygiene — pnpm); a follow-on may add one.
- **H. Article delete mode + the page_views SET-NULL regression.** `page_views.article_id` is FK **SET NULL**
  (§6.4) — confirm whether Article HARD-deletes (then a delete SET-NULLs the page_views, anonymizing them,
  `organization_id` intact) or SOFT-deletes (then page_views are untouched). Plan reads
  `DeleteArticleAction` / the Article model's SoftDeletes to confirm the mode and writes the CORRECT
  regression assertion. **If a new cross-domain MODEL ref is added to any Delete Action (e.g. Content's
  DeleteArticleAction touching `Analytics\Models\PageView`), add the arch allow-list edge + the regression
  test (the slice-004 lesson).** Lock the confirmed mode + the matching test in plan.md. (Likely NO Delete
  Action change is needed — SET NULL is a DB-level cascade the FK handles automatically; confirm.)
- **I. The metric services live under `App\Domain\Analytics\Services` (NOT Actions).** They are READ
  services (the UNIGES `…Service` convention — query + map, no transaction, no write). The single WRITE
  (`RecordPageViewAction`) is an Action (it mutates: increment + insert in a transaction). Lock the
  Action-vs-Service split: write = Action, reads = Services.
- **J. The arch boundary edges.** `App\Domain\Analytics` toOnlyUse: `App\Domain\Shared`, `App\Support`
  (OrganizationScope), `App\Domain\Content\Models` (Article), `App\Domain\Organization\Models`
  (Organization/Branch), `App\Domain\Jobs\Models` (JobPosting), `App\Domain\Membership\Models` (Member),
  `App\Domain\Engagement\Models` (ContactMessage), `Illuminate`, `Spatie\LaravelData`,
  `Spatie\TypeScriptTransformer`, `Database\Factories`, `Carbon` (the DTO/services type CarbonImmutable for
  bucket dates / `viewed_at`); `->ignoring(['__','now','request'])`; plus `never depends on HTTP`,
  `Analytics actions/data/services are final`, and `Analytics enums are string-backed` (TimePeriod IS an
  enum — this rule APPLIES, unlike Engagement). The SPEC §11.2 STUB (`App\Domain\Analytics` toOnlyUse
  Shared/Illuminate/Spatie) is BROADENED to this exact set. Confirm each edge is actually used during
  implement (drop any unused). Lock the edge set.
