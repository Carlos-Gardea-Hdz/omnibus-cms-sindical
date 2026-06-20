<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization domain — `municipalities` (SPEC §6.3.1). A hard-delete reference
 * catalog (NO SoftDeletes — §6.3.1 lists no deleted_at), guarded at the Action
 * level by a restrict-in-use pre-check (MunicipalityInUseException) before the
 * restrict FK on organizations is ever reached, exactly like UNIGES Department.
 * bigint id (Deviation E — matches users.id / articles.id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('state', 100);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipalities');
    }
};
