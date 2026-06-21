<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement domain — `contact_messages` (SPEC §6.3.11). bigint id + foreignId, matching
 * the live users/articles/branches/job_postings ids (slices 001–005; Decision A — the
 * established CMS deviation, NOT ULID). Both FKs are RESTRICT (§6.4): organization_id and
 * branch_id are NOT NULL and both arrive from the ANONYMOUS public payload — the Submit
 * Action asserts the chosen branch belongs to the chosen org (slice-004 trait), so a
 * forged cross-org pair is a 302 + branch_id error, never a cross-org attach.
 *
 * This is the SIMPLEST schema of all slices and three SPEC facts are deliberate:
 *   - NO `status` column (Decision B) → the row is its own terminal state; there is no
 *     moderation lifecycle / enum / approve-reject. A submission is a permanent record.
 *   - NO `softDeletes()` (Decision C) → a contact message is a permanent audit record
 *     (§6.4); it is never deleted, so a RESTRICT FK on it is never tripped by the parent
 *     soft-delete (Decision E — the slice-004 cross-slice lesson resolves to a NO-OP here:
 *     organizations/branches soft-delete (UPDATE), so the SQL DELETE that RESTRICT guards
 *     never fires).
 *   - `message` is PLAIN TEXT (Decision A) → NOT a TipTap JSONB document; SanitizesContent
 *     does NOT apply. React auto-escapes it at render, never dangerouslySetInnerHTML.
 *
 * The sender PII (first_name/last_name/email/phone) is PLAIN — no encryption (Decision H,
 * §10.5 excludes contact PII; email/phone are shown in the admin inbox because replying is
 * the message's whole purpose, org-scoped — this is not a leak). email is sized 60 to match
 * the §3.5 CONTACT-01 ≤60 rule; phone is exactly 10 digits (the MexicanPhone rule).
 *
 * Timestamped after the slice-006 predecessors so the organizations/branches FK targets
 * resolve at migrate time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('email', 60);                          // PLAIN — no encryption (Decision H)
            $table->string('phone', 10);                          // exactly 10 digits (MexicanPhone)
            $table->text('message');                              // PLAIN TEXT — no SanitizesContent
            $table->timestamps();
            // NO softDeletes() — a contact message is a permanent audit record (Decision C).

            $table->index('organization_id');
            $table->index(['organization_id', 'created_at']);
            // No status index — there is no status column (Decision B).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
