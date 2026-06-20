<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization domain — `directors` (SPEC §6.3.4). bigint id + foreignId.
 * organization_id is UNIQUE (one director per organization — SPEC §3.2 ORG-04, the
 * UniqueDirectorPerOrganizationRule intent enforced as a DB unique index; the
 * backstop a raw second insert trips, proving the constraint physically exists) and
 * RESTRICT (§6.4). SoftDeletes. The reverse 1:1 pointer organizations.director_id
 * (SET NULL) is wired in add_director_fk_to_organizations_table after this table
 * exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('photo_path', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directors');
    }
};
