<?php

namespace Tests\Feature;

use App\Models\RdapStatus;
use App\Models\User;
use App\Services\CandidateRepository;
use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ]));
    }

    public function test_a_parsed_status_is_saved_and_updated(): void
    {
        $this->postJson('/domain-status', [
            'domain' => 'AcmePlumbing.com',
            'status' => 'on_hold',
            'status_label' => 'On hold',
            'expiry' => '2027-01-15',
            'checked_at' => '2026-10-24T12:00:00Z',
        ])
            ->assertCreated()
            ->assertJsonPath('status.domain', 'acmeplumbing.com')
            ->assertJsonPath('status.status', 'on_hold')
            ->assertJsonPath('status.status_label', 'On hold')
            ->assertJsonPath('status.expiry', '2027-01-15');

        $this->assertDatabaseHas('rdap_status', [
            'domain' => 'acmeplumbing.com',
            'status' => 'on_hold',
            'status_label' => 'On hold',
            'expiry' => '2027-01-15',
        ]);

        $this->postJson('/domain-status', [
            'domain' => 'acmeplumbing.com',
            'status' => 'renewed',
            'status_label' => 'Renewed to 2027-01-15',
            'expiry' => '2027-01-15',
            'checked_at' => '2026-10-25T12:00:00Z',
        ])->assertCreated();

        $this->assertDatabaseCount('rdap_status', 1);
        $this->assertDatabaseHas('rdap_status', [
            'domain' => 'acmeplumbing.com',
            'status' => 'renewed',
            'status_label' => 'Renewed to 2027-01-15',
        ]);
    }

    public function test_only_parsed_statuses_can_be_saved(): void
    {
        $this->postJson('/domain-status', [
            'domain' => 'acmeplumbing.com',
            'status' => 'rate_limited',
            'status_label' => 'Rate limited, wait a few minutes',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->postJson('/domain-status', [
            'domain' => 'not a domain',
            'status' => 'on_hold',
            'status_label' => 'On hold',
        ])->assertUnprocessable()->assertJsonValidationErrors('domain');
    }

    public function test_guests_cannot_save_a_status(): void
    {
        auth()->logout();

        $this->postJson('/domain-status', [
            'domain' => 'acmeplumbing.com',
            'status' => 'on_hold',
            'status_label' => 'On hold',
        ])->assertUnauthorized();
    }

    public function test_only_on_hold_and_renewed_com_names_are_hidden(): void
    {
        RdapStatus::create(['domain' => 'acme.com', 'status' => RdapStatus::ON_HOLD, 'status_label' => 'On hold']);
        RdapStatus::create(['domain' => 'oak.com', 'status' => RdapStatus::RENEWED, 'status_label' => 'Renewed']);
        RdapStatus::create(['domain' => 'pine.com', 'status' => RdapStatus::REDEMPTION, 'status_label' => 'In redemption']);
        RdapStatus::create(['domain' => 'acme.net', 'status' => RdapStatus::ON_HOLD, 'status_label' => 'On hold']);

        $this->assertEqualsCanonicalizing(['acme', 'oak'], RdapStatus::hiddenNames());
    }

    public function test_candidate_queries_exclude_hidden_names(): void
    {
        RdapStatus::create(['domain' => 'acme.com', 'status' => RdapStatus::ON_HOLD, 'status_label' => 'On hold']);
        RdapStatus::create(['domain' => 'oak.com', 'status' => RdapStatus::RENEWED, 'status_label' => 'Renewed']);
        RdapStatus::create(['domain' => 'pine.com', 'status' => RdapStatus::REDEMPTION, 'status_label' => 'In redemption']);

        $client = new class(config('zones.clickhouse')) extends ClickHouseClient
        {
            /** @var array<int, array{sql: string, params: array<string, mixed>}> */
            public array $calls = [];

            public function rows(string $sql, array $params = []): array
            {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                return [];
            }
        };

        $repository = new CandidateRepository($client);

        $repository->pageForDate('2026-10-24');

        $this->assertStringContainsString('c.name NOT IN {hidden:Array(String)}', $client->calls[0]['sql']);
        $this->assertEqualsCanonicalizing(['acme', 'oak'], $client->calls[0]['params']['hidden']);
    }
}
