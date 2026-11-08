<?php

namespace Tests\Feature;

use App\Models\JobRun;
use App\Models\User;
use App\Models\ZoneTld;
use App\Services\CandidateRepository;
use App\Services\Zone\ZoneSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCandidateRepository;
use Tests\Fakes\FakeZoneSearch;
use Tests\TestCase;

class DashboardPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(CandidateRepository::class, new FakeCandidateRepository(
            candidates: [
                [
                    'event_date' => now()->format('Y-m-d'),
                    'name' => 'acmeplumbing',
                    'domain' => 'acmeplumbing.com',
                    'weaker_found' => ['acme-plumbing.net', 'acmeplumbing.net'],
                    'weaker_types' => ['hyphen', 'other_tld'],
                    'weaker_count' => 2,
                    'tld_count' => 1,
                    'exact_tld_count' => 3,
                    'is_short' => false,
                    'score' => 91.5,
                    'estimated_drop_date' => now()->addDays(35)->format('Y-m-d'),
                    'watchlisted' => false,
                ],
                [
                    'event_date' => now()->format('Y-m-d'),
                    'name' => 'oak',
                    'domain' => 'oak.com',
                    'weaker_found' => ['oak.net'],
                    'weaker_types' => ['other_tld'],
                    'weaker_count' => 1,
                    'tld_count' => 1,
                    'exact_tld_count' => 1,
                    'is_short' => true,
                    'score' => 72.0,
                    'estimated_drop_date' => now()->addDays(35)->format('Y-m-d'),
                    'watchlisted' => false,
                ],
            ],
            removed: ['anothername'],
            added: ['newname'],
        ));

        $this->app->instance(ZoneSearch::class, new FakeZoneSearch(payload: [
            'results' => [
                [
                    'domain' => 'acmeplumbing.com',
                    'tld' => 'com',
                    'name' => 'acmeplumbing',
                    'removed_on' => now()->subDay()->format('Y-m-d'),
                    'added_on' => null,
                    'lookup_url' => 'https://rdap.org/domain/acmeplumbing.com',
                ],
                [
                    'domain' => 'acmeplumbing.net',
                    'tld' => 'net',
                    'name' => 'acmeplumbing',
                    'removed_on' => null,
                    'added_on' => now()->subDay()->format('Y-m-d'),
                    'lookup_url' => 'https://rdap.org/domain/acmeplumbing.net',
                ],
            ],
            'latest' => now()->format('Y-m-d'),
        ]));

        $this->actingAs(User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ]));
    }

    public function test_the_overview_shows_the_candidates(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('acmeplumbing.com')
            ->assertSee('acme-plumbing.net')
            ->assertSee('estimated drop')
            ->assertSee('Check status')
            ->assertSee('View raw')
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('https://rdap.verisign.com/com/v1/domain/acmeplumbing.com', false)
            ->assertSee('https://rdap.org/domain/acme-plumbing.net', false);
    }

    public function test_the_daily_list_shows_the_removed_names(): void
    {
        $this->get('/daily')->assertOk()->assertSee('acmeplumbing');
    }

    public function test_the_daily_candidates_open_in_a_modal_with_their_variations(): void
    {
        $this->get('/daily')
            ->assertOk()
            ->assertSee('data-modal-open="daily-candidate-0"', false)
            ->assertSee('<dialog', false)
            ->assertSee('Weaker variations')
            ->assertSee('acme-plumbing.net')
            ->assertSee('RDAP status')
            ->assertSee('Check status')
            ->assertSee('View raw')
            ->assertSee('Track this name')
            ->assertSee('2 variations');
    }

    public function test_daily_candidates_can_be_searched_by_keyword(): void
    {
        $this->get('/daily?q=oak')
            ->assertOk()
            ->assertSee('oak.com')
            ->assertDontSee('acmeplumbing.com');

        $this->get('/daily?q=acmeplumbing.com')
            ->assertOk()
            ->assertSee('acmeplumbing.com')
            ->assertDontSee('oak.com');

        $this->getJson('/api/daily?q=acmeplumbing.com')
            ->assertOk()
            ->assertJsonPath('query', 'acmeplumbing.com')
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.domain', 'acmeplumbing.com');
    }

    public function test_the_daily_candidates_are_paginated(): void
    {
        $candidates = [];

        for ($index = 0; $index < 30; $index++) {
            $candidates[] = [
                'event_date' => now()->format('Y-m-d'),
                'name' => 'candidate'.$index,
                'domain' => 'candidate'.$index.'.com',
                'weaker_found' => [],
                'weaker_types' => [],
                'weaker_count' => 0,
                'tld_count' => 1,
                'exact_tld_count' => 1,
                'is_short' => false,
                'score' => 100 - $index,
                'estimated_drop_date' => now()->addDays(35)->format('Y-m-d'),
                'watchlisted' => false,
            ];
        }

        $this->app->instance(CandidateRepository::class, new FakeCandidateRepository(candidates: $candidates));

        $this->get('/daily?page=2')
            ->assertOk()
            ->assertSee('candidate25.com')
            ->assertSee('Page 2 of 2')
            ->assertSee('26&ndash;30', false)
            ->assertSee('data-page-mover', false)
            ->assertSee('aria-label="Go to page"', false)
            ->assertDontSee('candidate0.com');

        $this->get('/daily?q=candidate&page=2')
            ->assertOk()
            ->assertSee('name="q" value="candidate"', false);

        $this->getJson('/api/daily?page=2')
            ->assertOk()
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.total', 30)
            ->assertJsonPath('pagination.pages', 2)
            ->assertJsonCount(5, 'candidates')
            ->assertJsonPath('candidates.0.domain', 'candidate25.com');
    }

    public function test_the_short_badge_and_the_short_filter_are_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Is short')
            ->assertSee('title="Name has 4 or fewer characters"', false)
            ->assertSee('oak.com');

        $this->get('/?short=1')
            ->assertOk()
            ->assertSee('oak.com')
            ->assertSee('Show all')
            ->assertDontSee('acmeplumbing.com');

        $this->get('/daily?short=1')
            ->assertOk()
            ->assertSee('oak.com')
            ->assertSee('Show all')
            ->assertDontSee('acmeplumbing.com');

        $this->getJson('/api/daily?short=1')
            ->assertOk()
            ->assertJsonPath('short_only', true);

        $this->getJson('/api/overview?short=1')
            ->assertOk()
            ->assertJsonPath('short_only', true);
    }

    public function test_the_endings_badge_and_filter_are_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('2+ endings')
            ->assertSee('3 endings')
            ->assertSee('1 ending');

        $this->get('/?endings=1')
            ->assertOk()
            ->assertSee('acmeplumbing.com')
            ->assertDontSee('oak.com');

        $this->get('/daily?endings=1')
            ->assertOk()
            ->assertSee('acmeplumbing.com')
            ->assertDontSee('oak.com');

        $this->getJson('/api/daily?endings=1')
            ->assertOk()
            ->assertJsonPath('endings_only', true);

        $this->getJson('/api/overview?endings=1')
            ->assertOk()
            ->assertJsonPath('endings_only', true);
    }

    public function test_daily_candidates_can_be_filtered_by_variation_count(): void
    {
        $this->get('/daily?min_variations=2')
            ->assertOk()
            ->assertSee('acmeplumbing.com')
            ->assertDontSee('oak.com');

        $this->get('/daily?max_variations=1')
            ->assertOk()
            ->assertSee('oak.com')
            ->assertDontSee('acmeplumbing.com');

        $this->get('/daily?exact_variations=2')
            ->assertOk()
            ->assertSee('acmeplumbing.com')
            ->assertDontSee('oak.com');

        $this->get('/daily?exact_variations=1&min_variations=2')
            ->assertOk()
            ->assertSee('oak.com')
            ->assertDontSee('acmeplumbing.com');

        $this->getJson('/api/daily?min_variations=2')
            ->assertOk()
            ->assertJsonPath('min_variations', 2)
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.domain', 'acmeplumbing.com');

        $this->getJson('/api/daily?exact_variations=1')
            ->assertOk()
            ->assertJsonPath('exact_variations', 1)
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.domain', 'oak.com');
    }

    public function test_the_search_page_shows_the_results(): void
    {
        $this->get('/search?q=acmeplumbing')
            ->assertOk()
            ->assertSee('acmeplumbing.com')
            ->assertSee('acmeplumbing.net')
            ->assertSee('Removed '.now()->subDay()->format('Y-m-d'))
            ->assertSee('Added '.now()->subDay()->format('Y-m-d'))
            ->assertSee('latest snapshot')
            ->assertSee('Hide digits')
            ->assertSee('Hide hyphens');

        $this->getJson('/api/search?q=acmeplumbing&mode=ends&tld[]=com&min_length=3')
            ->assertOk()
            ->assertJsonPath('query', 'acmeplumbing')
            ->assertJsonPath('mode', 'ends')
            ->assertJsonPath('tlds', ['com'])
            ->assertJsonPath('min_length', 3)
            ->assertJsonCount(2, 'results');
    }

    public function test_the_search_page_shows_a_too_broad_error(): void
    {
        $this->app->instance(ZoneSearch::class, new FakeZoneSearch(payload: [
            'error' => 'The search is too broad. Add a TLD, more characters or a length filter.',
        ]));

        $this->get('/search?q=acme')
            ->assertOk()
            ->assertSee('too broad')
            ->assertDontSee('Zone results');
    }

    public function test_the_jobs_pages_render(): void
    {
        JobRun::create([
            'run_id' => '11111111-1111-4111-8111-111111111111',
            'step' => 'diff',
            'status' => 'success',
            'rows' => 3,
            'message' => 'com: 2 removed, 1 added',
            'queued_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->get('/jobs')->assertOk()->assertSee('diff');
        $this->get('/jobs/11111111-1111-4111-8111-111111111111')->assertOk()->assertSee('com: 2 removed, 1 added');
    }

    public function test_the_zones_page_renders(): void
    {
        $this->get('/zones')->assertOk();
    }

    public function test_the_zones_page_shows_the_published_zone_date(): void
    {
        $lastModified = now()->subHours(2)->startOfMinute();

        ZoneTld::create([
            'tld' => 'com',
            'status' => 'downloaded',
            'last_download_at' => now(),
            'last_modified' => $lastModified,
            'file_size' => 1024,
            'rows' => 10,
        ]);

        $this->get('/zones')
            ->assertOk()
            ->assertSee('Zone date')
            ->assertSee($lastModified->format('Y-m-d H:i'))
            ->assertSee('published by ICANN')
            ->assertSee('today');
    }

    public function test_the_json_endpoints_answer(): void
    {
        $this->getJson('/api/overview')->assertOk()->assertJsonStructure([
            'counts' => ['removed', 'added', 'candidates'],
            'worker' => ['alive'],
            'top_candidates',
        ]);

        $this->getJson('/api/daily')->assertOk()->assertJsonStructure(['counts', 'removed_names', 'candidates']);
        $this->getJson('/api/search?q=acmeplumbing')->assertOk()->assertJsonStructure(['query', 'results']);
        $this->getJson('/api/jobs')->assertOk()->assertJsonStructure(['runs']);
        $this->getJson('/api/zones')->assertOk()->assertJsonStructure(['zones']);
    }
}
