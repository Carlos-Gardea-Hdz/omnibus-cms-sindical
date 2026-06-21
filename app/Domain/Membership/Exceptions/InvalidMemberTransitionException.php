<?php

declare(strict_types=1);

namespace App\Domain\Membership\Exceptions;

use DomainException;

/**
 * A member status transition was rejected by the MemberStatus state graph (SPEC §3.6,
 * Decision C). Carries only a translated message so the Membership domain stays free of
 * Illuminate\Http — the HTTP render (302 + a `status` field error on web, 422 JSON on
 * API) lives in bootstrap/app.php. Mirrors InvalidJobTransitionException.
 */
final class InvalidMemberTransitionException extends DomainException {}
