<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Models;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Database\Factories\ContactMessageFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContactMessage — a public contact-form submission (SPEC §6.3.11, §3.5 CONTACT-01/02).
 * The SIMPLEST entity of the CMS: it has NO status (the row is its own terminal state —
 * NO enum / moderation lifecycle, Decision B), NO SoftDeletes (a permanent audit record,
 * Decision C — it is never deleted, so the RESTRICT FKs on it are never tripped by a
 * parent soft-delete), and a PLAIN-TEXT `message` (NOT TipTap — SanitizesContent does NOT
 * apply; React auto-escapes at render, never dangerouslySetInnerHTML).
 *
 * Born org-scoped (CONTACT-02): the global {@see OrganizationScope} confines a
 * manager/administrator reading the admin inbox to their own org's messages (a cross-org
 * row is invisible); super_admin / CLI are unconfined. There is no public READ — contact
 * PII never leaks publicly. The anonymous WRITE is unconfined (the scope only filters
 * SELECT, so the INSERT is a no-op pass-through); the write-provenance guard is the Submit
 * Action's branch→org assertion, not an org-stamp (there is no owner to stamp, Decision F).
 *
 * organization and branch are both non-nullable belongsTo (RESTRICT FK) — never |null
 * (PHPStan L9). References the Organization domain's Branch/Organization MODELS directly
 * (a cross-domain FK model reference is arch-allowed; calling another domain's Actions is
 * not).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $branch_id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $phone
 * @property string $message
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Organization $organization
 * @property-read Branch $branch
 */
final class ContactMessage extends Model
{
    /** @use HasFactory<ContactMessageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'branch_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'message',
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
        return ContactMessageFactory::new();
    }
}
