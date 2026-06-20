<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Organization\Actions\CreateDirectorAction;
use App\Domain\Organization\Actions\DeleteDirectorAction;
use App\Domain\Organization\Actions\UpdateDirectorAction;
use App\Domain\Organization\Data\DirectorData;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Director CRUD (SPEC §3.2, §7.3 / slice-003 §9). Exactly one director per organization
 * (a unique FK), wired onto `organizations.director_id` by the Actions. Gated
 * `role:administrator` upstream in routes/web.php — admin+ runs UNCONFINED, so the
 * org-scoped Director listing here spans every organization.
 *
 * Anemic by law: each mutation hands a validated {@see DirectorData} (resolved via the
 * method signature → web failure is 302 + session errors, never 422) to its Action,
 * which owns the photo storage and the one-per-org guard. A second director for an org
 * makes {@see CreateDirectorAction} throw
 * {@see \App\Domain\Organization\Exceptions\DirectorAlreadyAssignedException} — rendered
 * to a graceful 302 + `director` field error in bootstrap/app.php, never a 500.
 */
final class DirectorController extends Controller
{
    public function index(): Response
    {
        $directors = Director::query()
            ->with('organization:id,name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Director $director): array => [
                'id' => $director->id,
                'first_name' => $director->first_name,
                'last_name' => $director->last_name,
                'organization_name' => $director->organization->name,
                'photo_url' => $this->imageUrl($director->photo_path),
            ]);

        return Inertia::render('Directors/Index', [
            'directors' => [
                'data' => $directors->items(),
                'links' => $directors->linkCollection()->toArray(),
                'meta' => [
                    'from' => $directors->firstItem(),
                    'to' => $directors->lastItem(),
                    'total' => $directors->total(),
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Directors/Create', [
            'organizations' => $this->organizationOptions(),
        ]);
    }

    public function store(DirectorData $data, CreateDirectorAction $action): RedirectResponse
    {
        $action->handle($data);

        return redirect()->route('admin.directors.index')->with('success', __('directors.created'));
    }

    public function edit(Director $director): Response
    {
        return Inertia::render('Directors/Edit', [
            'director' => [
                'id' => $director->id,
                'first_name' => $director->first_name,
                'last_name' => $director->last_name,
                'organization_id' => $director->organization_id,
                'photo_url' => $this->imageUrl($director->photo_path),
            ],
            'organizations' => $this->organizationOptions(),
        ]);
    }

    public function update(Director $director, DirectorData $data, UpdateDirectorAction $action): RedirectResponse
    {
        $action->handle($director, $data);

        return redirect()->route('admin.directors.index')->with('success', __('directors.updated'));
    }

    public function destroy(Director $director, DeleteDirectorAction $action): RedirectResponse
    {
        $action->handle($director);

        return redirect()->route('admin.directors.index')->with('success', __('directors.deleted'));
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

    /** Resolve a stored image path to a public URL (null stays null). */
    private function imageUrl(?string $path): ?string
    {
        return $path === null ? null : Storage::disk('public')->url($path);
    }
}
