<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * An attempt to delete the SOLE super_admin (SPEC §3.1 AUTH-04, the singleton
 * invariant): the system must always retain exactly one super_admin, so the last one
 * can never be removed. Carrying only a translated message keeps the Identity domain
 * free of Illuminate\Http — the HTTP render (302 + flash on web, 422 JSON on API)
 * lives in bootstrap/app.php. Thrown by DeleteUserAction.
 */
final class CannotDeleteLastSuperAdminException extends RuntimeException {}
