<?php

declare(strict_types=1);

use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * RegisterMemberData DTO validation (CONTRACT §4/§5/§14, SPEC §3.6 §11.4). The DTO is
 * validated via Spatie Data on the PUBLIC controller signature, so a failing WEB
 * request ALWAYS surfaces as a 302 redirect-back with session errors — NEVER a 422
 * (the cardinal CMS web-validation rule). This covers: the Mexican-PII format rules
 * (CurpFormat / RfcFormat / MexicanPhone), the digits:5 postal code, the before:today
 * DOB, the required name parts, and the Exists() FK rules on municipality_id (required)
 * and organization_id (nullable but must exist when present). Each case isolates ONE
 * bad field over a valid baseline; nothing is ever persisted. Runs on PostgreSQL 18.
 * All fixtures are FICTIONAL.
 */

/** A fully-valid FICTIONAL baseline (an existing municipality) so each case fails on ONE field. */
function validMemberAttrs(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ana',
        'last_name_paternal' => 'García',
        'last_name_maternal' => 'Ruiz',
        'curp' => 'XEXX010101MNEXXXA8',  // FICTIONAL — pattern-valid
        'rfc' => 'XEXX010101000',        // FICTIONAL — pattern-valid
        'date_of_birth' => '1985-03-20',
        'municipality_id' => Municipality::factory()->create()->getKey(),
        'address' => 'Avenida Siempre Viva 742',
        'postal_code' => '12345',
        'neighborhood' => 'Las Flores',
        'mobile' => '5511223344',
        'phone' => null,
    ], $overrides);
}

it('rejects a registration with one bad field: 302 (never 422) + that field error, nothing written', function (Closure $mutate, string $field): void {
    $payload = $mutate(validMemberAttrs());

    post(route('membership.store'), $payload)
        ->assertRedirect()
        ->assertStatus(302) // explicitly NOT 422
        ->assertSessionHasErrors($field);

    expect(Member::withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
})->with([
    'curp not 18 chars' => [
        fn (array $a): array => [...$a, 'curp' => 'XEXX010101HNE'],
        'curp',
    ],
    'curp bad pattern (digits where letters expected)' => [
        fn (array $a): array => [...$a, 'curp' => '0000010101HNEXXXA4'],
        'curp',
    ],
    'rfc bad pattern' => [
        fn (array $a): array => [...$a, 'rfc' => 'BADRFC'],
        'rfc',
    ],
    'mobile not 10 digits' => [
        fn (array $a): array => [...$a, 'mobile' => '12345'],
        'mobile',
    ],
    'phone present but not 10 digits' => [
        fn (array $a): array => [...$a, 'phone' => '999'],
        'phone',
    ],
    'postal_code not 5 digits' => [
        fn (array $a): array => [...$a, 'postal_code' => '123'],
        'postal_code',
    ],
    'date_of_birth today (before:today fails)' => [
        fn (array $a): array => [...$a, 'date_of_birth' => now()->toDateString()],
        'date_of_birth',
    ],
    'date_of_birth in the future' => [
        fn (array $a): array => [...$a, 'date_of_birth' => now()->addYear()->toDateString()],
        'date_of_birth',
    ],
    'missing first_name' => [
        function (array $a): array {
            unset($a['first_name']);

            return $a;
        },
        'first_name',
    ],
    'missing last_name_paternal' => [
        function (array $a): array {
            unset($a['last_name_paternal']);

            return $a;
        },
        'last_name_paternal',
    ],
    'missing last_name_maternal' => [
        function (array $a): array {
            unset($a['last_name_maternal']);

            return $a;
        },
        'last_name_maternal',
    ],
    'over-max first_name (>100)' => [
        fn (array $a): array => [...$a, 'first_name' => str_repeat('A', 101)],
        'first_name',
    ],
    'missing municipality_id' => [
        function (array $a): array {
            unset($a['municipality_id']);

            return $a;
        },
        'municipality_id',
    ],
    'non-existent municipality_id' => [
        fn (array $a): array => [...$a, 'municipality_id' => 999_999],
        'municipality_id',
    ],
    'supplied-but-non-existent organization_id' => [
        fn (array $a): array => [...$a, 'organization_id' => 999_999],
        'organization_id',
    ],
]);

it('accepts a registration that omits the optional phone (nullable)', function (): void {
    post(route('membership.store'), validMemberAttrs([
        'first_name' => 'SinTelefono',
        'phone' => null,
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'SinTelefono')->exists())->toBeTrue();
});

it('accepts an existing organization_id (the nullable FK resolves when present)', function (): void {
    $organization = Organization::factory()->create();

    post(route('membership.store'), validMemberAttrs([
        'first_name' => 'ConOrg',
        'organization_id' => $organization->getKey(),
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'ConOrg')
        ->where('organization_id', $organization->getKey())->exists())->toBeTrue();
});
