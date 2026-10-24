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
            'https://lookup.test/domain/acme%2Fevil.net?source=dropwatch',
            LookupLink::for('ACME/evil.net'),
        );
    }

    public function test_the_default_com_link_uses_the_registry_endpoint(): void
    {
        config([
            'zones.lookup_link_template' => 'https://rdap.org/domain/{domain}',
            'zones.lookup_link_template_com' => 'https://rdap.verisign.com/com/v1/domain/{domain}',
        ]);

        $this->assertSame(
            'https://rdap.verisign.com/com/v1/domain/acme.com',
            LookupLink::for('acme.com'),
        );
        $this->assertSame('com', LookupLink::source('acme.com'));
    }

    public function test_other_tlds_keep_the_generic_template(): void
    {
        config([
            'zones.lookup_link_template' => 'https://rdap.org/domain/{domain}',
            'zones.lookup_link_template_com' => 'https://rdap.verisign.com/com/v1/domain/{domain}',
        ]);

        $this->assertSame(
            'https://rdap.org/domain/acme.net',
            LookupLink::for('acme.net'),
        );
        $this->assertSame('rdap', LookupLink::source('acme.net'));
    }

    public function test_it_refuses_a_template_that_is_not_http(): void
    {
        config(['zones.lookup_link_template' => 'javascript:alert({domain})']);

        $this->assertSame(
            'https://rdap.org/domain/acme.net',
            LookupLink::for('acme.net'),
        );
    }

    public function test_an_invalid_com_template_falls_back_to_the_generic_one(): void
    {
        config([
            'zones.lookup_link_template' => 'https://lookup.test/domain/{domain}',
            'zones.lookup_link_template_com' => 'javascript:alert({domain})',
        ]);

        $this->assertSame(
            'https://lookup.test/domain/acme.com',
            LookupLink::for('acme.com'),
        );
    }
}
