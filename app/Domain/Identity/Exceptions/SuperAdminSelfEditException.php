<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * A guard on the super_admin singleton's own record (SPEC §3.1 AUTH-04, Decision C):
 *
 *   - The sole super_admin row may be edited ONLY by itself — no other actor may touch
 *     it (a peer super_admin cannot exist, and a lower role may not reach it).
 *   - A super_admin may not demote ITSELF via a plain update; the singleton can change
 *     hands only as the side-effect of promoting someone else (the swap in CreateUser /
 *     UpdateUserAction), which leaves the system always holding exactly one.
 *
 * Carrying only a translated message keeps the Identity domain free of Illuminate\Http
 * — the HTTP render (302 + a `role` field error on web, 422 JSON on API) lives in
 * bootstrap/app.php. Thrown by UpdateUserAction.
 */
final class SuperAdminSelfEditException extends RuntimeException {}
