<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Analytics\Models\DailySnapshot;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailySnapshot>
 *
 * FICTIONAL data ONLY. Faker counts; the [organization_id, date] pair is unique per
 * (org, day) — the factory pairs a fresh org with a recent date by default. The table +
 * model ship for schema completeness (Decision F (i)); the populating job is DEFERRED, so
 * this factory exists for arch/schema tests and a future snapshot-reading slice.
 */
final class DailySnapshotFactory extends Factory
{
    protected $model = DailySnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'date' => fake()->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
            'articles_count' => fake()->numberBetween(0, 50),
            'jobs_count' => fake()->numberBetween(0, 20),
            'members_count' => fake()->numberBetween(0, 100),
            'contact_messages_count' => fake()->numberBetween(0, 40),
            'page_views_count' => fake()->numberBetween(0, 500),
        ];
    }

    /** Pin the snapshot to an existing organization. */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }
}
