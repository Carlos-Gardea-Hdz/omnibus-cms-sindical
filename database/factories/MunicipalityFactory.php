<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organization\Models\Municipality;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Municipality>
 */
final class MunicipalityFactory extends Factory
{
    protected $model = Municipality::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'state' => fake()->state(),
        ];
    }
}
