<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics domain — `daily_snapshots` (SPEC §6.3.13). bigint id + foreignId, matching
 * the established CMS ids. organization_id is NOT NULL + RESTRICT (§6.4); a unique
 * composite [organization_id, date] guarantees one snapshot row per org per day.
 *
 * SHIP table+model now, populating job DEFERRED (Decision F option (i)): the schema is
 * defined here for completeness (the §6.3.13 contract is honored, zero runtime cost),
 * but the MVP dashboard reads LIVE aggregates off the source tables. The nightly
 * RollDailySnapshotsJob that upserts these per-org daily counts is a high-volume
 * pre-aggregation optimization deferred to a scaling phase (spec §"Out of scope"). No
 * code reads FROM this table in the MVP.
 *
 * Timestamped after `page_views` so the organizations FK target resolves at migrate time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->date('date');
            $table->integer('articles_count')->default(0);
            $table->integer('jobs_count')->default(0);
            $table->integer('members_count')->default(0);
            $table->integer('contact_messages_count')->default(0);
            $table->integer('page_views_count')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'date']);          // §6.3.13 unique composite
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_snapshots');
    }
};
