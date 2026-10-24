<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\CandidateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCandidateRepository;
use Tests\TestCase;

class WatchlistTest extends TestCase
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
                    'watchlisted' => true,
                ],
            ],
        ));

        $this->actingAs(User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ]));
    }

    public function test_a_domain_can_be_added_removed_and_annotated(): void
    {
        $this->postJson('/watchlist', [
            'domain' => 'acmeplumbing.com',
            'status' => 'watching',
        ])->assertCreated()->assertJsonPath('entry.domain', 'acmeplumbing.com');

        $this->assertDatabaseHas('watchlist', ['domain' => 'acmeplumbing.com']);

        $this->postJson('/watchlist/acmeplumbing.com/notes', [
            'body' => 'Owner asked about the price.',
        ])->assertCreated()->assertJsonPath('note.body', 'Owner asked about the price.');

        $this->assertDatabaseHas('notes', ['domain' => 'acmeplumbing.com']);

        $this->putJson('/watchlist/acmeplumbing.com', ['status' => 'dropped'])
            ->assertOk()
            ->assertJsonPath('entry.status', 'dropped');

        $this->deleteJson('/watchlist/acmeplumbing.com')->assertOk();

        $this->assertDatabaseCount('watchlist', 0);
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_the_watchlist_page_lists_the_entries_in_a_modal(): void
    {
        Watchlist::create(['domain' => 'acmeplumbing.com', 'status' => 'watching']);

        Note::create(['domain' => 'acmeplumbing.com', 'body' => 'Owner asked about the price.']);

        $this->get('/watchlist')
            ->assertOk()
            ->assertSee('Watched names')
            ->assertSee('acmeplumbing.com')
            ->assertSee('Watching')
            ->assertSee('data-modal-open', false)
            ->assertSee('<dialog', false)
            ->assertSee('Weaker variations')
            ->assertSee('acme-plumbing.net')
            ->assertSee('RDAP status')
            ->assertSee('Check status')
            ->assertSee('View raw')
            ->assertSee('Notes')
            ->assertSee('Owner asked about the price.')
            ->assertSee('Add a note')
            ->assertSee('Remove from watchlist')
            ->assertSee('2 variations');

        $this->getJson('/api/watchlist')
            ->assertOk()
            ->assertJsonPath('query', '')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('entries.0.domain', 'acmeplumbing.com')
            ->assertJsonPath('entries.0.candidate.weaker_found', ['acme-plumbing.net', 'acmeplumbing.net']);
    }

    public function test_watchlist_entries_can_be_searched_by_domain_or_note(): void
    {
        Watchlist::create(['domain' => 'acmeplumbing.com', 'status' => 'watching']);
        Watchlist::create(['domain' => 'oak.com', 'status' => 'dropped']);

        Note::create(['domain' => 'oak.com', 'body' => 'Renewal window closes soon.']);

        $this->get('/watchlist?q=acmeplumbing')
            ->assertOk()
            ->assertSee('acmeplumbing.com')
            ->assertDontSee('oak.com');

        $this->get('/watchlist?q=RENEWAL')
            ->assertOk()
            ->assertSee('oak.com')
            ->assertDontSee('acmeplumbing.com');

        $this->getJson('/api/watchlist?q=acmeplumbing.com')
            ->assertOk()
            ->assertJsonPath('query', 'acmeplumbing.com')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('entries.0.domain', 'acmeplumbing.com');
    }

    public function test_the_watchlist_is_paginated(): void
    {
        for ($index = 0; $index < 30; $index++) {
            Watchlist::create([
                'domain' => sprintf('watched%02d.com', $index),
                'status' => 'watching',
            ]);
        }

        $this->get('/watchlist')
            ->assertOk()
            ->assertSee('watched00.com')
            ->assertSee('Page 1 of 2')
            ->assertSee('1&ndash;25', false);

        $this->get('/watchlist?page=2')
            ->assertOk()
            ->assertSee('watched25.com')
            ->assertDontSee('watched00.com')
            ->assertSee('Page 2 of 2')
            ->assertSee('26&ndash;30', false);

        $this->getJson('/api/watchlist?page=99')
            ->assertOk()
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.total', 30)
            ->assertJsonPath('pagination.pages', 2)
            ->assertJsonCount(5, 'entries');
    }

    public function test_an_unknown_domain_cannot_be_updated(): void
    {
        $this->putJson('/watchlist/missing.com', ['status' => 'dropped'])->assertNotFound();
    }
}
