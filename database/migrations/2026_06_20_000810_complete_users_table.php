<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 008 — complete the users table for Identity Phase 2 (demo login AUTH-02 +
 * user CRUD AUTH-04). Purely ADDITIVE relative to slice 001/003:
 *
 *   - name / email → NULLABLE: a user (and a demo user in particular) may carry no
 *     name or email; the login key is `username`, not email (AUTH-01). Laravel 12's
 *     native schema change toggles only the NOT NULL constraint on PostgreSQL and
 *     leaves the existing UNIQUE index on email intact (no doctrine/dbal index drop),
 *     so the index is NOT re-declared here.
 *   - avatar_path: ships the column now; the upload UI/Action is DEFERRED (Decision F).
 *   - last_login_at / last_login_ip: stamped by the login + demo-login paths (Decision H).
 *   - demo_session_id (+ index): the per-session isolation/cleanup tag for ephemeral
 *     demo users (null on every real user — the DemoCleanupAction guard relies on it).
 *   - softDeletes(): user deletion is a SOFT delete (Decision G) so an authored
 *     article's author_id still resolves (FK-safe, no restrict-FK 500).
 *
 * down() reverses ONLY what up() added — it never touches is_demo / organization_id /
 * role (owned by slices 001/003). It restores name/email to NOT NULL; run it only
 * against data where those are populated (the slice's pre-state).
 *
 * NOTE: do NOT run on the shared DB — schema change is the deploy step's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('avatar_path', 255)->nullable()->after('email');
            $table->timestamp('last_login_at')->nullable()->after('avatar_path');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->string('demo_session_id', 36)->nullable()->after('is_demo');
            $table->index('demo_session_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropIndex(['demo_session_id']);
            $table->dropColumn(['avatar_path', 'last_login_at', 'last_login_ip', 'demo_session_id']);
            $table->string('email')->nullable(false)->change();
            $table->string('name')->nullable(false)->change();
        });
    }
};
