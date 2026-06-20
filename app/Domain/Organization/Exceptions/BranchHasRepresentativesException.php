<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use RuntimeException;

/**
 * A branch cannot be deleted because it still has representatives (a restrict FK on
 * representatives.branch_id). Message-only — the HTTP render (302 + `branch` field
 * error on web, 422 JSON on API) lives in bootstrap/app.php. Thrown by
 * DeleteBranchAction as an in-use pre-check BEFORE the application-level
 * branch→article cascade, so the restrict FK is never tripped and no 500 reaches the
 * user (SPEC §3.2 ORG-02, §11.4 #3).
 */
final class BranchHasRepresentativesException extends RuntimeException {}
