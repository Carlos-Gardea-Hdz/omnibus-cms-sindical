<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $name */
        $name = Str::limit(fake()->unique()->streetName(), 90, '');

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'location' => Str::limit(fake()->address(), 95, ''),
        ];
    }

    /** Pin the branch to an existing organization (crown-test / scoping fixtures). */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }
}
