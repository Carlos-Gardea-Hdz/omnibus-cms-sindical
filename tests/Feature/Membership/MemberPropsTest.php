<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Runtime prop-contract tests for the Membership Inertia pages (CONTRACT §11/§14).
 * Inertia props are untyped at runtime, so the static gates cannot catch a controller
 * that serialises a different shape than the React page consumes. These lock the EXACT
 * snake_case prop shape each page receives — a future controller/page drift fails CI.
 * The admin acting user is super_admin (unconfined) so the index lists every org's
 * rows. CRUCIALLY the Admin/Members/Index row carries NO curp/rfc (Decision E — PII is
 * NEVER serialised to the review table; it lives encrypted, admin-detail only). Runs on
 * PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

/** A super_admin sees every org's rows (unconfined) — the simplest admin fixture. */
function memberPropsAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

it('locks the Admin/Members/Index prop contract (paginated snake_case MemberRow + statuses + filters)', function (): void {
    $organization = Organization::factory()->create(['name' => 'Org Index']);
    $municipality = Municipality::factory()->create(['name' => 'Municipio Centro']);
    $member = Member::factory()
        ->forOrganization($organization)
        ->forMunicipality($municipality)
        ->pending()
        ->create([
            'first_name' => 'Mario',
            'last_name_paternal' => 'Bros',
            'last_name_maternal' => 'Nintendo',
        ]);

    actingAs(memberPropsAdmin())
        ->get(route('admin.members.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Members/Index')
                ->has('members.data', 1)
                ->where('members.data.0.id', $member->getKey())
                ->where('members.data.0.full_name', 'Mario Bros Nintendo')
                ->where('members.data.0.municipality_name', 'Municipio Centro')
                ->where('members.data.0.status', 'pending')
                ->where('members.data.0.status_label_key', 'member_status.pending')
                ->where('members.data.0.is_affiliated', false)
                ->hasAll([
                    'members.data.0.id',
                    'members.data.0.full_name',
                    'members.data.0.municipality_name',
                    'members.data.0.status',
                    'members.data.0.status_label_key',
                    'members.data.0.is_affiliated',
                    'members.data.0.created_at',
                ])
                // SECURITY (Decision E): the review table NEVER serialises PII.
                ->missing('members.data.0.curp')
                ->missing('members.data.0.rfc')
                ->missing('members.data.0.date_of_birth')
                ->missing('members.data.0.address')
                ->has('members.links')
                ->has('members.meta')
                ->has('statuses')
                ->has('filters'),
        );
});

it('serialises the approved status + affiliation flag on an approved member row', function (): void {
    $member = Member::factory()->approved()->create();

    actingAs(memberPropsAdmin())
        ->get(route('admin.members.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('members.data.0.id', $member->getKey())
                ->where('members.data.0.status', MemberStatus::Approved->value)
                ->where('members.data.0.status_label_key', 'member_status.approved')
                ->where('members.data.0.is_affiliated', true),
        );
});

it('locks the public Membership/Register prop contract (municipality + organization options)', function (): void {
    Municipality::factory()->create(['name' => 'Municipio Registro']);
    Organization::factory()->create(['name' => 'Org Registro']);

    get(route('membership.create'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Membership/Register')
                ->has('municipality_options')
                ->has(
                    'municipality_options.0',
                    fn (AssertableInertia $option): AssertableInertia => $option
                        ->has('id')
                        ->has('name'),
                )
                ->has('organization_options')
                ->has(
                    'organization_options.0',
                    fn (AssertableInertia $option): AssertableInertia => $option
                        ->has('id')
                        ->has('name'),
                ),
        );
});
