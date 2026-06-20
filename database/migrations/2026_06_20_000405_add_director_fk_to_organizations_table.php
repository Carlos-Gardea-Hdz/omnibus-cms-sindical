<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization domain — the circular FK (SPEC §6.4). organizations.director_id was
 * created (nullable + unique) in create_organizations_table; directors.organization_id
 * was created in create_directors_table. The two reference each other, so the
 * organizations→directors FK is added HERE, after both tables exist, to keep
 * migrate:fresh resolvable. SET NULL per §6.4: deleting a director clears the
 * organization's 1:1 pointer (the director row itself is restrict-guarded the other
 * way). The column + its unique index were created in step 2 and drop with the
 * organizations table; this reverse drops only the FK it added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->foreign('director_id')->references('id')->on('directors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropForeign(['director_id']);
        });
    }
};
