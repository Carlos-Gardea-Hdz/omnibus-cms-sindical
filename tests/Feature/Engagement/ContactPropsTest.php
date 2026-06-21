<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Runtime prop-contract test for the admin contact inbox (CONTRACT §7/§12.10). Inertia
 * props are untyped at runtime, so the static gates cannot catch a controller that
 * serialises a different shape than the React page consumes. This locks the EXACT
 * snake_case prop shape Admin/Contacts/Index receives — a future controller/page drift
 * fails CI. The acting user is super_admin (unconfined) so the inbox lists every org's
 * rows. The inbox DOES serialise email + phone — replying is the message's whole purpose,
 * scoped to the org, NOT a public leak (contrast Membership's review table, which hides
 * PII). The message is a plain TEXT scalar (no rich-HTML / SanitizesContent). Runs on
 * PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

it('locks the Admin/Contacts/Index prop contract (paginated snake_case ContactRow + filters)', function (): void {
    $organization = Organization::factory()->create(['name' => 'Org Inbox']);
    $branch = Branch::factory()->for($organization)->create(['name' => 'Sucursal Centro']);
    $message = ContactMessage::factory()
        ->forOrganization($organization)
        ->forBranch($branch)
        ->create([
            'first_name' => 'Mario',
            'last_name' => 'Bros',
            'email' => 'mario.fictional@example.com',
            'phone' => '5512345678',
            'message' => 'Mensaje de contacto de prueba.',
        ]);

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Contacts/Index')
                ->has('messages.data', 1)
                ->where('messages.data.0.id', $message->getKey())
                ->where('messages.data.0.full_name', 'Mario Bros')
                ->where('messages.data.0.email', 'mario.fictional@example.com')
                ->where('messages.data.0.phone', '5512345678')
                ->where('messages.data.0.branch_name', 'Sucursal Centro')
                ->where('messages.data.0.message', 'Mensaje de contacto de prueba.')
                ->hasAll([
                    'messages.data.0.id',
                    'messages.data.0.full_name',
                    'messages.data.0.email',
                    'messages.data.0.phone',
                    'messages.data.0.branch_name',
                    'messages.data.0.message',
                    'messages.data.0.created_at',
                ])
                ->has('messages.links')
                ->has('messages.meta')
                ->has('messages.meta.from')
                ->has('messages.meta.to')
                ->has('messages.meta.total')
                ->has('filters')
                ->has('filters.organization_id'),
        );
});

it('serialises created_at as an ISO-8601 string the React table can render', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    ContactMessage::factory()->forOrganization($organization)->forBranch($branch)->create([
        'first_name' => 'Fecha',
    ]);

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('messages.data.0.created_at', fn (mixed $value): bool => is_string($value) && $value !== ''),
        );
});
