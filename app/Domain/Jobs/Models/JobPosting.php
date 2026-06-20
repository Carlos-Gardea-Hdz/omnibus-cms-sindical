<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Models;

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * JobPosting — an opening posted by an organization for a specific branch (SPEC
 * §6.3.10, §3.4 JOB-01..03). Born org-scoped: the global OrganizationScope confines a
 * manager/editor to their own org's postings (a cross-org route-model-bound {job}
 * becomes unresolvable → 404); super_admin / administrator / CLI are unconfined. The
 * public job board reads this model UNCONFINED but filtered to JobStatus::Active only.
 * SoftDeletes; job_postings is a leaf entity (nothing FKs to it → no restrict pre-check
 * on delete). `status` casts to the JobStatus backed enum (no magic strings); salary is
 * integer cents (SPEC §5.5 — never float). `description` is plain text (Decision A).
 *
 * organization, branch and creator are all non-nullable belongsTo (RESTRICT FK) —
 * never |null (PHPStan L9).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $branch_id
 * @property int $created_by
 * @property string $title
 * @property string $description
 * @property string $schedule
 * @property string $contact_info
 * @property int|null $salary_min_cents
 * @property int|null $salary_max_cents
 * @property string|null $salary_display
 * @property JobStatus $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Organization $organization
 * @property-read Branch $branch
 * @property-read User $creator
 */
final class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'branch_id',
        'created_by',
        'title',
        'description',
        'schedule',
        'contact_info',
        'salary_min_cents',
        'salary_max_cents',
        'salary_display',
        'status',
    ];

    protected static function booted(): void
    {
        self::addGlobalScope(new OrganizationScope);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'salary_min_cents' => 'integer',
            'salary_max_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return JobPostingFactory::new();
    }
}
