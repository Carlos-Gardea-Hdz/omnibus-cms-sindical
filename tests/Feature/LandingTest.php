<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

test('the landing page renders the Inertia Landing component', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Landing/Index')
            ->has('latestArticles')
            ->has('latestJobs')
        );
});
