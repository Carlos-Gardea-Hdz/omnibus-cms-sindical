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
