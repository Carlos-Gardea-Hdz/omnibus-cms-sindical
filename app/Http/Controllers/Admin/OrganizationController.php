<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Organization\Actions\CreateOrganizationAction;
use App\Domain\Organization\Actions\DeleteOrganizationAction;
use App\Domain\Organization\Actions\UpdateOrganizationAction;
use App\Domain\Organization\Data\OrganizationData;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization CRUD (SPEC §3.2 ORG-01, §7.3, §10.2 / slice-003 §9). The top-level
 * tenant record: super_admin-only (gated `role:super_admin` upstream in routes/web.php),
 * so this controller runs UNCONFINED — the `OrganizationScope` does not touch
 * Organization itself, and the `branch_count` derived here is the true cross-org count.
 *
 * Anemic by law (≤15 lines/method): each mutation hands a validated
 * {@see OrganizationData} (resolved via the method signature → web failure is 302 +
 * session errors, never 422) to its Action, which owns the slug derivation/uniqueness,
 * the logo storage, and the in-use guard. The destroy path leans on
 * {@see DeleteOrganizationAction}'s pre-check, which throws
 * {@see \App\Domain\Organization\Exceptions\OrganizationInUseException} — rendered to a
 * graceful 302 + `organization` field error in bootstrap/app.php, never a 500.
 */
final class OrganizationController extends Controller
{
    public function index(): Response
    {
        $organizations = Organization::query()
            ->with(['municipality:id,name', 'director:id,first_name,last_name'])
            ->withCount('branches')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Organization $organization): array => $this->mapRow($organization));

        return Inertia::render('Organizations/Index', [
            'organizations' => [
                'data' => $organizations->items(),
                'links' => $organizations->linkCollection()->toArray(),
                'meta' => [
                    'from' => $organizations->firstItem(),
                    'to' => $organizations->lastItem(),
                    'total' => $organizations->total(),
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Organizations/Create', [
            'municipalities' => $this->municipalityOptions(),
        ]);
    }

    public function store(OrganizationData $data, CreateOrganizationAction $action): RedirectResponse
    {
        $action->handle($data);

        return redirect()->route('admin.organizations.index')->with('success', __('organizations.created'));
    }

    public function edit(Organization $organization): Response
    {
        return Inertia::render('Organizations/Edit', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'municipality_id' => $organization->municipality_id,
                'logo_url' => $this->imageUrl($organization->logo_path),
                'registered_at' => $organization->registered_at->toDateString(),
            ],
            'municipalities' => $this->municipalityOptions(),
        ]);
    }

    public function update(Organization $organization, OrganizationData $data, UpdateOrganizationAction $action): RedirectResponse
    {
        $action->handle($organization, $data);

        return redirect()->route('admin.organizations.index')->with('success', __('organizations.updated'));
    }

    public function destroy(Organization $organization, DeleteOrganizationAction $action): RedirectResponse
    {
        $action->handle($organization);

        return redirect()->route('admin.organizations.index')->with('success', __('organizations.deleted'));
    }

    /**
     * Shape one paginated organization row for the admin index (snake_case contract).
     *
     * @return array<string, mixed>
     */
    private function mapRow(Organization $organization): array
    {
        // municipality is a NOT NULL restrict FK; director is a nullable set-null FK.
        $director = $organization->director;

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'municipality_name' => $organization->municipality->name,
            'branch_count' => is_numeric($count = $organization->getAttribute('branches_count')) ? (int) $count : 0,
            'director_name' => $director === null ? null : $director->first_name.' '.$director->last_name,
            'registered_at' => $organization->registered_at->toDateString(),
        ];
    }

    /**
     * The municipality select options shared by create/edit (id + name only).
     *
     * @return list<array{id: int, name: string}>
     */
    private function municipalityOptions(): array
    {
        return array_values(
            Municipality::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Municipality $municipality): array => [
                    'id' => $municipality->id,
                    'name' => $municipality->name,
                ])->all()
        );
    }

    /** Resolve a stored image path to a public URL (null stays null). */
    private function imageUrl(?string $path): ?string
    {
        return $path === null ? null : Storage::disk('public')->url($path);
    }
}
