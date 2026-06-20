<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content domain — `article_images` (SPEC §6.3.9). The ONLY cascade in the model
 * (§6.4): a gallery image is a true child — meaningless without its parent
 * article — so a hard delete of the parent cascades the image rows at the DB
 * level. bigint id + foreignId (Deviation A). The single featured image is wired
 * this slice; multi-row gallery upload (sort_order pipeline) is DEFERRED to the
 * media slice, but the table/FK/cascade land now so NEWS-03 is satisfiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('path', 300);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_images');
    }
};
