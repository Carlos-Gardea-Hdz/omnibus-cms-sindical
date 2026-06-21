<?php

declare(strict_types=1);

/*
 * Architecture tests encode the OMNIBUS Law as executable rules (SPEC §11.2).
 * A violation FAILS the build. These are the universal, always-on rules; the
 * per-domain cross-isolation rules (e.g. "Identity only uses Shared") are added
 * in this file as each domain under app/Domain/** is built.
 */

arch('no debug statements ship to production')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exec', 'shell_exec', 'system'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();

arch('domain code is final')
    ->expect('App\Domain')
    ->classes()
    ->toBeFinal();

arch('controllers never touch Eloquent or the request directly')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Http\Request',
        'Illuminate\Database\Eloquent\Model',
    ]);

arch('Spatie Data DTOs are the only validation mechanism — no Form Requests')
    ->expect('App\Http')
    ->not->toUse('Illuminate\Foundation\Http\FormRequest');

/*
 * Slice 001 — Identity domain (auth foundation). The cross-isolation rule is the
 * load-bearing one: the Identity domain may lean only on Shared plus the
 * cross-domain Eloquent base model (App\Models\User — the AuthenticateUserAction
 * return type, SPEC §5.2 Rule 3), Illuminate, and the Spatie Data /
 * TypeScriptTransformer boundaries. It must never reach into HTTP or another
 * domain. The strict-types / final rules are already covered by the broad
 * App\Domain rules above; they stay implicit there but the no-HTTP and the
 * string-backed-enum guards are pinned explicitly here so a future narrowing of
 * the broad rules cannot silently relax the Identity auth layer.
 */

arch('Identity domain only leans on Shared, Models and framework boundaries')
    ->expect('App\Domain\Identity')
    ->toOnlyUse([
        'App\Domain\Shared',
        'App\Models',
        'Illuminate',
        'Spatie\LaravelData',
        'Spatie\TypeScriptTransformer',
    ])
    // The __() translation helper (the generic no-enumeration auth.failed message)
    // is a framework global, not a domain dependency.
    ->ignoring('__');

arch('the Identity domain never depends on HTTP')
    ->expect('App\Domain\Identity')
    ->not->toUse('Illuminate\Http');

arch('Identity actions are final')
    ->expect('App\Domain\Identity\Actions')
    ->classes()
    ->toBeFinal();

arch('Identity data DTOs are final')
    ->expect('App\Domain\Identity\Data')
    ->classes()
    ->toBeFinal();

arch('Identity enums are string-backed')
    ->expect('App\Domain\Identity\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums();

/*
 * Slice 002 — Content domain (Article + Category CRUD, the publication state
 * machine and the stored-XSS sanitizer). The cross-isolation rule is the
 * load-bearing one: the Content domain may lean only on Shared, the cross-domain
 * Eloquent base model (App\Models\User — the CreateArticleAction author argument,
 * CONTRACT §6), Illuminate, and the Spatie Data / TypeScriptTransformer boundaries.
 * Laravel\Scout is whitelisted ahead of the deferred Search slice (NEWS-06) even
 * though Article is not Searchable yet. It must never reach into HTTP or another
 * domain. The __() translation helper (used by the in-use / invalid-transition
 * exception messages) is a framework global, not a domain dependency, so it is
 * ignored.
 */

arch('Content domain only leans on Shared, Models and framework boundaries')
    ->expect('App\Domain\Content')
    ->toOnlyUse([
        'App\Domain\Shared',
        'App\Models',
        // Slice-003 retrofit (CONTRACT §12): the Article model gains organization()
        // and branch() belongsTo relations, so it references the Organization-domain
        // MODELS. This is a cross-domain MODEL reference (the org-scoping retrofit's
        // relation targets), NOT a call into another domain's Action — the §11.2
        // "never another domain's Actions" rule is preserved. The mirror edge
        // (Organization → Content\Models\Article) is whitelisted symmetrically above.
        'App\Domain\Organization\Models',
        // The App\Support scope spine: the retrofit adds the global OrganizationScope
        // to Article via booted(), exactly the UNIGES DemoScope placement.
        'App\Support',
        'Illuminate',
        'Spatie\LaravelData',
        'Spatie\TypeScriptTransformer',
        'Laravel\Scout',
        // The Content Eloquent models live in app/Domain (unlike User), so their
        // HasFactory binding must name the factory explicitly via newFactory().
        // Factories are test/seed infrastructure, not a cross-domain or HTTP
        // dependency — whitelisted like the future-Search Laravel\Scout boundary.
        'Database\Factories',
    ])
    // __() and now() are framework globals (translation + Carbon clock), not domain
    // dependencies — the Actions use now() for published_at / image-path timestamps.
    ->ignoring(['__', 'now']);

arch('the Content domain never depends on HTTP')
    ->expect('App\Domain\Content')
    ->not->toUse('Illuminate\Http')
    // UploadedFile is the standard Spatie Data file-upload type; ArticleData's
    // ?UploadedFile $featured_image (CONTRACT §4) and the storage-writing Actions
    // are its legitimate boundary. No request/response coupling leaks in.
    ->ignoring('Illuminate\Http\UploadedFile');

arch('Content actions are final')
    ->expect('App\Domain\Content\Actions')
    ->classes()
    ->toBeFinal();

arch('Content data DTOs are final')
    ->expect('App\Domain\Content\Data')
    ->classes()
    ->toBeFinal();

arch('Content enums are string-backed')
    ->expect('App\Domain\Content\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums();

/*
 * Slice 003 — Organization domain (Organization/Branch/Director/Representative/
 * Municipality CRUD + the org-scoping retrofit). The cross-isolation rule is the
 * load-bearing one: the Organization domain may lean only on Shared, the
 * cross-domain Eloquent base model (App\Models\User — the acting-user argument the
 * Actions receive), the App\Support scope spine (OrganizationScope / OrganizationContext,
 * domain-neutral so the org-scoped models add the global scope WITHOUT importing
 * App\Domain\Identity — exactly the UNIGES DemoScope placement), Illuminate, and the
 * Spatie Data / TypeScriptTransformer / Database\Factories boundaries.
 *
 * The one DELIBERATE cross-domain edge is App\Domain\Content\Models\Article: the
 * Organization/Branch models expose an articles() relation and DeleteBranchAction
 * runs the §3.2 ORG-02 application-level branch→article cascade (CONTRACT §8). That
 * is a reference to another domain's MODEL (the cascade target), NOT a call into
 * another domain's ACTION — the §11.2 "never another domain's Actions" rule is
 * preserved. It must never reach into HTTP (except the UploadedFile boundary for
 * logo/photo uploads). __() and now() are framework globals, not domain deps.
 */

arch('Organization domain only leans on Shared, Models, App\\Support and framework boundaries')
    ->expect('App\Domain\Organization')
    ->toOnlyUse([
        'App\Domain\Shared',
        'App\Models',
        'App\Support',
        // The branch deletion cascade targets — cross-domain MODEL references (not
        // another domain's Action): articles (§3.2) and job postings (slice 004, so an
        // orphaned active job can't outlive its branch and 500 the public board).
        'App\Domain\Content\Models',
        'App\Domain\Jobs\Models',
        // Slice-005 (Decision I): DeleteMunicipalityAction OR-checks a member referrer so
        // a member-only municipality blocks the hard delete gracefully. This is a
        // cross-domain MODEL reference (the in-use count target — Member), NOT a call into
        // the Membership domain's Actions — mirrors the App\Domain\Jobs\Models edge above.
        'App\Domain\Membership\Models',
        'Illuminate',
        // Carbon is the framework's date library (ships with Illuminate). OrganizationData
        // type-hints CarbonImmutable for the `registered_at` DATE field — the same clock
        // dependency the Content domain leans on via the now() helper, surfaced here as an
        // explicit class import because the DTO declares a typed property, not a call.
        'Carbon',
        'Spatie\LaravelData',
        'Spatie\TypeScriptTransformer',
        'Database\Factories',
    ])
    // __(), now() and request() are framework globals (translation, Carbon clock, and the
    // current-request accessor OrganizationData::rules() uses to detect create-vs-update from
    // the bound route model), not domain dependencies — ignored like the Content domain's globals.
    ->ignoring(['__', 'now', 'request']);

arch('the Organization domain never depends on HTTP')
    ->expect('App\Domain\Organization')
    ->not->toUse('Illuminate\Http')
    // UploadedFile is the standard Spatie Data file-upload type; the logo/photo DTOs
    // and the storage-writing Actions are its legitimate boundary.
    ->ignoring('Illuminate\Http\UploadedFile');

arch('Organization actions are final')
    ->expect('App\Domain\Organization\Actions')
    ->classes()
    ->toBeFinal();

arch('Organization data DTOs are final')
    ->expect('App\Domain\Organization\Data')
    ->classes()
    ->toBeFinal();

arch('Organization enums are string-backed')
    ->expect('App\Domain\Organization\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums();

/*
 * Slice 004 — Jobs domain (org-scoped job board + public active listing). The
 * cross-isolation rule is the load-bearing one: the Jobs domain may lean only on
 * Shared, the cross-domain Eloquent base model (App\Models\User — the actor argument
 * the Create/Update Actions receive), and the App\Support spine. JobPosting belongsTo
 * Branch / Organization (RESTRICT FKs) and the Jobs-local branch→org assertion checks a
 * Branch row, so the Jobs domain references the Organization MODELS directly — a
 * cross-domain FK MODEL reference is ALLOWED; calling another domain's Actions/Services
 * is NOT (only App\Domain\Organization\Models is whitelisted, never the whole namespace).
 * It must never reach into HTTP or another domain's behaviour. Jobs has no UploadedFile
 * (the description is plain text), so no Illuminate\Http\UploadedFile boundary is needed.
 * __(), now() and request() are framework globals, not domain deps.
 */

arch('Jobs domain only leans on Shared, Models, App\\Support and framework boundaries')
    ->expect('App\Domain\Jobs')
    ->toOnlyUse([
        'App\Domain\Shared',
        'App\Models',
        'App\Support',
        // Cross-domain FK MODEL references are allowed (JobPosting belongsTo Branch /
        // Organization; the branch→org assertion queries Branch). Organization Actions /
        // Services remain forbidden — only \Models is whitelisted.
        'App\Domain\Organization\Models',
        'Illuminate',
        'Spatie\LaravelData',
        'Spatie\TypeScriptTransformer',
        'Database\Factories',
    ])
    ->ignoring(['__', 'now', 'request']);

arch('the Jobs domain never depends on HTTP')
    ->expect('App\Domain\Jobs')
    ->not->toUse('Illuminate\Http');

arch('Jobs actions are final')
    ->expect('App\Domain\Jobs\Actions')
    ->classes()
    ->toBeFinal();

arch('Jobs data DTOs are final')
    ->expect('App\Domain\Jobs\Data')
    ->classes()
    ->toBeFinal();

arch('Jobs enums are string-backed')
    ->expect('App\Domain\Jobs\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums();

/*
 * Slice 005 — Membership domain (public union-member registration + the admin
 * approve/reject review lifecycle). The cross-isolation rule is the load-bearing one:
 * the Membership domain may lean only on Shared (the CurpFormat/RfcFormat/MexicanPhone
 * PII rules live in App\Domain\Shared\Rules), the cross-domain Eloquent base model
 * (App\Models — there is no acting-user argument, but the binding is symmetric with the
 * prior domains), and the App\Support spine (Member adds the global OrganizationScope via
 * booted()). Member belongsTo Organization (nullable, SET NULL) / Municipality (RESTRICT),
 * so the Membership domain references the Organization MODELS directly — a cross-domain FK
 * MODEL reference is ALLOWED; calling another domain's Actions/Services is NOT (only
 * App\Domain\Organization\Models is whitelisted, never the whole namespace). It must never
 * reach into HTTP or another domain's behaviour. RegisterMemberData type-hints
 * CarbonImmutable for the `date_of_birth` field, so Carbon is an explicit edge (like the
 * Organization DTO). Membership has no UploadedFile boundary (registration is plain text +
 * scalars). __(), now() and request() are framework globals, not domain deps.
 */

arch('Membership domain only leans on Shared, Models, App\\Support and framework boundaries')
    ->expect('App\Domain\Membership')
    ->toOnlyUse([
        'App\Domain\Shared',
        'App\Models',
        'App\Support',
        // Cross-domain FK MODEL references are allowed (Member belongsTo Organization /
        // Municipality). Organization Actions / Services remain forbidden — only \Models.
        'App\Domain\Organization\Models',
        'Illuminate',
        // RegisterMemberData type-hints CarbonImmutable for date_of_birth — the same clock
        // dependency surfaced as an explicit import (mirrors the Organization DTO edge).
        'Carbon',
        'Spatie\LaravelData',
        'Spatie\TypeScriptTransformer',
        'Database\Factories',
    ])
    ->ignoring(['__', 'now', 'request']);

arch('the Membership domain never depends on HTTP')
    ->expect('App\Domain\Membership')
    ->not->toUse('Illuminate\Http');

arch('Membership actions are final')
    ->expect('App\Domain\Membership\Actions')
    ->classes()
    ->toBeFinal();

arch('Membership data DTOs are final')
    ->expect('App\Domain\Membership\Data')
    ->classes()
    ->toBeFinal();

arch('Membership enums are string-backed')
    ->expect('App\Domain\Membership\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums();

/*
 * Slice 006 — Engagement domain (public contact form + org-scoped admin inbox). The
 * cross-isolation rule is the load-bearing one: the Engagement domain may lean only on
 * Shared, the App\Support spine (ContactMessage adds the global OrganizationScope via
 * booted(); the Engagement-local AssertsBranchBelongsToOrganization trait queries Branch
 * scope-free via OrganizationScope::class), Illuminate, and the Spatie Data /
 * TypeScriptTransformer / Database\Factories boundaries. ContactMessage belongsTo Branch /
 * Organization (RESTRICT FKs) and the branch→org assertion queries a Branch row, so the
 * Engagement domain references the Organization MODELS directly — a cross-domain FK MODEL
 * reference is ALLOWED; calling another domain's Actions/Services is NOT (only
 * App\Domain\Organization\Models is whitelisted, never the whole namespace).
 *
 * Engagement has NO enum (contact_messages has no status column — Decision B), so there is
 * deliberately NO "Engagement enums are string-backed" rule (an empty enum namespace would
 * make that expectation a no-op at best). There is NO acting-User argument on the sole
 * (public, anonymous) Action, so App\Models is NOT whitelisted; and no Carbon-typed DTO
 * field, so Carbon is NOT an edge. The Organization isolation rule is UNCHANGED: both FK
 * parents soft-delete, so the new RESTRICT FK never trips DeleteBranchAction and no
 * Organization → Engagement edge is added (Decision E). Engagement must never reach into
 * HTTP (the message is a plain TEXT scalar — no UploadedFile boundary). __(), now() and
 * request() are framework globals, not domain deps.
 */

arch('Engagement domain only leans on Shared, Models, App\\Support and framework boundaries')
    ->expect('App\Domain\Engagement')
    ->toOnlyUse([
        'App\Domain\Shared',
        'App\Support',
        // Cross-domain FK MODEL references are allowed (ContactMessage belongsTo Branch /
        // Organization; the branch→org assertion queries Branch). Organization Actions /
        // Services remain forbidden — only \Models is whitelisted.
        'App\Domain\Organization\Models',
        'Illuminate',
        'Spatie\LaravelData',
        'Spatie\TypeScriptTransformer',
        'Database\Factories',
    ])
    ->ignoring(['__', 'now', 'request']);

arch('the Engagement domain never depends on HTTP')
    ->expect('App\Domain\Engagement')
    ->not->toUse('Illuminate\Http');

arch('Engagement actions are final')
    ->expect('App\Domain\Engagement\Actions')
    ->classes()
    ->toBeFinal();

arch('Engagement data DTOs are final')
    ->expect('App\Domain\Engagement\Data')
    ->classes()
    ->toBeFinal();

/*
 * Slice 007 — Analytics domain (the LAST CMS domain: page-view tracking + the org-scoped
 * admin dashboard). The cross-isolation rule is the load-bearing one: Analytics is a
 * read-heavy aggregate domain that READS five org-scoped source models across the program,
 * so it legitimately references their MODELS (never their Actions/Services — only \Models is
 * whitelisted). The edges:
 *   - App\Domain\Content\Models       — Article (the views_count counter site + top-articles)
 *   - App\Domain\Organization\Models  — Organization / Branch (PageView FK parents + filters)
 *   - App\Domain\Jobs\Models          — JobPosting (active-vs-closed aggregate)
 *   - App\Domain\Membership\Models    — Member (registration-trend aggregate)
 *   - App\Domain\Engagement\Models    — ContactMessage (message-trend aggregate)
 * plus Shared, the App\Support scope spine (PageView/DailySnapshot add the global
 * OrganizationScope via booted()), Illuminate, the Spatie Data / TypeScriptTransformer /
 * Database\Factories boundaries, and Carbon (the metric services lean on the Carbon clock
 * for the date_trunc windows). There is NO acting-User argument on the sole (server-derived)
 * Action, but App\Models is whitelisted symmetrically with the prior aggregate-reading
 * domains in case a future report binds the actor. It must never reach into HTTP (the
 * ip_hash is computed in the controller and handed to the Action as a plain string — the
 * Action never sees Illuminate\Http). __(), now() and request() are framework globals.
 *
 * Unlike Engagement, Analytics HAS an enum (TimePeriod), so the string-backed-enum rule
 * APPLIES. Confirm each toOnlyUse edge is actually used during implement; drop any unused.
 */

arch('Analytics domain only leans on Shared, Models, App\\Support and framework boundaries')
    ->expect('App\Domain\Analytics')
    ->toOnlyUse([
        'App\Domain\Shared',
        'App\Models',
        'App\Support',
        // Cross-domain aggregate-source MODEL references are allowed (the dashboard READS
        // five org-scoped models). Their Actions / Services remain forbidden — only \Models.
        'App\Domain\Content\Models',
        'App\Domain\Organization\Models',
        'App\Domain\Jobs\Models',
        // JobStatus is the (string-backed) enum the EngagementAnalyticsService binds as a
        // parameter in the `count(*) filter (where status = ?)` active-vs-closed aggregate —
        // a magic-string-free enum VALUE reference (NOT a call into the Jobs domain's
        // Actions/Services, which stay forbidden). Mirrors the cross-domain \Models edge.
        'App\Domain\Jobs\Enums',
        'App\Domain\Membership\Models',
        'App\Domain\Engagement\Models',
        'Illuminate',
        // The metric services type-hint / lean on the Carbon clock for the date_trunc
        // bucketing windows — the same clock edge the Organization/Membership DTOs surface.
        'Carbon',
        'Spatie\LaravelData',
        'Spatie\TypeScriptTransformer',
        'Database\Factories',
    ])
    // __(), now() and request() are framework globals (translation, Carbon clock, the
    // current-request accessor the filter DTO leans on), not domain dependencies.
    ->ignoring(['__', 'now', 'request']);

arch('the Analytics domain never depends on HTTP')
    ->expect('App\Domain\Analytics')
    ->not->toUse('Illuminate\Http');

arch('Analytics actions are final')
    ->expect('App\Domain\Analytics\Actions')
    ->classes()
    ->toBeFinal();

arch('Analytics data DTOs are final')
    ->expect('App\Domain\Analytics\Data')
    ->classes()
    ->toBeFinal();

arch('Analytics services are final')
    ->expect('App\Domain\Analytics\Services')
    ->classes()
    ->toBeFinal();

arch('Analytics enums are string-backed')
    ->expect('App\Domain\Analytics\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums();

/*
 * The scope spine lives under App\Support precisely so the org-scoped domain models
 * (Article, Branch, Director, Representative) can add the global OrganizationScope
 * WITHOUT importing App\Domain — which the per-domain isolation rules above forbid.
 * This guard keeps App\Support domain-neutral: if it ever imported App\Domain, the
 * placement would no longer break the cycle and the isolation rules would silently
 * be at risk. Mirrors the UNIGES DemoScope/DemoContext neutrality contract.
 */
arch('App\\Support never imports App\\Domain (the scope spine stays domain-neutral)')
    ->expect('App\Support')
    ->not->toUse('App\Domain');
