<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use RuntimeException;

/**
 * An organization already has a director — only one is allowed (SPEC §3.2 ORG-04,
 * the UniqueDirectorPerOrganizationRule intent). Message-only — the HTTP render
 * (302 + `director` field error on web, 422 JSON on API) lives in bootstrap/app.php.
 * Thrown by CreateDirectorAction as a one-per-org pre-check; the DB unique index on
 * directors.organization_id is the backstop a raw insert trips.
 */
final class DirectorAlreadyAssignedException extends RuntimeException {}
