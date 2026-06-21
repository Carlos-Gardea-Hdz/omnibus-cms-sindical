<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * A user attempted to delete their own account (SPEC §3.1 AUTH-04). Disallowed so an
 * actor can never lock themselves (or, for the sole super_admin, the whole system) out
 * mid-session. Carrying only a translated message keeps the Identity domain free of
 * Illuminate\Http — the HTTP render (302 + flash on web, 422 JSON on API) lives in
 * bootstrap/app.php. Thrown by DeleteUserAction.
 */
final class CannotDeleteSelfException extends RuntimeException {}
