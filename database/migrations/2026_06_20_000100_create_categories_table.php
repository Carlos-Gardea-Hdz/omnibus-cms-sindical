<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content domain — `categories` (SPEC §6.3.7). bigint id (Deviation A: matches
 * users.id program-wide). NO SoftDeletes — catalog deletion is guarded at the
 * Action level (CategoryInUseException) before the restrict FK on articles is
 * ever reached, so a hard delete is safe and intentional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 30);
            $table->string('slug', 50)->unique();
            $table->string('description', 250)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
