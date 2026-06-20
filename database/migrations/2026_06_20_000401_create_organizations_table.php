<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization domain — `organizations` (SPEC §6.3.2). bigint id + foreignId.
 * municipality_id is RESTRICT (§6.4 — a referenced municipality can never be
 * deleted out from under an organization). director_id is a 1:1 (UNIQUE) nullable
 * column whose FK→directors (SET NULL) is added in a SEPARATE later migration
 * (add_director_fk_to_organizations_table): the directors table carries an
 * organization_id FK back to here, so the two FKs are circular and must be split
 * for migrate:fresh to succeed. slug is unique (derived from name in the Action).
 * SoftDeletes for recoverable removal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
            // FK→directors added in step 6 (circular dependency). Nullable + unique (1:1).
            $table->unsignedBigInteger('director_id')->nullable()->unique();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('logo_path', 255)->nullable();
            $table->date('registered_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index('municipality_id');
            $table->index('registered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
