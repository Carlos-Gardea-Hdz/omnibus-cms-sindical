<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DirectorController;
use App\Http\Controllers\Admin\JobController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\MunicipalityController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\RepresentativeController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\DemoLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Public\ArticleController as PublicArticleController;
use App\Http\Controllers\Public\ContactController as PublicContactController;
use App\Http\Controllers\Public\JobController as PublicJobController;
use App\Http\Controllers\Public\MemberController as PublicMemberController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

/*
 * Session authentication (SPEC §3.1 AUTH-01, §7.1). The 'guest' alias keeps an
 * already-authenticated user off the login screen; 'auth' gates logout. Web
 * validation is 302 + session errors (never 422) via the LoginData DTO, and the
 * credential error is generic (no user enumeration). Laravel ships the 'auth' and
 * 'guest' aliases by default — only the level-based 'role' alias is registered in
 * bootstrap/app.php. Brute-force throttling is DEFERRED (gate decision C).
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
 * Demo mode (SPEC §3.1 AUTH-02 / slice-008 §A). A visitor picks a DemoPreset on the
 * guest-facing chooser and is provisioned a throwaway, session-scoped sandbox via
 * POST /demo-login. The chooser is anonymous (a CTA also lives on the login screen,
 * Decision E); the POST is IP rate-limited (10/hour, AUTH-02) by the `demo-login`
 * limiter registered in AppServiceProvider — a 10th-over hit is 302 + a `preset` error,
 * no user minted. DemoLoginData (a DemoPreset enum-cast, NEVER super_admin) is the sole
 * validation truth, so an out-of-set preset is 302 + `preset` error, never 422. The
 * 30-minute TTL + the destructive-route block are enforced by the `demo` middleware
 * applied to every admin group below.
 */
Route::get('/demo', [DemoLoginController::class, 'create'])->name('demo.create');
Route::post('/demo-login', [DemoLoginController::class, 'store'])
    ->middleware('throttle:demo-login')
    ->name('demo.store');

/*
 * Admin shell (SPEC §7.2, §10.2, §10.3). Gated 'auth' first (guests → 302 login), then the
 * level-based 'role' alias at the LOWEST rung ('role:editor') so all four roles reach the
 * dashboard this slice; an authenticated-but-under-level user is a 403. The 'org.scope'
 * alias (slice-003 §3) sets the per-request organization context: it CONFINES manager+editor
 * to their own org and UNCONFINES administrator+super_admin — it never 403s (the 'role' alias
 * owns access). With it here, managers/editors auto-confine on /admin/articles* (and every
 * org-scoped model) without touching the Content controllers; the global scope does the work.
 */
Route::middleware(['auth', 'role:editor', 'org.scope', 'demo'])->group(function (): void {
    Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');

    /*
     * Content authoring (SPEC §3.3 NEWS-01/02/03, CAT-01/02; §10.2 / Gate D).
     * Editor+ may create, edit and delete articles and manage the category catalog.
     * Publishing a draft is a HIGHER privilege (manager+) — gated separately below.
     */
    Route::get('/admin/articles', [ArticleController::class, 'index'])->name('admin.articles.index');
    Route::get('/admin/articles/create', [ArticleController::class, 'create'])->name('admin.articles.create');
    Route::post('/admin/articles', [ArticleController::class, 'store'])->name('admin.articles.store');
    Route::get('/admin/articles/{article}/edit', [ArticleController::class, 'edit'])->name('admin.articles.edit');
    Route::put('/admin/articles/{article}', [ArticleController::class, 'update'])->name('admin.articles.update');
    Route::delete('/admin/articles/{article}', [ArticleController::class, 'destroy'])->name('admin.articles.destroy');

    Route::get('/admin/categories', [CategoryController::class, 'index'])->name('admin.categories.index');
    Route::post('/admin/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
    Route::put('/admin/categories/{category}', [CategoryController::class, 'update'])->name('admin.categories.update');
    Route::delete('/admin/categories/{category}', [CategoryController::class, 'destroy'])->name('admin.categories.destroy');

    /*
     * Job-posting authoring + lifecycle (SPEC §3.4 JOB-01/02; §7.2, §10.2 / slice-004 §8).
     * The WHOLE Jobs lifecycle is editor-scoped — unlike Article publish (a manager privilege),
     * the JobStatus toggle stays here at `role:editor` (JOB-02), NOT gated separately at manager+.
     * JobPosting is org-scoped, so 'org.scope' on this group auto-confines a manager/editor: the
     * index/branch picker filter to their own org and a cross-org route-model-bound {job} 404s.
     * The salary-range + cross-org-branch + transition guards live in the Actions, not here.
     */
    Route::get('/admin/jobs', [JobController::class, 'index'])->name('admin.jobs.index');
    Route::get('/admin/jobs/create', [JobController::class, 'create'])->name('admin.jobs.create');
    Route::post('/admin/jobs', [JobController::class, 'store'])->name('admin.jobs.store');
    Route::get('/admin/jobs/{job}/edit', [JobController::class, 'edit'])->name('admin.jobs.edit');
    Route::put('/admin/jobs/{job}', [JobController::class, 'update'])->name('admin.jobs.update');
    Route::delete('/admin/jobs/{job}', [JobController::class, 'destroy'])->name('admin.jobs.destroy');
    Route::post('/admin/jobs/{job}/status', [JobController::class, 'toggleStatus'])->name('admin.jobs.status');
});

/*
 * Content publishing (SPEC §3.3 NEWS-07; §10.2 / Gate D). Publish / unpublish /
 * archive a draft is a MANAGER privilege: an authenticated editor hitting these is a
 * 403 (the `role:manager` rung outranks `role:editor`). Bodyless POSTs — the publish
 * precondition (featured image + non-empty content) and the strict ArticleStatus
 * transition guard live in the Actions, not here.
 */
Route::middleware(['auth', 'role:manager', 'org.scope', 'demo'])->group(function (): void {
    Route::post('/admin/articles/{article}/publish', [ArticleController::class, 'publish'])->name('admin.articles.publish');
    Route::post('/admin/articles/{article}/archive', [ArticleController::class, 'archive'])->name('admin.articles.archive');
});

/*
 * Organization administration (SPEC §3.2, §7.3-7.4, §10.2-10.3 / slice-003 §10). Each group
 * pins ONE minimum role rung and carries 'org.scope' so the request-scoped org context is set:
 * super_admin/administrator run UNCONFINED (cross-org); manager runs CONFINED to its own org,
 * so the org-scoped Branch/Representative listings auto-filter and a cross-org route-model-bound
 * row 404s. The 'org.scope' alias only sets context — it never aborts; the 'role' rung owns access.
 */

// Organizations are the top-level tenant record: super_admin only (SPEC §7.3).
Route::middleware(['auth', 'role:super_admin', 'org.scope', 'demo'])->group(function (): void {
    Route::resource('admin/organizations', OrganizationController::class)
        ->except(['show'])
        ->names('admin.organizations');
});

// Shared municipality catalog + per-org directors: administrator and above (SPEC §7.3).
Route::middleware(['auth', 'role:administrator', 'org.scope', 'demo'])->group(function (): void {
    Route::get('/admin/municipalities', [MunicipalityController::class, 'index'])->name('admin.municipalities.index');
    Route::post('/admin/municipalities', [MunicipalityController::class, 'store'])->name('admin.municipalities.store');
    Route::put('/admin/municipalities/{municipality}', [MunicipalityController::class, 'update'])->name('admin.municipalities.update');
    Route::delete('/admin/municipalities/{municipality}', [MunicipalityController::class, 'destroy'])->name('admin.municipalities.destroy');

    Route::resource('admin/directors', DirectorController::class)
        ->except(['show'])
        ->names('admin.directors');

    /*
     * Analytics dashboard (SPEC §3.7 ANALYTICS-01/02/03, §7.5, §10.2 / slice-007 §9). READ-ONLY,
     * index ONLY. The §10.2 RBAC matrix governs the gate: administrator = own-org, super_admin =
     * all — so it rides THIS `role:administrator` group. An editor or a MANAGER (lower rungs) is a
     * 403. `org.scope` confines the (already-403'd) lower actors and auto-filters the org-scoped
     * metric models; an administrator runs unconfined (narrowable via `?organization_id=`). There
     * is NO public Analytics route — page-view tracking rides the existing GET /articles/{article}.
     * `/admin/analytics/export` is DEFERRED (§7.5).
     */
    Route::get('/admin/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics.index');

    /*
     * User administration (SPEC §3.1 AUTH-04 / slice-008 §B). Gated `role:administrator` so an
     * editor/manager is a 403; the `demo` middleware on this group blocks a demo user on the write
     * routes. The User model carries NO OrganizationScope (Deviation A — the login lookup runs
     * before context), so `org.scope` here does NOT auto-confine the {user} binding: confinement
     * is an EXPLICIT `where` in the controller (an administrator sees + reaches only own-org users,
     * a super_admin runs cross-org), and a cross-org {user} 404s in the controller, not via a global
     * scope. `show` is excluded (no per-user detail screen — index → create/edit only). The
     * assignable-set check, the server-side org stamp for an administrator, the super_admin
     * singleton swap, and the self-delete / last-super_admin / self-elevation guards live in the
     * Actions; UserData/UpdateUserData are the sole validation truth (a bad field → 302, never 422).
     */
    Route::resource('admin/users', UserController::class)
        ->except(['show'])
        ->names('admin.users');
});

// Org-scoped branches + representatives + membership review: manager and above, auto-confined by
// 'org.scope' (SPEC §7.4, §7.3). An editor (a LOWER rung) hitting any of these is a 403.
Route::middleware(['auth', 'role:manager', 'org.scope', 'demo'])->group(function (): void {
    Route::resource('admin/branches', BranchController::class)
        ->except(['show'])
        ->names('admin.branches');

    Route::resource('admin/representatives', RepresentativeController::class)
        ->except(['show'])
        ->names('admin.representatives');

    /*
     * Membership review (SPEC §3.6 MEMBER-02; §7.3, §10.2, §10.5 / slice-005 §8). Admin gets
     * ONLY index + approve + reject — there is NO admin create / update / delete / edit (the SOLE
     * create path is the PUBLIC registration endpoint below). Member is org-scoped, so 'org.scope'
     * on this group auto-confines a manager: the review index filters to their own org and a
     * cross-org route-model-bound {member} 404s (the write-isolation crown — a confined manager of
     * org A can NEVER approve/reject an org-B member). The {member} is an implicit SCOPED binding;
     * the scope is NOT bypassed on the admin path. The MemberStatus transition guard lives in the
     * Action, not here. The review index never exposes curp / rfc (PII stays at rest, §10.5).
     */
    Route::get('/admin/members', [MemberController::class, 'index'])->name('admin.members.index');
    Route::post('/admin/members/{member}/approve', [MemberController::class, 'approve'])->name('admin.members.approve');
    Route::post('/admin/members/{member}/reject', [MemberController::class, 'reject'])->name('admin.members.reject');

    /*
     * Contact inbox (SPEC §3.5, §6.3.11, §7.2, §10.2, §10.5 / slice-006 §6). Admin gets ONLY a
     * read-only index — there is NO create / update / delete / moderation (a contact message has
     * no status and no deleted_at; it is a permanent audit record, §6.4). The SOLE create path is
     * the PUBLIC /contact endpoint below. ContactMessage is org-scoped, so 'org.scope' on this
     * group auto-confines a manager: the inbox filters to their own org's messages (the read-
     * isolation crown — a confined manager of org A can NEVER see an org-B message). The inbox
     * surfaces email + phone (replying is the point, scoped to the org), never on a public path.
     */
    Route::get('/admin/contacts', [ContactController::class, 'index'])->name('admin.contacts.index');
});

/*
 * Public single-article view (SPEC §3.3 NEWS-04). The `{article}` is the slug; the
 * controller resolves it WITHOUT the OrganizationScope (public content is unconfined —
 * a published article is visible to any visitor, even a logged-in editor whose session
 * is org-confined). views_count tracking is DEFERRED (Analytics).
 */
Route::get('/articles/{article}', [PublicArticleController::class, 'show'])->name('articles.show');

/*
 * Public job board (SPEC §3.4 JOB-03 / slice-004 §8). Both routes resolve WITHOUT the
 * OrganizationScope (public content is unconfined — an active job is visible to any visitor,
 * even a logged-in editor whose session is org-confined) and are filtered to JobStatus::Active,
 * so a draft / paused / closed job (of ANY org) 404s on /jobs/{job} and is absent from /jobs.
 * The `{job}` is bound MANUALLY in the controller (a string param), not via scoped route binding.
 */
Route::get('/jobs', [PublicJobController::class, 'index'])->name('jobs.index');
Route::get('/jobs/{job}', [PublicJobController::class, 'show'])->name('jobs.show');

/*
 * Public union-membership registration (SPEC §3.6 MEMBER-01; §7.3, §10.5 / slice-005 §8). This is
 * the SOLE create path for a member: ANONYMOUS and fully UNCONFINED — no 'auth', no 'org.scope', so
 * EnsureOrganizationScope leaves the request UNCONFINED (the visitor carries no session
 * confinement) and the municipality / organization pickers offer every option. A visitor submits
 * their own PII via the RegisterMemberData DTO, so a bad field is 302 + session errors (never 422);
 * the member always lands Pending + is_affiliated=false (the Action stamps those). The store route
 * is rate-limited (5 submissions per 60 minutes) to blunt spam / abuse of the open endpoint — the
 * GET form is not throttled. There is deliberately NO public member READ path (PII is admin-only).
 */
Route::get('/membership/register', [PublicMemberController::class, 'create'])->name('membership.create');
Route::post('/membership/register', [PublicMemberController::class, 'store'])
    ->middleware('throttle:5,60')
    ->name('membership.store');

/*
 * Public contact-message submission (SPEC §3.5, §6.3.11, §10.4 / slice-006 §6). This is the SOLE
 * create path for a contact message: ANONYMOUS and fully UNCONFINED — no 'auth', no 'org.scope',
 * so EnsureOrganizationScope leaves the request UNCONFINED (the visitor carries no session
 * confinement). There is NO GET /contact page — the form is an Inertia COMPONENT embedded in the
 * landing/footer (SPEC §8.3 / Decision G), so only the store route exists here. A visitor submits
 * their data via the SubmitContactData DTO, so a bad field is 302 + session errors (never 422).
 * Both organization_id AND branch_id come from the payload; the Action asserts the branch belongs
 * to the org (a mismatch is a graceful 302 + branch_id error, never a 500). The store route is
 * rate-limited (3 submissions per 15 minutes) — the throttle IS the §10.4 abuse control (no
 * CAPTCHA); CSRF ships with the web group. There is deliberately NO public READ path (the sender
 * PII — name, email, phone — is admin-only, §10.5).
 */
Route::post('/contact', [PublicContactController::class, 'store'])
    ->middleware('throttle:3,15')
    ->name('contact.store');
