<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RETROFIT (slice 003) — constrain the slice-001 users.organization_id column.
 *
 * users.organization_id already exists (slice 001 create_users_table — nullable,
 * FK-less, awaiting an Organization target). This migration adds ONLY the FK
 * →organizations(id) RESTRICT (§6.4) plus the §6.3.6 organization_id + role
 * indexes. The column itself is NOT touched and STAYS nullable (Deviation B):
 * super_admin may be org-less / cross-org, and a confined null-org user fails CLOSED
 * in OrganizationScope. down() drops only what this migration added — never the
 * column (slice 001 owns it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index('organization_id');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['role']);
        });
    }
};
