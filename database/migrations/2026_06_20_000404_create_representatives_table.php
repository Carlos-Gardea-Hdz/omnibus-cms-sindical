<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization domain — `representatives` (SPEC §6.3.5). bigint id + foreignId.
 * organization_id and branch_id are both RESTRICT (§6.4). The existence of a
 * representative is what blocks its branch's delete (§3.2 ORG-02 → the
 * BranchHasRepresentativesException pre-check in DeleteBranchAction). `shift` is a
 * plain string column whose RepresentativeShift backing value is cast in the model
 * (portability — same convention as users.role / articles.status). SoftDeletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('representatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('shift', 20); // RepresentativeShift backing value; cast in the model
            $table->boolean('is_coordinator')->default(false);
            $table->string('photo_path', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
            $table->index('branch_id');
            $table->index(['organization_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('representatives');
    }
};
