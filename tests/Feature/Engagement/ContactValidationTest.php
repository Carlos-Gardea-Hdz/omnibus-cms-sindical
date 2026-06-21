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
 * PUBLIC contact-submission validation (CONTRACT §3/§12.2, SPEC §3.5 CONTACT-01).
 * SubmitContactData is validated via Spatie Data on the controller signature, so a
 * failing WEB request ALWAYS surfaces as a 302 redirect-back with session errors —
 * NEVER a 422 (the cardinal CMS web-validation rule). This covers the required-field +
 * length bounds (first_name/last_name ≤60, email valid + ≤60, message ≤1000), the
 * 10-digit MexicanPhone rule, and the Exists() FK rules on organization_id / branch_id.
 * No acting user (the endpoint is anonymous) — a bad payload fails on VALIDATION. The
 * request carries a referer (route('home')) so back() resolves. Runs on PostgreSQL 18
 * (RefreshDatabase). All fixtures are FICTIONAL.
 */

/**
 * A fully-valid baseline so each case isolates exactly one bad field.
 *
 * @return array<string, mixed>
 */
function validContactAttrs(Organization $organization, Branch $branch): array
{
    return [
        'first_name' => 'Valida',
        'last_name' => 'Persona',
        'email' => 'valida.fictional@example.com',
        'phone' => '5512345678',
        'message' => 'Un mensaje de contacto válido.',
        'organization_id' => $organization->getKey(),
        'branch_id' => $branch->getKey(),
    ];
}

it('rejects a contact submission with a bad field: 302, never 422, nothing created', function (Closure $mutate, string $field): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    $payload = $mutate(validContactAttrs($organization, $branch));

    from(route('home'))
        ->post(route('contact.store'), $payload)
        ->assertRedirect(route('home'))
        ->assertStatus(302) // explicitly NOT 422
        ->assertSessionHasErrors($field);

    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
})->with([
    'missing first_name' => [
        function (array $a): array {
            unset($a['first_name']);

            return $a;
        },
        'first_name',
    ],
    'over-max first_name (>60)' => [
        fn (array $a): array => [...$a, 'first_name' => str_repeat('A', 61)],
        'first_name',
    ],
    'missing last_name' => [
        function (array $a): array {
            unset($a['last_name']);

            return $a;
        },
        'last_name',
    ],
    'over-max last_name (>60)' => [
        fn (array $a): array => [...$a, 'last_name' => str_repeat('B', 61)],
        'last_name',
    ],
    'missing email' => [
        function (array $a): array {
            unset($a['email']);

            return $a;
        },
        'email',
    ],
    'invalid email' => [
        fn (array $a): array => [...$a, 'email' => 'not-an-email'],
        'email',
    ],
    'over-max email (>60)' => [
        fn (array $a): array => [...$a, 'email' => str_repeat('a', 55).'@example.com'],
        'email',
    ],
    'missing phone' => [
        function (array $a): array {
            unset($a['phone']);

            return $a;
        },
        'phone',
    ],
    'too-short phone (9 digits)' => [
        fn (array $a): array => [...$a, 'phone' => '551234567'],
        'phone',
    ],
    'too-long phone (11 digits)' => [
        fn (array $a): array => [...$a, 'phone' => '55123456789'],
        'phone',
    ],
    'non-numeric phone' => [
        fn (array $a): array => [...$a, 'phone' => '55-12-34-56'],
        'phone',
    ],
    'missing message' => [
        function (array $a): array {
            unset($a['message']);

            return $a;
        },
        'message',
    ],
    'over-max message (>1000)' => [
        fn (array $a): array => [...$a, 'message' => str_repeat('M', 1001)],
        'message',
    ],
    'missing organization_id' => [
        function (array $a): array {
            unset($a['organization_id']);

            return $a;
        },
        'organization_id',
    ],
    'non-existent organization_id' => [
        fn (array $a): array => [...$a, 'organization_id' => 999_999],
        'organization_id',
    ],
    'missing branch_id' => [
        function (array $a): array {
            unset($a['branch_id']);

            return $a;
        },
        'branch_id',
    ],
    'non-existent branch_id' => [
        fn (array $a): array => [...$a, 'branch_id' => 999_999],
        'branch_id',
    ],
]);

it('accepts a boundary-length payload (60-char names, exactly-1000-char message, 10-digit phone)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    from(route('home'))
        ->post(route('contact.store'), [
            ...validContactAttrs($organization, $branch),
            'first_name' => str_repeat('N', 60),
            'last_name' => str_repeat('A', 60),
            'message' => str_repeat('M', 1000),
            'phone' => '5500000000',
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', str_repeat('N', 60))->exists())->toBeTrue();
});
