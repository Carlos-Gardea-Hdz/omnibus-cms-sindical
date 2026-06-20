<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Content\Models\Article;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Organization — a sindicato / tenant (SPEC §6.3.2). The tenancy root every
 * org-scoped row hangs off. SoftDeletes; deletion is guarded at the Action level
 * (OrganizationInUseException) when branches exist, before the restrict FK is
 * reached. NOT globally org-scoped itself — it is the super_admin-only catalog,
 * confined by route-gating (§10.2), so administrators/managers never list it.
 *
 * `municipality` is a non-nullable belongsTo (RESTRICT FK) — never |null. `director`
 * is the nullable 1:1 (SET NULL FK on director_id) — |null. slug is unique, derived
 * from name in the Action.
 *
 * @property int $id
 * @property int $municipality_id
 * @property int|null $director_id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property \Illuminate\Support\Carbon $registered_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Municipality $municipality
 * @property-read Director|null $director
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Branch> $branches
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Representative> $representatives
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Article> $articles
 */
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'municipality_id',
        'director_id',
        'name',
        'slug',
        'logo_path',
        'registered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return BelongsTo<Director, $this>
     */
    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<Representative, $this>
     */
    public function representatives(): HasMany
    {
        return $this->hasMany(Representative::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return OrganizationFactory::new();
    }
}
