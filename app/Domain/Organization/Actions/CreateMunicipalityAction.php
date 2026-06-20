<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\MunicipalityData;
use App\Domain\Organization\Models\Municipality;

/**
 * Create a Municipality (SPEC §6.3.1). A single-table write — no transaction needed.
 */
final class CreateMunicipalityAction
{
    public function handle(MunicipalityData $data): Municipality
    {
        return Municipality::create([
            'name' => $data->name,
            'state' => $data->state,
        ]);
    }
}
