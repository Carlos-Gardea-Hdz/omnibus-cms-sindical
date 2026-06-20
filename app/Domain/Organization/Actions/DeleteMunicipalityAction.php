<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Exceptions\MunicipalityInUseException;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Delete a Municipality (hard delete — municipalities carry NO SoftDeletes). A
 * municipality referenced by any organization is refused gracefully: the pre-check
 * throws MunicipalityInUseException (rendered as a 302 + `municipality` field error
 * in bootstrap/app.php) BEFORE any mutation, so the restrict FK on organizations is
 * never tripped and no 500 reaches the user (SPEC §3.2 ORG-05).
 */
final class DeleteMunicipalityAction
{
    public function handle(Municipality $municipality): void
    {
        if ($this->isReferenced($municipality)) {
            throw new MunicipalityInUseException(__('municipalities.error.in_use'));
        }

        DB::transaction(static fn (): ?bool => $municipality->delete());
    }

    private function isReferenced(Municipality $municipality): bool
    {
        // withoutGlobalScope is unnecessary — Organization is not org-scoped — but
        // withTrashed() matters: a soft-deleted organization's row still physically
        // holds the municipality_id FK, so the restrict constraint would trip on it
        // too. The pre-check must see trashed rows for the graceful refusal to be honest.
        return Organization::withTrashed()
            ->where('municipality_id', $municipality->getKey())
            ->exists();
    }
}
