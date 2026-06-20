<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use RuntimeException;

/**
 * A category cannot be deleted because at least one article still references it
 * (a restrict FK). Carrying only a translated message keeps the Content domain
 * free of any Illuminate\Http dependency — the HTTP render (302 + field error on
 * web, 422 JSON on API) lives in bootstrap/app.php. Thrown by DeleteCategoryAction
 * as an in-use pre-check, so the restrict FK is never tripped and no 500 reaches
 * the user (SPEC §3.3 CAT-02).
 */
final class CategoryInUseException extends RuntimeException {}
