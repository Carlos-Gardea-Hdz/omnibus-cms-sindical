<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use RuntimeException;

/**
 * An organization cannot be deleted because at least one branch still references it
 * (a restrict FK). Message-only — the HTTP render (302 + `organization` field error
 * on web, 422 JSON on API) lives in bootstrap/app.php. Thrown by
 * DeleteOrganizationAction as an in-use pre-check, so the restrict FK is never
 * tripped and no 500 reaches the user (SPEC §3.2 ORG-01).
 */
final class OrganizationInUseException extends RuntimeException {}
