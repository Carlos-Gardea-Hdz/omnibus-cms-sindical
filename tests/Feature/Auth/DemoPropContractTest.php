<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\DemoPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * Runtime prop-contract test for the demo surface (CONTRACT §D). The Auth/DemoChooser
 * page exposes the preset catalogue WITHOUT ever shipping a server-only token, and the
 * shared `demo` prop reports the session state. A `super_admin` value must NEVER appear
 * in the chooser (the load-bearing demo invariant, mirrored at the prop layer). Boots
 * PostgreSQL 18.
 */

beforeEach(function (): void {
    RateLimiter::clear('demo-login');
});

it('renders the Auth/DemoChooser with the preset catalogue and no super_admin, no leaked token', function (): void {
    get(route('demo.create'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('Auth/DemoChooser')->has('presets');

            $presets = $page->toArray()['props']['presets'];

            $values = collect($presets)->pluck('value')->all();
            expect($values)->not->toContain('super_admin');

            // Each preset row carries only the public shape — value/role/title_key/description_key.
            foreach ($presets as $preset) {
                expect($preset)->toHaveKeys(['value', 'role', 'title_key', 'description_key'])
                    ->and($preset)->not->toHaveKey('demo_session_id')
                    ->and($preset)->not->toHaveKey('token');
            }
        });
});

it('shares a null `demo` prop for a real (non-demo) authenticated user', function (): void {
    $real = User::factory()->superAdmin()->create();

    actingAs($real)
        ->get(route('admin.dashboard'))
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('demo', null),
        );
});

it('shares a populated `demo` prop {is_demo, expires_at} once a demo session is active', function (): void {
    post(route('demo.store'), ['preset' => DemoPreset::Editor->value])
        ->assertRedirect(route('admin.dashboard'));

    get(route('admin.dashboard'))
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->has('demo', fn (AssertableInertia $demo): AssertableInertia => $demo
                    ->where('is_demo', true)
                    ->whereType('expires_at', 'integer')),
        );
});
