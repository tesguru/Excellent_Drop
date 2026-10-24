<?php

namespace Tests\Unit;

use App\Support\LookupLink;
use Tests\TestCase;

class LookupLinkTest extends TestCase
{
    public function test_it_fills_the_configured_template_with_an_encoded_domain(): void
    {
        config(['zones.lookup_link_template' => 'https://lookup.test/domain/{domain}?source=dropwatch']);

        $this->assertSame(
            'https://lookup.test/domain/acme%2Fevil.com?source=dropwatch',
            LookupLink::for('ACME/evil.com'),
        );
    }

    public function test_the_default_builds_an_rdap_org_link(): void
    {
        config(['zones.lookup_link_template' => 'https://rdap.org/domain/{domain}']);

        $this->assertSame(
            'https://rdap.org/domain/acme.com',
            LookupLink::for('acme.com'),
        );
    }

    public function test_it_refuses_a_template_that_is_not_http(): void
    {
        config(['zones.lookup_link_template' => 'javascript:alert({domain})']);

        $this->assertSame(
            'https://rdap.org/domain/acme.com',
            LookupLink::for('acme.com'),
        );
    }
}
