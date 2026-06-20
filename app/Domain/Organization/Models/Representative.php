<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\RepresentativeShift;
use App\Support\OrganizationScope;
use Database\Factories\RepresentativeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Representative — a delegate at a branch (SPEC §6.3.5). The presence of a
 * representative is what blocks its branch's delete (§3.2 ORG-02). Org-scoped via
 * the global OrganizationScope. SoftDeletes. `shift` casts to the RepresentativeShift
 * backed enum (no magic strings); is_coordinator is a bool.
 *
 * `organization` and `branch` are non-nullable belongsTo (RESTRICT FK) — never |null.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $branch_id
 * @property string $first_name
 * @property string $last_name
 * @property RepresentativeShift $shift
 * @property bool $is_coordinator
 * @property string|null $photo_path
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Organization $organization
 * @property-read Branch $branch
 */
final class Representative extends Model
{
    /** @use HasFactory<RepresentativeFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'branch_id',
        'first_name',
        'last_name',
        'shift',
        'is_coordinator',
        'photo_path',
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
            'shift' => RepresentativeShift::class,
            'is_coordinator' => 'boolean',
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
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return RepresentativeFactory::new();
    }
}
