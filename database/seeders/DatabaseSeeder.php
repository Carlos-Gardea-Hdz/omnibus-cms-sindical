<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Organization structure first: municipalities are the FK target of
        // organizations, and an org-dependent user/article needs an org to exist.
        // Both run UNCONFINED (no org.scope in CLI) — the OrganizationScope is a
        // no-op here.
        $this->call([
            MunicipalitySeeder::class,
            OrganizationSeeder::class,
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
