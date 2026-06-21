<?php

declare(strict_types=1);

namespace App\Domain\Membership\Models;

use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Member — a union member (SPEC §6.3.12, §3.6). Born org-scoped: the global
 * OrganizationScope confines a manager/editor to their own org's members for the review
 * path (a cross-org route-model-bound {member} becomes unresolvable → 404 — the
 * write-isolation crown of this slice). super_admin / administrator / CLI are unconfined.
 * The sole CREATE path is the PUBLIC anonymous `POST /membership/register`; there is no
 * admin create/update/destroy (only index + approve + reject — §7.3).
 *
 * organization is NULLABLE (SET NULL FK — a member may register org-less, and deleting an
 * organization NULLs its members): @property int|null + @property-read Organization|null.
 * municipality is NOT NULL (RESTRICT FK): @property-read Municipality (never |null).
 *
 * PII at rest: `curp` and `rfc` carry the `encrypted` cast — written/read in the clear
 * through the model accessor, ciphertext on disk. `status` casts to the MemberStatus
 * backed enum (no magic strings); `date_of_birth` is a date; `is_affiliated` a boolean.
 * SoftDeletes; members is a leaf entity (nothing FKs to it → no restrict pre-check on
 * delete).
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int $municipality_id
 * @property string $curp
 * @property string $rfc
 * @property string $first_name
 * @property string $last_name_paternal
 * @property string $last_name_maternal
 * @property \Illuminate\Support\Carbon $date_of_birth
 * @property string $address
 * @property string $postal_code
 * @property string $neighborhood
 * @property string|null $phone
 * @property string $mobile
 * @property bool $is_affiliated
 * @property MemberStatus $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Organization|null $organization
 * @property-read Municipality $municipality
 */
final class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'municipality_id',
        'curp',
        'rfc',
        'first_name',
        'last_name_paternal',
        'last_name_maternal',
        'date_of_birth',
        'address',
        'postal_code',
        'neighborhood',
        'phone',
        'mobile',
        'is_affiliated',
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
            'curp' => 'encrypted',
            'rfc' => 'encrypted',
            'date_of_birth' => 'date',
            'is_affiliated' => 'boolean',
            'status' => MemberStatus::class,
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
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return MemberFactory::new();
    }
}
