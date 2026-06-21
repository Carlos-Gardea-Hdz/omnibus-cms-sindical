<?php

declare(strict_types=1);

use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Municipality;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * PII-at-rest encryption for members (CONTRACT §1/§3/§14, SPEC §10.5 §11.4). The curp
 * and rfc columns use the Eloquent `encrypted` cast (TEXT, because ciphertext is longer
 * than the plaintext). The DB-shape guarantee: a RAW row read (bypassing the model cast,
 * e.g. DB::table) returns CIPHERTEXT — it must NOT equal the plaintext the user typed.
 * The accessor guarantee: reading $member->curp / $member->rfc THROUGH the model
 * decrypts back to the original plaintext. Both directions are proven against a real
 * registration on PostgreSQL 18. The fixture PII is FICTIONAL (pattern-valid, never real).
 */

/** A valid FICTIONAL registration payload with known PII to assert round-trips. */
function piiRegistrationPayload(string $curp, string $rfc): array
{
    return [
        'first_name' => 'Encriptado',
        'last_name_paternal' => 'Apellido',
        'last_name_maternal' => 'Materno',
        'curp' => $curp,
        'rfc' => $rfc,
        'date_of_birth' => '1992-07-07',
        'municipality_id' => Municipality::factory()->create()->getKey(),
        'address' => 'Domicilio Ficticio 1',
        'postal_code' => '54321',
        'neighborhood' => 'Barrio Demo',
        'mobile' => '5500110022',
        'phone' => null,
    ];
}

it('stores curp/rfc as ciphertext at rest: a raw DB read NEVER equals the plaintext', function (): void {
    $curp = 'XEXX010101HNEXXXA4'; // FICTIONAL
    $rfc = 'XEXX010101000';       // FICTIONAL

    post(route('membership.store'), piiRegistrationPayload($curp, $rfc))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $member = Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Encriptado')->sole();

    // RAW column value (bypasses the model cast) — must be CIPHERTEXT, not the plaintext.
    $rawCurp = DB::table('members')->where('id', $member->getKey())->value('curp');
    $rawRfc = DB::table('members')->where('id', $member->getKey())->value('rfc');

    expect($rawCurp)->not->toBe($curp)
        ->and($rawRfc)->not->toBe($rfc)
        // Ciphertext is a non-empty string longer than the plaintext (Laravel envelope).
        ->and($rawCurp)->toBeString()->not->toBe('')
        ->and(mb_strlen((string) $rawCurp))->toBeGreaterThan(mb_strlen($curp));
});

it('decrypts curp/rfc back to the original plaintext when read through the model accessor', function (): void {
    $curp = 'XEXX010101MNEXXXA8'; // FICTIONAL
    $rfc = 'ABC010101AB1';        // FICTIONAL (persona moral)

    post(route('membership.store'), piiRegistrationPayload($curp, $rfc))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $member = Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Encriptado')->sole();

    // THROUGH the model: the cast decrypts the ciphertext back to the typed plaintext.
    expect($member->curp)->toBe($curp)
        ->and($member->rfc)->toBe($rfc);
});
