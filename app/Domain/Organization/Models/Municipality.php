<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use Database\Factories\MunicipalityFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Municipality reference catalog (SPEC §6.3.1). NO SoftDeletes — a hard-delete
 * catalog whose deletion is guarded at the Action level (MunicipalityInUseException)
 * before the restrict FK on organizations is ever reached, exactly like the slice-002
 * Category and the UNIGES Department. NOT org-scoped — it is a shared catalog.
 *
 * @property int $id
 * @property string $name
 * @property string $state
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Organization> $organizations
 */
final class Municipality extends Model
{
    /** @use HasFactory<MunicipalityFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'state',
    ];

    /**
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return MunicipalityFactory::new();
    }
}
