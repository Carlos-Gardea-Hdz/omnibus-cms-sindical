<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Engagement\Actions\SubmitContactAction;
use App\Domain\Engagement\Data\SubmitContactData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Public contact-message submission (SPEC §3.5, §6.3.11, §10.4 / slice-006 §6). This is the
 * SOLE create path for a {@see \App\Domain\Engagement\Models\ContactMessage}: an ANONYMOUS,
 * fully UNCONFINED endpoint — no `auth`, no `org.scope`, so {@see \App\Http\Middleware\EnsureOrganizationScope}
 * leaves the request UNCONFINED (an anonymous visitor carries no session confinement). It sits
 * at the top level under `throttle:3,15` (routes/web.php) — the rate limit IS the §10.4 abuse
 * control (no CAPTCHA), and CSRF protection ships with the `web` group.
 *
 * Anemic by law (≤15 lines/method): {@see store} hands a validated {@see SubmitContactData}
 * (resolved via the method signature → a bad field is 302 + session errors, NEVER 422) to
 * {@see SubmitContactAction}, which owns the write inside a `DB::transaction`. Unlike the
 * org-stamped admin writes elsewhere in the CMS there is NO server-owned field to stamp (the
 * `contact_messages` table has no status / moderation column — the row is its own terminal
 * state, §6.3.11). The write-provenance guard is the branch-belongs-to-org assertion the
 * Action performs (both `organization_id` and `branch_id` arrive from the anonymous payload);
 * a mismatched branch throws Spatie's `ValidationException` → a graceful 302 + `branch_id`
 * error, never a 500.
 *
 * There is deliberately NO public READ path for contact messages: the sender PII (name, email,
 * phone) is admin-only, surfaced exclusively on the org-scoped {@see \App\Http\Controllers\Admin\ContactController}
 * inbox (SPEC §10.5 excludes contact PII from encryption but keeps it off every public path).
 */
final class ContactController extends Controller
{
    public function store(SubmitContactData $data, SubmitContactAction $action): RedirectResponse
    {
        $action->handle($data);

        return back()->with('success', __('contact.submitted'));
    }
}
