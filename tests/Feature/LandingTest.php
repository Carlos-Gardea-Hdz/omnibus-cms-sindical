<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Public union-CMS home (SPEC §8.3 / Decision G). The landing surfaces the latest PUBLISHED
 * articles + ACTIVE jobs across EVERY org (public is cross-org). WARN 5: it used to render
 * hardcoded empty arrays — these prove it now renders real content, and FALSIFIABLY that a
 * DRAFT / unpublished article and an inactive (draft) job NEVER appear.
 */

it('renders the Inertia Landing component with the article + job props present', function (): void {
    get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Landing/Index')
            ->has('latestArticles')
            ->has('latestJobs'));
});

it('surfaces a PUBLISHED article + an ACTIVE job on the home page', function (): void {
    $org = Organization::factory()->create();
    $branch = Branch::factory()->for($org)->create();

    $published = Article::factory()->forOrganization($org)->published()->create([
        'branch_id' => $branch->getKey(),
        'title' => 'Published Headline',
    ]);
    $activeJob = JobPosting::factory()->forOrganization($org)->active()->create([
        'title' => 'Active Vacancy',
    ]);

    get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Landing/Index')
            ->has('latestArticles', 1)
            ->where('latestArticles.0.id', $published->getKey())
            ->where('latestArticles.0.title', 'Published Headline')
            ->where('latestArticles.0.slug', $published->slug)
            ->has('latestJobs', 1)
            ->where('latestJobs.0.id', $activeJob->getKey())
            ->where('latestJobs.0.title', 'Active Vacancy'));
});

it('NEVER surfaces a draft/unpublished article nor an inactive job (falsifiable status filtering)', function (): void {
    $org = Organization::factory()->create();
    $branch = Branch::factory()->for($org)->create();

    // A DRAFT article + a DRAFT job — both must be absent from the public home.
    Article::factory()->forOrganization($org)->draft()->create([
        'branch_id' => $branch->getKey(),
        'title' => 'Secret Draft',
    ]);
    JobPosting::factory()->forOrganization($org)->draft()->create([
        'title' => 'Unpublished Job',
    ]);

    get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Landing/Index')
            ->has('latestArticles', 0) // the draft article never leaks
            ->has('latestJobs', 0));    // the draft job never leaks

    // FALSIFIABLE: the rows physically exist — they were hidden by the status filter, not absent.
    expect(Article::count())->toBe(1);
    expect(JobPosting::count())->toBe(1);
});

it('shows published articles cross-org (a published article of ANY org appears on the home)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $branchA = Branch::factory()->for($orgA)->create();
    $branchB = Branch::factory()->for($orgB)->create();

    Article::factory()->forOrganization($orgA)->published()->create(['branch_id' => $branchA->getKey()]);
    Article::factory()->forOrganization($orgB)->published()->create(['branch_id' => $branchB->getKey()]);

    get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Landing/Index')
            ->has('latestArticles', 2)); // both orgs' published articles appear (cross-org)
});
