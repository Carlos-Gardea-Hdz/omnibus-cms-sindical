<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Director>
 *
 * One director per organization (DB unique on organization_id) — a fresh
 * Organization::factory() per director by default keeps that invariant. Use
 * forOrganization() to pin to an existing org (still one per org).
 */
final class DirectorFactory extends Factory
{
    protected $model = Director::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'photo_path' => null,
        ];
    }

    /** Pin the director to an existing organization (one per org). */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }
}
