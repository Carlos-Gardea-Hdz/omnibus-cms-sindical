<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\from;

uses(RefreshDatabase::class);

/*
 * THE RATE LIMIT (CONTRACT §6/§12.5, SPEC §3.5, §10.4). POST /contact carries
 * throttle:3,15 — 3 submissions per 15 minutes per IP. This is the §10.4 abuse control
 * for the public contact form (there is no CAPTCHA — the throttle IS the control). The
 * limiter is backed by the array cache store (phpunit.xml CACHE_STORE=array), recreated
 * per test by the fresh application instance — so this test starts with a clean budget of
 * 3 without any manual clear. The first 3 POSTs pass (302), the 4th 429s, and NO fourth
 * row is written. Runs on PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

it('enforces throttle:3,15: the first 3 POSTs pass, the 4th 429s, no fourth row', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    $payload = fn (string $name): array => [
        'first_name' => $name,
        'last_name' => 'Throttle',
        'email' => 'throttle.fictional@example.com',
        'phone' => '5512345678',
        'message' => 'Mensaje dentro de la ventana.',
        'organization_id' => $organization->getKey(),
        'branch_id' => $branch->getKey(),
    ];

    // 3 successful submissions inside the window.
    for ($i = 1; $i <= 3; $i++) {
        from(route('home'))
            ->post(route('contact.store'), $payload("Throttle{$i}"))
            ->assertStatus(302)
            ->assertSessionHasNoErrors();
    }

    // The 4th is rate-limited — 429, and NO fourth row is written.
    from(route('home'))
        ->post(route('contact.store'), $payload('Throttle4'))
        ->assertStatus(429);

    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Throttle4')->exists())->toBeFalse();

    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'like', 'Throttle%')->count())->toBe(3);
});
