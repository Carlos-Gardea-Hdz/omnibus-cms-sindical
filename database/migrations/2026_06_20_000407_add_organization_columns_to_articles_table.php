<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RETROFIT (slice 003) — add organization_id + branch_id to the slice-002 articles
 * table (SPEC §6.3.8, §6.4).
 *
 * Both columns are added NULLABLE with a RESTRICT FK (Deviation C): §6.3.8 lists
 * them NOT NULL, but a nullable-with-restrict-FK column is the only reversible,
 * non-destructive way to bolt org onto an already-shipped table with no safe
 * backfill target. Existing rows (if any) stay null; the retrofitted
 * CreateArticleAction stamps organization_id from the author so every NEW article is
 * non-null, and stamps branch_id when supplied. A future data-migration tightens to
 * NOT NULL once backfilled. No DB ON DELETE CASCADE (§6.1) — the branch→article
 * cascade is application-level in DeleteBranchAction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')
                ->constrained('branches')->restrictOnDelete();

            $table->index('organization_id');
            $table->index(['organization_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'published_at']);
            $table->dropIndex(['organization_id']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
