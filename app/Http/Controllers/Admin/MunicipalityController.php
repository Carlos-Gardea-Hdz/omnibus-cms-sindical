<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Organization\Actions\CreateMunicipalityAction;
use App\Domain\Organization\Actions\DeleteMunicipalityAction;
use App\Domain\Organization\Actions\UpdateMunicipalityAction;
use App\Domain\Organization\Data\MunicipalityData;
use App\Domain\Organization\Models\Municipality;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Municipalities catalog CRUD (SPEC §3.2, §7.3 / slice-003 §9). A shared catalog —
 * NOT org-scoped — so the listing is the full baseline for every admin. Inline CRUD
 * on a single page, mirroring the Content {@see CategoryController}/Categories/Index.
 *
 * Anemic by law: each mutation hands a validated {@see MunicipalityData} (resolved via
 * the method signature → web failure is 302 + session errors, never 422) to its Action.
 * The destroy path leans on {@see DeleteMunicipalityAction}'s in-use pre-check, which
 * throws {@see \App\Domain\Organization\Exceptions\MunicipalityInUseException} — rendered
 * to a graceful 302 + `municipality` field error in bootstrap/app.php, never a restrict-FK
 * 500. Gated `role:administrator` upstream in routes/web.php.
 */
final class MunicipalityController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Municipalities/Index', [
            'municipalities' => Municipality::query()
                ->withCount('organizations')
                ->orderBy('name')
                ->get()
                ->map(function (Municipality $municipality): array {
                    $count = $municipality->getAttribute('organizations_count');

                    return [
                        'id' => $municipality->id,
                        'name' => $municipality->name,
                        'state' => $municipality->state,
                        'organizations_count' => is_numeric($count) ? (int) $count : 0,
                    ];
                })->all(),
        ]);
    }

    public function store(MunicipalityData $data, CreateMunicipalityAction $action): RedirectResponse
    {
        $action->handle($data);

        return back()->with('success', __('municipalities.created'));
    }

    public function update(Municipality $municipality, MunicipalityData $data, UpdateMunicipalityAction $action): RedirectResponse
    {
        $action->handle($municipality, $data);

        return back()->with('success', __('municipalities.updated'));
    }

    public function destroy(Municipality $municipality, DeleteMunicipalityAction $action): RedirectResponse
    {
        $action->handle($municipality);

        return back()->with('success', __('municipalities.deleted'));
    }
}
