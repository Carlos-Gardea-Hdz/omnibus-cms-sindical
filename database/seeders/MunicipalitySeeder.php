<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organization\Models\Municipality;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Fictional municipality catalog (slice-003, spec.md Appendix). Names are INVENTED —
 * NOT real municipalities tied to real people (PII rules). The set is the FK target
 * of organizations.municipality_id, so this seeder runs BEFORE OrganizationSeeder.
 */
final class MunicipalitySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $municipalities = [
            ['name' => 'Ciudad Norte', 'state' => 'Estado Demo'],
            ['name' => 'Villa Sur', 'state' => 'Estado Demo'],
            ['name' => 'Puerto Centro', 'state' => 'Estado Demo'],
            ['name' => 'San Ejemplo', 'state' => 'Estado Demo'],
            ['name' => 'Lago Modelo', 'state' => 'Estado Demo'],
        ];

        foreach ($municipalities as $municipality) {
            Municipality::query()->firstOrCreate(
                ['name' => $municipality['name']],
                ['state' => $municipality['state']],
            );
        }
    }
}
