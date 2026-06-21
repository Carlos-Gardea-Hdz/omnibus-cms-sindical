<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\from;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * PUBLIC contact submission (CONTRACT §1/§5/§6/§12.1, SPEC §3.5 CONTACT-01, §7.1). The
 * SOLE create path for a contact message is the anonymous POST /contact — there is NO
 * admin create. SubmitContactAction takes NO OrganizationContext (Decision F): the public
 * caller is unconfined, so an unauthenticated visitor lands a row carrying the submitted
 * organization_id / branch_id (BOTH from the payload, both NOT NULL) and the sender's PII
 * (first_name / last_name / email / phone / message). The controller answers with
 * back()->with('success') — the form is a COMPONENT embedded in the landing/footer
 * (Decision G), so there is no standalone GET /contact page; the request carries a
 * referer (here route('home')) and the assertion is on the success flash + 302, NOT a
 * named redirect target. Web validation is 302 + session errors, never 422. Runs on
 * PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

/**
 * A fully-valid, FICTIONAL contact payload (snake_case, as the React ContactForm posts).
 * The org/branch are a consistent pair (the branch belongs to the org) so the only thing
 * a case ever varies is the field it intends to exercise.
 *
 * @return array<string, mixed>
 */
function contactPayload(Organization $organization, Branch $branch, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ana',
        'last_name' => 'Martínez',
        'email' => 'ana.fictional@example.com',
        'phone' => '5512345678',
        'message' => 'Quisiera más información sobre la afiliación sindical.',
        'organization_id' => $organization->getKey(),
        'branch_id' => $branch->getKey(),
    ], $overrides);
}

/**
 * A consistent org + one of its branches — the always-valid provenance pair.
 *
 * @return array{org: Organization, branch: Branch}
 */
function contactOrgPair(string $name = 'Org Contacto'): array
{
    $org = Organization::factory()->create(['name' => $name]);
    $branch = Branch::factory()->for($org)->create();

    return ['org' => $org, 'branch' => $branch];
}

it('lets an anonymous visitor submit a contact message: a row lands (302 + success flash)', function (): void {
    ['org' => $organization, 'branch' => $branch] = contactOrgPair();

    from(route('home'))
        ->post(route('contact.store'), contactPayload($organization, $branch, [
            'first_name' => 'Contacto',
        ]))
        ->assertStatus(302)
        ->assertSessionHas('success', __('contact.submitted'))
        ->assertSessionHasNoErrors();

    $message = ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Contacto')->sole();

    // The submitted provenance + sender PII are persisted exactly as posted (no org-stamp,
    // no server-owned field — the table has none).
    expect($message->organization_id)->toBe($organization->getKey())
        ->and($message->branch_id)->toBe($branch->getKey())
        ->and($message->first_name)->toBe('Contacto')
        ->and($message->last_name)->toBe('Martínez')
        ->and($message->email)->toBe('ana.fictional@example.com')
        ->and($message->phone)->toBe('5512345678')
        ->and($message->message)->toBe('Quisiera más información sobre la afiliación sindical.');
});

it('persists server-owned id + timestamps on a fresh submission (the row is its own terminal state)', function (): void {
    ['org' => $organization, 'branch' => $branch] = contactOrgPair();

    from(route('home'))
        ->post(route('contact.store'), contactPayload($organization, $branch, [
            'first_name' => 'Terminal',
        ]))
        ->assertSessionHas('success', __('contact.submitted'));

    $message = ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Terminal')->sole();

    // No status / moderation lifecycle exists; the persisted row simply carries a real
    // server id + framework timestamps — there is nothing else to be in a "pending" state.
    expect($message->getKey())->toBeInt()->toBeGreaterThan(0)
        ->and($message->created_at)->not->toBeNull()
        ->and($message->updated_at)->not->toBeNull();
});
