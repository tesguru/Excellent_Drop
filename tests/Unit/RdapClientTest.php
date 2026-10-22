<?php

namespace Tests\Unit;

use App\Services\Rdap\RdapBootstrap;
use App\Services\Rdap\RdapClient;
use Tests\TestCase;

class RdapClientTest extends TestCase
{
    public function test_it_normalises_the_registry_statuses(): void
    {
        $client = new RdapClient(config('zones.rdap'), new RdapBootstrap(config('zones.rdap')));

        $this->assertSame('pending delete', $client->normaliseStatus(['pending delete']));
        $this->assertSame('redemption period', $client->normaliseStatus(['redemption period']));
        $this->assertSame('hold', $client->normaliseStatus(['client hold']));
        $this->assertSame('active', $client->normaliseStatus([]));
        $this->assertSame('client transfer prohibited', $client->normaliseStatus(['client transfer prohibited']));
    }
}
