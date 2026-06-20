<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization domain — `branches` (SPEC §6.3.3). bigint id + foreignId.
 * organization_id is RESTRICT (§6.4); an organization with branches refuses to
 * delete (OrganizationInUseException) before the FK is tripped. SoftDeletes. The
 * branch→article cascade on delete is application-level (DeleteBranchAction), never
 * a DB ON DELETE CASCADE (§6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('location', 100);
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
