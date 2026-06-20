<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\MunicipalityData;
use App\Domain\Organization\Models\Municipality;

/**
 * Update a Municipality (SPEC §6.3.1). A single-table write — no transaction needed.
 */
final class UpdateMunicipalityAction
{
    public function handle(Municipality $municipality, MunicipalityData $data): Municipality
    {
        $municipality->fill([
            'name' => $data->name,
            'state' => $data->state,
        ])->save();

        return $municipality;
    }
}
