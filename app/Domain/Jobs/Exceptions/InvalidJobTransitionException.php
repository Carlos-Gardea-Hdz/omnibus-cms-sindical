<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Exceptions;

use DomainException;

/**
 * A job-posting status transition was rejected by the JobStatus state graph (SPEC §3.4
 * JOB-02). Carries only a translated message so the Jobs domain stays free of
 * Illuminate\Http — the HTTP render (302 + a `status` field error on web, 422 JSON on
 * API) lives in bootstrap/app.php. Mirrors InvalidArticleTransitionException.
 */
final class InvalidJobTransitionException extends DomainException {}
