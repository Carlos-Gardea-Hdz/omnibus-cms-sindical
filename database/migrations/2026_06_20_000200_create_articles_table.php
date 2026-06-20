<?php

declare(strict_types=1);

use App\Domain\Content\Enums\ArticleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content domain — `articles` (SPEC §6.3.8). bigint id + foreignId (Deviation A:
 * matches users.id). category_id / author_id are RESTRICT (historical content is
 * protected — a referenced category or author can never be deleted out from
 * under an article). SoftDeletes for recoverable removal. `content` is JSONB
 * (TipTap/ProseMirror), sanitized on store. `organization_id`/`branch_id` are
 * DEFERRED to the Organization slice — no FK target exists yet, so adding a
 * nullable-no-FK column now would contradict the §6.4 RESTRICT contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 150);
            $table->string('slug', 180)->unique();
            $table->string('subtitle', 200)->nullable();
            $table->jsonb('content');
            $table->string('signature', 200)->nullable();
            $table->string('featured_image_path', 255)->nullable();   // required ON PUBLISH (Action-enforced)
            $table->string('status', 20)->default(ArticleStatus::Draft->value);
            $table->string('meta_title', 200)->nullable();
            $table->text('meta_description')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index('category_id');
            $table->index('author_id');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
