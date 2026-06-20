<?php

declare(strict_types=1);

use App\Domain\Content\Actions\CreateArticleAction;
use App\Domain\Content\Data\ArticleData;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * The two retrofit ALTER migrations + the CreateArticleAction org stamping
 * (CONTRACT §1 #7-#8, §12, §16, SPEC §6.3.6/§6.3.8 + Deviation B/C). Proves:
 *   - users.organization_id is now FK-constrained to organizations(id) with RESTRICT
 *     (a user holding a non-null org blocks deleting that org at the DB level);
 *   - articles.organization_id + articles.branch_id exist and are restrict FKs;
 *   - CreateArticleAction stamps organization_id from the AUTHOR (every new article
 *     gets the author's org), and tolerates a null-org author (stamps null, no throw).
 * Schema assertions use PostgreSQL 18's information_schema (RefreshDatabase, never
 * SQLite). The stamping is asserted by invoking the Action directly (no HTTP, so the
 * context is unconfined and the read is unobstructed).
 */

it('constrains users.organization_id as a restrict FK to organizations(id)', function (): void {
    expect(Schema::hasColumn('users', 'organization_id'))->toBeTrue();

    $fk = DB::selectOne(<<<'SQL'
        SELECT rc.delete_rule, ccu.table_name AS referenced_table
        FROM information_schema.table_constraints tc
        JOIN information_schema.key_column_usage kcu
          ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
        JOIN information_schema.referential_constraints rc
          ON tc.constraint_name = rc.constraint_name AND tc.table_schema = rc.constraint_schema
        JOIN information_schema.constraint_column_usage ccu
          ON rc.unique_constraint_name = ccu.constraint_name AND rc.constraint_schema = ccu.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY'
          AND tc.table_name = 'users'
          AND kcu.column_name = 'organization_id'
        LIMIT 1
    SQL);

    // Laravel's restrictOnDelete() emits ON DELETE RESTRICT, which PostgreSQL's
    // information_schema reports as 'RESTRICT'. The load-bearing guarantee is that
    // it is NEITHER 'CASCADE' NOR 'SET NULL' (§6.1: no DB cascade; the column stays).
    expect($fk)->not->toBeNull()
        ->and($fk->referenced_table)->toBe('organizations')
        ->and(mb_strtoupper((string) $fk->delete_rule))->toBeIn(['RESTRICT', 'NO ACTION']);
});

it('blocks deleting an organization a user still references (DB-level restrict)', function (): void {
    $organization = Organization::factory()->create();
    User::factory()->forOrganization($organization)->create();

    // The restrict FK must prevent a raw hard-delete of the referenced org.
    expect(fn (): int => DB::table('organizations')->where('id', $organization->getKey())->delete())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('adds articles.organization_id and articles.branch_id as restrict FKs', function (): void {
    expect(Schema::hasColumn('articles', 'organization_id'))->toBeTrue()
        ->and(Schema::hasColumn('articles', 'branch_id'))->toBeTrue();

    $rules = DB::select(<<<'SQL'
        SELECT kcu.column_name, rc.delete_rule, ccu.table_name AS referenced_table
        FROM information_schema.table_constraints tc
        JOIN information_schema.key_column_usage kcu
          ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
        JOIN information_schema.referential_constraints rc
          ON tc.constraint_name = rc.constraint_name AND tc.table_schema = rc.constraint_schema
        JOIN information_schema.constraint_column_usage ccu
          ON rc.unique_constraint_name = ccu.constraint_name AND rc.constraint_schema = ccu.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY'
          AND tc.table_name = 'articles'
          AND kcu.column_name IN ('organization_id', 'branch_id')
    SQL);

    $byColumn = collect($rules)->keyBy('column_name');

    expect($byColumn->has('organization_id'))->toBeTrue()
        ->and($byColumn->get('organization_id')->referenced_table)->toBe('organizations')
        ->and(mb_strtoupper((string) $byColumn->get('organization_id')->delete_rule))->toBeIn(['RESTRICT', 'NO ACTION'])
        ->and($byColumn->has('branch_id'))->toBeTrue()
        ->and($byColumn->get('branch_id')->referenced_table)->toBe('branches')
        ->and(mb_strtoupper((string) $byColumn->get('branch_id')->delete_rule))->toBeIn(['RESTRICT', 'NO ACTION']);
});

it('stamps the article organization_id from the author on create', function (): void {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $author = User::factory()->forOrganization($organization)->create();
    $category = Category::factory()->create();

    $article = app(CreateArticleAction::class)->handle(
        ArticleData::from([
            'title' => 'Comunicado',
            'category_id' => $category->getKey(),
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ok']]]]],
        ]),
        $author,
    );

    expect(Article::withoutGlobalScope(OrganizationScope::class)->findOrFail($article->getKey())->organization_id)
        ->toBe($organization->getKey());
});

it('tolerates a null-org author: stamps a null organization_id, never throws', function (): void {
    Storage::fake('public');
    $author = User::factory()->create(['organization_id' => null]);
    $category = Category::factory()->create();

    $article = app(CreateArticleAction::class)->handle(
        ArticleData::from([
            'title' => 'Sin organización',
            'category_id' => $category->getKey(),
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ok']]]]],
        ]),
        $author,
    );

    expect(Article::withoutGlobalScope(OrganizationScope::class)->findOrFail($article->getKey())->organization_id)
        ->toBeNull();
});
