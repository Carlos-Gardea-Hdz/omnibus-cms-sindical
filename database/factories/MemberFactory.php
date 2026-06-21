<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 *
 * FICTIONAL PII ONLY (PII rules). The CURP/RFC are pattern-valid but entirely FAKE —
 * built from random uppercase letters + fake digits to satisfy the CurpFormat/RfcFormat
 * regexes WITHOUT ever resembling a real person's identifier. Names, address and contact
 * data are faker-generated. NEVER a real CURP/RFC/name.
 */
final class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $firstName */
        $firstName = fake()->firstName();

        /** @var string $paternal */
        $paternal = fake()->lastName();

        /** @var string $maternal */
        $maternal = fake()->lastName();

        return [
            'organization_id' => Organization::factory(),
            'municipality_id' => Municipality::factory(),
            'curp' => $this->fakeCurp(),
            'rfc' => $this->fakeRfc(),
            'first_name' => mb_substr($firstName, 0, 100),
            'last_name_paternal' => mb_substr($paternal, 0, 100),
            'last_name_maternal' => mb_substr($maternal, 0, 100),
            'date_of_birth' => fake()->dateTimeBetween('-65 years', '-18 years')->format('Y-m-d'),
            'address' => fake()->streetAddress(),
            'postal_code' => fake()->numerify('#####'),
            'neighborhood' => mb_substr((string) fake()->streetName(), 0, 100),
            'phone' => fake()->numerify('##########'),
            'mobile' => fake()->numerify('##########'),
            'is_affiliated' => false,
            'status' => MemberStatus::Pending,
        ];
    }

    /**
     * A FAKE, pattern-valid CURP: ^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$
     * (4 letters · 6 digits · H/M · 5 letters · 1 alphanumeric · 1 digit).
     */
    private function fakeCurp(): string
    {
        return $this->letters(4)
            .fake()->numerify('######')
            .fake()->randomElement(['H', 'M'])
            .$this->letters(5)
            .fake()->randomElement(array_merge(range('A', 'Z'), range('0', '9')))
            .(string) fake()->numberBetween(0, 9);
    }

    /**
     * A FAKE, pattern-valid RFC (persona física, 13 chars):
     * ^[A-ZN&]{3,4}\d{6}[A-Z0-9]{3}$ — here the 4-letter leading form.
     */
    private function fakeRfc(): string
    {
        $homoclave = '';

        foreach (range(1, 3) as $ignored) {
            $homoclave .= fake()->randomElement(array_merge(range('A', 'Z'), range('0', '9')));
        }

        return $this->letters(4).fake()->numerify('######').$homoclave;
    }

    /** A run of $count uppercase A–Z letters. */
    private function letters(int $count): string
    {
        $value = '';

        foreach (range(1, $count) as $ignored) {
            $value .= fake()->randomElement(range('A', 'Z'));
        }

        return $value;
    }

    /** Pin the member to an existing organization (crown-test / scoping fixtures). */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }

    /** Pin the member to an existing municipality (the RESTRICT-delete regression). */
    public function forMunicipality(Municipality $municipality): static
    {
        return $this->state(fn (array $attributes): array => [
            'municipality_id' => $municipality->getKey(),
        ]);
    }

    /** An org-less member (organization_id NULL — the SET NULL / public-register case). */
    public function orgLess(): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => null,
        ]);
    }

    /** A pending registration awaiting review (the default). */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MemberStatus::Pending,
            'is_affiliated' => false,
        ]);
    }

    /** An approved, affiliated member. */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MemberStatus::Approved,
            'is_affiliated' => true,
        ]);
    }

    /** A rejected applicant (never affiliated). */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MemberStatus::Rejected,
            'is_affiliated' => false,
        ]);
    }

    /** An affiliated member (is_affiliated true, status untouched). */
    public function affiliated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_affiliated' => true,
        ]);
    }
}
