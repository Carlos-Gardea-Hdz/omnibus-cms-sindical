<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Data;

use Closure;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * JobPosting create/update payload (editor-facing).
 *
 * Spatie Data is the single source of truth: server-side rules + the TS type.
 * FormRequests are prohibited. One route-agnostic DTO serves both store and update.
 *
 * organization_id / branch_id carry only Exists(...) here — the DTO is route-agnostic
 * and does NOT enforce the tenant invariant. The Create/Update JobPosting Actions
 * resolve the effective organization_id SERVER-SIDE from the OrganizationContext
 * (forcing the confined org) and assert the chosen branch belongs to it, mirroring the
 * Representative author-stamp pattern — so a confined manager can never plant/move a
 * posting into another tenant via the payload.
 *
 * `description` is plain text (Decision A — NOT a TipTap document; React auto-escapes it
 * at render, never dangerouslySetInnerHTML). Salary is integer cents (SPEC §5.5 — never
 * float). `status` is NOT in this DTO: a new posting is created Active (JOB-01 default)
 * and the lifecycle is a separate toggle DTO/Action.
 */
#[TypeScript]
final class CreateJobPostingData extends Data
{
    public function __construct(
        #[Required, Max(100)]
        public string $title,
        #[Required]
        public string $description,
        #[Required, Max(100)]
        public string $schedule,
        #[Required, Max(100)]
        public string $contact_info,
        #[Required, Exists('organizations', 'id')]
        public int $organization_id,
        #[Required, Exists('branches', 'id')]
        public int $branch_id,
        #[Nullable]
        public ?int $salary_min_cents = null,
        #[Nullable]
        public ?int $salary_max_cents = null,
        #[Nullable, Max(50)]
        public ?string $salary_display = null,
    ) {}

    /**
     * Salary band rules (Decision E). Both cents fields are nullable non-negative
     * integers; when BOTH are present a single cross-field closure on
     * `salary_max_cents` asserts max >= min, reporting on one key (like ArticleData's
     * tiptap closure) so the error surfaces as a `salary_max_cents` session error.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'salary_min_cents' => ['nullable', 'integer', 'min:0'],
            'salary_max_cents' => ['nullable', 'integer', 'min:0', self::salaryRangeRule()],
        ];
    }

    /**
     * Closure rule asserting the salary band is ordered: when both cents values are
     * present, max must be >= min. Reports on the `salary_max_cents` key.
     */
    private static function salaryRangeRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            /** @var array<string, mixed> $input */
            $input = request()->all();

            $min = $input['salary_min_cents'] ?? null;
            $max = $value;

            if ($min === null || $max === null) {
                return;
            }

            if (! is_numeric($min) || ! is_numeric($max)) {
                return;
            }

            if ((int) $max < (int) $min) {
                $fail(__('jobs.error.salary_range'));
            }
        };
    }
}
