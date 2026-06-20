<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JobPosting>
 */
final class JobPostingFactory extends Factory
{
    protected $model = JobPosting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = Organization::factory();

        // Integer-cents salary band with min <= max (SPEC §5.5 — never float).
        $minCents = fake()->numberBetween(8_000_00, 18_000_00);
        $maxCents = $minCents + fake()->numberBetween(2_000_00, 12_000_00);

        /** @var string $title */
        $title = fake()->jobTitle();

        /** @var string $description */
        $description = fake()->paragraphs(2, true);

        /** @var string $contact */
        $contact = fake()->companyEmail();

        /** @var string $schedule */
        $schedule = fake()->randomElement([
            'Lunes a viernes, 9:00 - 18:00',
            'Turno matutino, 6:00 - 14:00',
            'Turno vespertino, 14:00 - 22:00',
            'Fines de semana, 8:00 - 16:00',
        ]);

        return [
            'organization_id' => $organization,
            'branch_id' => Branch::factory()->for($organization),
            'created_by' => User::factory()->for($organization),
            'title' => Str::limit($title, 90, ''),
            'description' => $description,
            'schedule' => Str::limit($schedule, 95, ''),
            'contact_info' => Str::limit($contact, 95, ''),
            'salary_min_cents' => $minCents,
            'salary_max_cents' => $maxCents,
            'salary_display' => '$'.number_format($minCents / 100, 0).' - $'.number_format($maxCents / 100, 0),
            'status' => JobStatus::Active,
        ];
    }

    /** Pin the posting to an existing organization (crown-test / scoping fixtures). */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
            'branch_id' => Branch::factory()->for($organization),
            'created_by' => User::factory()->for($organization),
        ]);
    }

    /** Pin the posting to an existing branch (and its organization). */
    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->getKey(),
        ]);
    }

    /** Stamp the posting's author (a user of the posting's organization). */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_by' => $user->getKey(),
        ]);
    }

    /** A live posting (the default). */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => JobStatus::Active,
        ]);
    }

    /** An unpublished draft posting. */
    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => JobStatus::Draft,
        ]);
    }

    /** A temporarily paused posting. */
    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => JobStatus::Paused,
        ]);
    }

    /** A terminal, closed posting. */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => JobStatus::Closed,
        ]);
    }
}
