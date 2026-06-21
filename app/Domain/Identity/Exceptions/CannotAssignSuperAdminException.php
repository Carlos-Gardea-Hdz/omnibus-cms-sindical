<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * An administrator attempted to assign (or elevate to) the super_admin role, which
 * only a super_admin may grant (SPEC §3.1 AUTH-04). Carrying only a translated
 * message keeps the Identity domain free of any Illuminate\Http dependency — the HTTP
 * render (302 + a `role` field error on web, 422 JSON on API) lives in
 * bootstrap/app.php. Thrown by Create/UpdateUserAction as a pre-check, so no
 * privilege escalation is ever persisted.
 */
final class CannotAssignSuperAdminException extends RuntimeException {}
