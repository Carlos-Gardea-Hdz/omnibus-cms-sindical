<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $name */
        $name = fake()->unique()->company();
        $name = Str::limit($name, 90, '');

        return [
            'municipality_id' => Municipality::factory(),
            'director_id' => null, // wired by the Director Actions/factory, not on create
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'logo_path' => null,
            'registered_at' => fake()->dateTimeBetween('-10 years', 'now')->format('Y-m-d'),
        ];
    }

    /** An organization with a logo already on disk (path only — no physical file). */
    public function withLogo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'logo_path' => 'organizations/logos/'.Str::random(20).'.webp',
        ]);
    }
}
