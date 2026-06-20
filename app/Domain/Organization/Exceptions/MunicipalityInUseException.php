<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use RuntimeException;

/**
 * A municipality cannot be deleted because at least one organization still
 * references it (a restrict FK). Message-only — the Organization domain stays free
 * of any Illuminate\Http dependency; the HTTP render (302 + `municipality` field
 * error on web, 422 JSON on API) lives in bootstrap/app.php. Thrown by
 * DeleteMunicipalityAction as an in-use pre-check, so the restrict FK is never
 * tripped and no 500 reaches the user (SPEC §3.2 ORG-05).
 */
final class MunicipalityInUseException extends RuntimeException {}
