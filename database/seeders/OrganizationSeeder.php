<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * One or two fictional demo organizations, each with a couple of branches and a
 * director (slice-003). All content is invented — no real names / data (PII rules).
 * Runs AFTER MunicipalitySeeder (it needs municipality rows as the FK target) and
 * BEFORE any org-dependent seed. Runs UNCONFINED (no org.scope in CLI), so the global
 * OrganizationScope is a no-op here.
 */
final class OrganizationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $municipalities = Municipality::query()->get();

        if ($municipalities->isEmpty()) {
            return;
        }

        $demoOrganizations = [
            ['name' => 'Sindicato Demo Norte', 'branches' => ['Sucursal Centro', 'Sucursal Periferia']],
            ['name' => 'Sindicato Demo Sur', 'branches' => ['Sucursal Principal']],
        ];

        foreach ($demoOrganizations as $index => $definition) {
            /** @var Municipality $municipality */
            $municipality = $municipalities[$index % $municipalities->count()];

            $organization = Organization::factory()
                ->for($municipality)
                ->create(['name' => $definition['name']]);

            foreach ($definition['branches'] as $branchName) {
                Branch::factory()
                    ->forOrganization($organization)
                    ->create(['name' => $branchName]);
            }

            $director = Director::factory()
                ->forOrganization($organization)
                ->create();

            $organization->forceFill(['director_id' => $director->getKey()])->save();
        }
    }
}
