<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\from;

uses(RefreshDatabase::class);

/*
 * THE ANONYMOUS-WRITE-HARDENING CROWN — adapted (CONTRACT §12.3, SPEC §3.5). The
 * highest-stakes attack on a public, unauthenticated endpoint is a submitter planting a
 * value the server should own. contact_messages has NO moderation/status column (Decision
 * B) — so there is literally nothing to self-elevate — but the guarantee is BROADER: a
 * hostile payload with extra/unknown keys (a forged id / created_at / an invented
 * status / is_spam / organization_id-bypass) MUST be inert. Spatie Data discards keys it
 * does not declare AND SubmitContactAction persists ONLY the defined fields, so a
 * submitter can inject NOTHING into ANY column. The row still lands with a server-assigned
 * id + framework timestamps and the honest, declared provenance. Runs on PostgreSQL 18
 * (RefreshDatabase). All fixtures are FICTIONAL.
 */

it('ignores forged id / created_at / invented status & is_spam keys (a submitter injects nothing)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    $forgedTimestamp = '1999-01-01 00:00:00';

    from(route('home'))
        ->post(route('contact.store'), [
            'first_name' => 'Tamper',
            'last_name' => 'Hostile',
            'email' => 'tamper.fictional@example.com',
            'phone' => '5512345678',
            'message' => 'Intento de inyectar columnas que no me pertenecen.',
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            // Hostile, undeclared keys — every one must be discarded.
            'id' => 999_999,
            'created_at' => $forgedTimestamp,
            'updated_at' => $forgedTimestamp,
            'status' => 'approved',
            'is_spam' => true,
            'is_read' => true,
        ])
        ->assertStatus(302)
        ->assertSessionHas('success', __('contact.submitted'))
        ->assertSessionHasNoErrors();

    $message = ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Tamper')->sole();

    // The forged id was discarded — the row carries a real auto-increment key.
    expect($message->getKey())->not->toBe(999_999)
        ->and($message->getKey())->toBeGreaterThan(0);

    // The forged timestamps were discarded — created_at is "now", never 1999.
    expect($message->created_at->year)->toBe(Carbon::now()->year)
        ->and($message->created_at->toDateTimeString())->not->toBe($forgedTimestamp);

    // No invented column was created on the model from the hostile keys.
    expect($message->getAttributes())->not->toHaveKey('status')
        ->and($message->getAttributes())->not->toHaveKey('is_spam')
        ->and($message->getAttributes())->not->toHaveKey('is_read');

    // The honest, declared provenance + PII are intact.
    expect($message->organization_id)->toBe($organization->getKey())
        ->and($message->branch_id)->toBe($branch->getKey())
        ->and($message->email)->toBe('tamper.fictional@example.com');
});

it('cannot inject an arbitrary column value through a non-fillable key', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    from(route('home'))
        ->post(route('contact.store'), [
            'first_name' => 'Mass',
            'last_name' => 'Assignment',
            'email' => 'mass.fictional@example.com',
            'phone' => '5512345678',
            'message' => 'Probando asignación masiva.',
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            // A second org the attacker does NOT belong to — must never override provenance.
            'foreign_key_injection' => 12345,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    $message = ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Mass')->sole();

    // Only the seven declared columns were written; the injected key never reached the DB.
    expect($message->getAttributes())->not->toHaveKey('foreign_key_injection')
        ->and($message->organization_id)->toBe($organization->getKey());
});
