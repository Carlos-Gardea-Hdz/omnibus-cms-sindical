<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 * NO PUBLIC READ of contact messages (CONTRACT §12.9, SPEC §3.5 / §10.5). Contact
 * submissions carry sender PII (first_name / last_name / email / phone) and must NEVER be
 * exposed on a public path — the inbox is admin-only (CONTACT-02, Manager+). This is the
 * structural guard:
 *
 *   - the ONLY public `contact.*` route is `contact.store` (a POST write) — there is no
 *     public GET that returns contact-message data;
 *   - every route whose name reads contact messages (admin.contacts.*) sits behind the
 *     'auth' middleware (no anonymous read path exists);
 *   - the admin.contacts.index route carries 'auth' + 'role:manager' + 'org.scope'.
 *
 * Pure route-table introspection + a guest probe — no business behaviour. Runs on
 * PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

it('exposes contact.store as the ONLY public contact route, and it is a POST write (not a read)', function (): void {
    $contactRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'contact.'));

    // The single public contact endpoint is the store write.
    expect($contactRoutes)->toHaveCount(1);

    $store = $contactRoutes->first();
    expect($store->getName())->toBe('contact.store')
        ->and($store->methods())->toContain('POST')
        ->and($store->methods())->not->toContain('GET');
});

it('guards every contact-message READ route behind auth (no anonymous inbox)', function (): void {
    $adminContactRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.contacts.'));

    // There IS an admin read route, and it is gated.
    expect($adminContactRoutes)->not->toBeEmpty();

    $adminContactRoutes->each(function ($route): void {
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain('auth')
            ->and($middleware)->toContain('role:manager')
            ->and($middleware)->toContain('org.scope');
    });
});

it('a guest hitting the admin contact inbox is bounced to login, never served the data', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    ContactMessage::factory()->forOrganization($organization)->forBranch($branch)->create([
        'email' => 'should-never-leak.fictional@example.com',
    ]);

    $response = $this->get(route('admin.contacts.index'));

    $response->assertRedirect(route('login'))->assertStatus(302);
    // The PII string is never present in the guest response body.
    expect($response->getContent())->not->toContain('should-never-leak.fictional@example.com');
});
