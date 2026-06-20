<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs domain — `job_postings` (SPEC §6.3.10). bigint id + foreignId, matching the
 * live users/articles/branches ids (slices 001–003). All three FKs are RESTRICT
 * (§6.4): organization_id, branch_id (NOT NULL — Decision D, §6.3.10 lists it with
 * no nullable note) and created_by (→ users). The org-scoping spine confines a
 * manager/editor to their own org's postings; the Create/Update Actions stamp
 * organization_id server-side so a confined caller cannot plant a posting in another
 * tenant.
 *
 * `description` is PLAIN TEXT (Decision A — NOT a TipTap JSONB document; SPEC §6.3.10
 * types it TEXT, §3.4 lists no rich-content rule). Salary is integer cents (BIGINT,
 * SPEC §5.5 — never float); `salary_display` is the pre-rendered human string.
 * `status` is a plain string column whose JobStatus backing value is cast in the
 * model (portability — same convention as users.role / articles.status). SoftDeletes.
 * job_postings is a leaf (nothing FKs to it) so no CASCADE is needed.
 *
 * Timestamped after the slice-003 ...0407 retrofit so the organizations/branches/users
 * FK targets resolve at migrate time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 100);
            $table->text('description');                          // PLAIN TEXT (Decision A)
            $table->string('schedule', 100);
            $table->string('contact_info', 100);
            $table->bigInteger('salary_min_cents')->nullable();   // integer cents, NEVER float
            $table->bigInteger('salary_max_cents')->nullable();
            $table->string('salary_display', 50)->nullable();
            $table->string('status', 20)->default('active');      // JobStatus backing value; cast in model
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index(['organization_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
