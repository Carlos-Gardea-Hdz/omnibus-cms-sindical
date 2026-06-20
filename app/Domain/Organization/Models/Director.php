<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Support\OrganizationScope;
use Database\Factories\DirectorFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Director — the secretary-general of an organization (SPEC §6.3.4). One per org
 * (organization_id is UNIQUE — ORG-04, enforced at the Action AND as a DB unique
 * index backstop). Org-scoped via the global OrganizationScope. SoftDeletes.
 *
 * `organization` is a non-nullable belongsTo (RESTRICT FK) — never |null.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $photo_path
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Organization $organization
 */
final class Director extends Model
{
    /** @use HasFactory<DirectorFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'first_name',
        'last_name',
        'photo_path',
    ];

    protected static function booted(): void
    {
        self::addGlobalScope(new OrganizationScope);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return DirectorFactory::new();
    }
}
