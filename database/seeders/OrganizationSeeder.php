<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * One or two fictional demo organizations, each with a couple of branches, a director,
 * and a few demo job postings (slice-003 + slice-004). All content is invented — no
 * real names / data (PII rules). Runs AFTER MunicipalitySeeder (it needs municipality
 * rows as the FK target) and BEFORE any org-dependent seed. Runs UNCONFINED (no
 * org.scope in CLI), so the global OrganizationScope is a no-op here.
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

            $branches = [];

            foreach ($definition['branches'] as $branchName) {
                $branches[] = Branch::factory()
                    ->forOrganization($organization)
                    ->create(['name' => $branchName]);
            }

            $director = Director::factory()
                ->forOrganization($organization)
                ->create();

            $organization->forceFill(['director_id' => $director->getKey()])->save();

            $this->seedJobs($organization, $branches[0]);
        }
    }

    /**
     * A few fictional demo job postings for an organization's first branch — one of
     * each lifecycle status so the public active-only board and the admin lifecycle UI
     * both have data. The author is a fresh editor of the organization. Invented
     * content only (PII rules).
     */
    private function seedJobs(Organization $organization, Branch $branch): void
    {
        $author = User::factory()->editor()->forOrganization($organization)->create();

        $factory = JobPosting::factory()
            ->forBranch($branch)
            ->createdBy($author);

        $factory->active()->create([
            'title' => 'Auxiliar administrativo',
            'salary_min_cents' => 12_000_00,
            'salary_max_cents' => 16_000_00,
            'salary_display' => '$12,000 - $16,000',
        ]);

        $factory->active()->create([
            'title' => 'Recepcionista de sucursal',
            'salary_min_cents' => 10_000_00,
            'salary_max_cents' => 13_000_00,
            'salary_display' => '$10,000 - $13,000',
        ]);

        $factory->draft()->create(['title' => 'Coordinador de logística (borrador)']);
        $factory->paused()->create(['title' => 'Vigilante de turno nocturno (pausada)']);
        $factory->closed()->create(['title' => 'Promotor de afiliación (cerrada)']);
    }
}
