<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Organization\Actions\CreateBranchAction;
use App\Domain\Organization\Actions\DeleteBranchAction;
use App\Domain\Organization\Actions\UpdateBranchAction;
use App\Domain\Organization\Data\BranchData;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch CRUD (SPEC §3.2, §7.4, §11.4 / slice-003 §9). Branch is org-scoped: the listing
 * is auto-filtered by the {@see \App\Support\OrganizationScope} global scope under the
 * `org.scope` middleware — a confined manager sees ONLY their org's branches (no manual
 * `where`), and a cross-org route-model-bound branch is unresolvable → 404. Gated
 * `role:manager` upstream in routes/web.php.
 *
 * Anemic by law: each mutation hands a validated {@see BranchData} (resolved via the
 * method signature → web failure is 302 + session errors, never 422) to its Action. The
 * destroy path is load-bearing (§11.4 #2/#3): {@see DeleteBranchAction} application-level
 * cascades a representative-free branch (soft-deletes its articles + removes their files),
 * but a branch that still holds representatives makes it throw
 * {@see \App\Domain\Organization\Exceptions\BranchHasRepresentativesException} — rendered
 * to a graceful 302 + `branch` field error in bootstrap/app.php, never a 500.
 */
final class BranchController extends Controller
{
    public function index(): Response
    {
        $branches = Branch::query()
            ->with('organization:id,name')
            ->withCount('representatives')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'location' => $branch->location,
                'organization_name' => $branch->organization->name,
                'representative_count' => is_numeric($count = $branch->getAttribute('representatives_count')) ? (int) $count : 0,
            ]);

        return Inertia::render('Branches/Index', [
            'branches' => [
                'data' => $branches->items(),
                'links' => $branches->linkCollection()->toArray(),
                'meta' => [
                    'from' => $branches->firstItem(),
                    'to' => $branches->lastItem(),
                    'total' => $branches->total(),
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Branches/Create', [
            'organizations' => $this->organizationOptions(),
        ]);
    }

    public function store(BranchData $data, CreateBranchAction $action): RedirectResponse
    {
        $action->handle($data);

        return redirect()->route('admin.branches.index')->with('success', __('branches.created'));
    }

    public function edit(Branch $branch): Response
    {
        return Inertia::render('Branches/Edit', [
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'location' => $branch->location,
                'organization_id' => $branch->organization_id,
            ],
            'organizations' => $this->organizationOptions(),
        ]);
    }

    public function update(Branch $branch, BranchData $data, UpdateBranchAction $action): RedirectResponse
    {
        $action->handle($branch, $data);

        return redirect()->route('admin.branches.index')->with('success', __('branches.updated'));
    }

    public function destroy(Branch $branch, DeleteBranchAction $action): RedirectResponse
    {
        $action->handle($branch);

        return redirect()->route('admin.branches.index')->with('success', __('branches.deleted'));
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
}
