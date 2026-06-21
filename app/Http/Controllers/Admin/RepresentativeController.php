<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Organization\Actions\CreateRepresentativeAction;
use App\Domain\Organization\Actions\DeleteRepresentativeAction;
use App\Domain\Organization\Actions\UpdateRepresentativeAction;
use App\Domain\Organization\Data\RepresentativeData;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Representative;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Representative CRUD (SPEC §3.2, §7.4 / slice-003 §9). Representative is org-scoped: the
 * listing AND the branch picker are auto-filtered by the {@see \App\Support\OrganizationScope}
 * global scope under the `org.scope` middleware — a confined manager sees ONLY their org's
 * representatives and branches (no manual `where`), and a cross-org route-model-bound row is
 * unresolvable → 404. Gated `role:manager` upstream in routes/web.php.
 *
 * ORG ISOLATION (defense-in-depth, mirrors AnalyticsController/UserController): an
 * administrator runs UNCONFINED through the global scope, so the index ALSO applies an
 * EXPLICIT own-org `where` for any non-super_admin actor (administrator = own-org per the
 * §10.2 RBAC matrix; super_admin alone is cross-org). A manager/editor is already confined
 * by the global scope, so the explicit filter is a harmless no-op for it.
 *
 * Anemic by law: each mutation hands a validated {@see RepresentativeData} (resolved via the
 * method signature → web failure is 302 + session errors, never 422) to its Action, which
 * owns the photo storage. The {@see \App\Domain\Organization\Enums\RepresentativeShift} cast
 * surfaces a `shift` value + `shift_label_key` for the i18n label — no magic strings.
 */
final class RepresentativeController extends Controller
{
    public function index(): Response
    {
        $actor = request()->user();
        $ownOrgId = $actor instanceof User && $actor->role !== UserRole::SuperAdmin
            ? $actor->organization_id
            : null;

        $representatives = Representative::query()
            ->when($ownOrgId !== null, fn ($query) => $query->where('organization_id', $ownOrgId))
            ->with(['organization:id,name', 'branch:id,name'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Representative $representative): array => [
                'id' => $representative->id,
                'first_name' => $representative->first_name,
                'last_name' => $representative->last_name,
                'shift' => $representative->shift->value,
                'shift_label_key' => $representative->shift->labelKey(),
                'is_coordinator' => $representative->is_coordinator,
                'organization_name' => $representative->organization->name,
                'branch_name' => $representative->branch->name,
            ]);

        return Inertia::render('Representatives/Index', [
            'representatives' => [
                'data' => $representatives->items(),
                'links' => $representatives->linkCollection()->toArray(),
                'meta' => [
                    'from' => $representatives->firstItem(),
                    'to' => $representatives->lastItem(),
                    'total' => $representatives->total(),
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Representatives/Create', [
            'organizations' => $this->organizationOptions(),
            'branches' => $this->branchOptions(),
        ]);
    }

    public function store(RepresentativeData $data, CreateRepresentativeAction $action): RedirectResponse
    {
        $action->handle($data);

        return redirect()->route('admin.representatives.index')->with('success', __('representatives.created'));
    }

    public function edit(Representative $representative): Response
    {
        return Inertia::render('Representatives/Edit', [
            'representative' => [
                'id' => $representative->id,
                'first_name' => $representative->first_name,
                'last_name' => $representative->last_name,
                'organization_id' => $representative->organization_id,
                'branch_id' => $representative->branch_id,
                'shift' => $representative->shift->value,
                'is_coordinator' => $representative->is_coordinator,
            ],
            'organizations' => $this->organizationOptions(),
            'branches' => $this->branchOptions(),
        ]);
    }

    public function update(Representative $representative, RepresentativeData $data, UpdateRepresentativeAction $action): RedirectResponse
    {
        $action->handle($representative, $data);

        return redirect()->route('admin.representatives.index')->with('success', __('representatives.updated'));
    }

    public function destroy(Representative $representative, DeleteRepresentativeAction $action): RedirectResponse
    {
        $action->handle($representative);

        return redirect()->route('admin.representatives.index')->with('success', __('representatives.deleted'));
    }

    /**
     * The organization select options shared by create/edit (id + name only).
     *
     * @return list<array{id: int, name: string}>
     */
    private function organizationOptions(): array
    {
        return array_values(
            Organization::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ])->all()
        );
    }

    /**
     * The branch select options for the form (id + name + org id). Org-scoped, so a
     * confined manager only ever sees their own org's branches (UI filters by org id).
     *
     * @return list<array{id: int, name: string, organization_id: int}>
     */
    private function branchOptions(): array
    {
        return array_values(
            Branch::query()
                ->orderBy('name')
                ->get(['id', 'name', 'organization_id'])
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'organization_id' => $branch->organization_id,
                ])->all()
        );
    }
}
