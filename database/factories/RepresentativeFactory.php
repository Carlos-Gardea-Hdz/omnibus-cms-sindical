<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organization\Enums\RepresentativeShift;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Representative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Representative>
 */
final class RepresentativeFactory extends Factory
{
    protected $model = Representative::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = Organization::factory();

        return [
            'organization_id' => $organization,
            'branch_id' => Branch::factory()->for($organization),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'shift' => fake()->randomElement(RepresentativeShift::cases()),
            'is_coordinator' => false,
            'photo_path' => null,
        ];
    }

    /** Pin the representative to an existing branch (and its organization). */
    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->getKey(),
        ]);
    }

    /** A given shift (readability in tests). */
    public function shift(RepresentativeShift $shift): static
    {
        return $this->state(fn (array $attributes): array => [
            'shift' => $shift,
        ]);
    }

    /** Mark the representative as a branch coordinator. */
    public function coordinator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_coordinator' => true,
        ]);
    }
}
