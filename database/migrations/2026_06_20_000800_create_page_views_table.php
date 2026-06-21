<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics domain — `page_views` (SPEC §6.3.14). bigint id + foreignId, matching the
 * live users/articles/organizations ids (slices 001–006; the established CMS deviation,
 * NOT ULID). This is an APPEND-ONLY event table — the SOLE write path is
 * RecordPageViewAction (an atomic insert behind a 24h dedup gate). Deliberate schema
 * facts:
 *   - article_id is NULLABLE + SET NULL (§6.4): a view survives its article's hard
 *     delete as an anonymized aggregate (article_id → null, organization_id intact).
 *   - organization_id is NOT NULL + RESTRICT (§6.4): a view always belongs to an org,
 *     derived from the article at record time (the dashboard aggregates over it scoped).
 *   - ip_hash is VARCHAR(64) — a SHA-256 hex digest of (raw IP + APP_KEY salt). The raw
 *     IP is hashed at the edge (the controller) and NEVER persisted (§10.5): no column
 *     holds the dotted IP; the hash is a stable-per-deployment, one-way value used only
 *     for the 24h dedup window.
 *   - user_agent is VARCHAR(255) NULLABLE — coarse analytics only, surfaced only in
 *     aggregate (never per-row in a prop).
 *   - viewed_at is the SSOT event clock. NO timestamps() and NO softDeletes(): an
 *     append-only event has no created_at/updated_at and is never soft-deleted.
 *
 * Indexes (§6.3.14): article_id, organization_id, viewed_at, and the composite
 * [article_id, ip_hash, viewed_at] that backs the 24h dedup EXISTS check.
 *
 * Timestamped after the slice-006 `contact_messages` (the last data table) so the
 * articles/organizations FK targets resolve at migrate time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table): void {
            $table->id();
            // SET NULL: a view survives its article's hard-delete as an anonymized aggregate (§6.4).
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            // RESTRICT + NOT NULL: a view always belongs to an org (derived from the article at record time).
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('ip_hash', 64);                        // SHA-256 of (raw IP + APP_KEY salt); raw IP NEVER stored (§10.5)
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('viewed_at');                       // the event clock — NO timestamps()/softDeletes (append-only)

            $table->index('article_id');
            $table->index('organization_id');
            $table->index('viewed_at');
            $table->index(['article_id', 'ip_hash', 'viewed_at']); // the 24h dedup composite (§6.3.14)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
