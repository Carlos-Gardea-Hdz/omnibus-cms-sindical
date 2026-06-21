<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership domain — `members` (SPEC §6.3.12). A union member: registered via the
 * PUBLIC anonymous `POST /membership/register` (the sole CREATE path), then reviewed by
 * an org admin (approve/reject lifecycle). The org-scoping spine confines a
 * manager/editor to their own org's members for the review path (a cross-org
 * route-model-bound {member} becomes unresolvable → 404).
 *
 * FK rules (Decision B):
 *   - organization_id is NULLABLE + SET NULL — a member may register without picking an
 *     org, and deleting an organization NULLs its members rather than blocking (members
 *     are NOT a restrict referrer of organizations; DeleteOrganizationAction stays
 *     untouched).
 *   - municipality_id is NOT NULL + RESTRICT — a referenced municipality cannot be hard
 *     deleted (DeleteMunicipalityAction pre-checks members too).
 *
 * PII at rest: `curp` and `rfc` are TEXT (ciphertext is longer than the cleartext) and
 * carry the Eloquent `encrypted` cast in the model — they are never stored or queried in
 * the clear. No unique index on curp/rfc (encryption makes a DB unique constraint moot;
 * uniqueness, if ever needed, is an Action-level concern). `status` is a plain string
 * column whose MemberStatus backing value is cast in the model (portability — same
 * convention as users.role / job_postings.status). SoftDeletes; members is a leaf entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
            $table->text('curp');                                 // encrypted in the model
            $table->text('rfc');                                  // encrypted in the model
            $table->string('first_name', 100);
            $table->string('last_name_paternal', 100);
            $table->string('last_name_maternal', 100);
            $table->date('date_of_birth');
            $table->text('address');
            $table->string('postal_code', 5);
            $table->string('neighborhood', 100);
            $table->string('phone', 10)->nullable();
            $table->string('mobile', 10);
            $table->boolean('is_affiliated')->default(false);
            $table->string('status', 20)->default('pending');     // MemberStatus backing value; cast in model
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
            $table->index('municipality_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
