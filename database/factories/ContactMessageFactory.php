<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContactMessage>
 *
 * FICTIONAL data ONLY (PII rules). Names, email, phone and message are faker-generated —
 * never a real person's contact data. The org/branch pairing mirrors JobPostingFactory: by
 * default the branch is created FOR the same organization, so the branch→org invariant the
 * Submit Action asserts always holds in fixtures. email is capped at 60 and message at 1000
 * to match the column widths + DTO rules.
 */
final class ContactMessageFactory extends Factory
{
    protected $model = ContactMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = Organization::factory();

        /** @var string $firstName */
        $firstName = fake()->firstName();

        /** @var string $lastName */
        $lastName = fake()->lastName();

        /** @var string $email */
        $email = fake()->safeEmail();

        /** @var string $message */
        $message = fake()->paragraph();

        return [
            'organization_id' => $organization,
            'branch_id' => Branch::factory()->for($organization),
            'first_name' => mb_substr($firstName, 0, 60),
            'last_name' => mb_substr($lastName, 0, 60),
            'email' => Str::limit($email, 60, ''),
            'phone' => fake()->numerify('##########'),
            'message' => Str::limit($message, 1000, ''),
        ];
    }

    /** Pin the message to an existing organization (and a fresh branch of it). */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
            'branch_id' => Branch::factory()->for($organization),
        ]);
    }

    /** Pin the message to an existing branch (and its organization — invariant holds). */
    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->getKey(),
        ]);
    }
}
