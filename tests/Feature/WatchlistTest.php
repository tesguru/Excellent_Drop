<?php

namespace Tests\Feature;

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

        $this->app->instance(CandidateRepository::class, new FakeCandidateRepository);

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

    public function test_the_watchlist_page_lists_the_entries(): void
    {
        Watchlist::create(['domain' => 'acmeplumbing.com', 'status' => 'watching']);

        $this->get('/watchlist')->assertOk()->assertSee('acmeplumbing.com');

        $this->getJson('/api/watchlist')->assertOk()->assertJsonPath('entries.0.domain', 'acmeplumbing.com');
    }

    public function test_an_unknown_domain_cannot_be_updated(): void
    {
        $this->putJson('/watchlist/missing.com', ['status' => 'dropped'])->assertNotFound();
    }
}
