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
