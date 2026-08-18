<?php

namespace Tests\Unit;

use App\Support\PanelEndpointIdentity;
use PHPUnit\Framework\TestCase;

class PanelEndpointIdentityTest extends TestCase
{
    public function test_canonicalizes_only_case_insensitive_url_components(): void
    {
        $this->assertSame(
            'https://panel.example.com/PanelA',
            PanelEndpointIdentity::canonicalUrl(
                ' HTTPS://Panel.Example.COM:443/PanelA/ '
            )
        );
        $this->assertSame(
            'http://panel.example.com/PanelA',
            PanelEndpointIdentity::canonicalUrl(
                'http://PANEL.example.com:80/PanelA'
            )
        );
        $this->assertSame(
            'https://panel.example.com:8443/PanelA',
            PanelEndpointIdentity::canonicalUrl(
                'https://Panel.Example.com:8443/PanelA/'
            )
        );
    }

    public function test_path_case_produces_distinct_panel_identities(): void
    {
        $this->assertNotSame(
            PanelEndpointIdentity::hash(
                'https://panel.example.com/PanelA'
            ),
            PanelEndpointIdentity::hash(
                'https://panel.example.com/panela'
            )
        );
        $this->assertSame(
            PanelEndpointIdentity::hash(
                'https://PANEL.example.com:443/PanelA/'
            ),
            PanelEndpointIdentity::hash(
                'https://panel.example.com/PanelA'
            )
        );
    }

    public function test_rejects_ambiguous_or_secret_bearing_panel_urls(): void
    {
        foreach ([
            'https://user@panel.example.com/PanelA',
            'https://panel.example.com/PanelA?token=secret',
            'https://panel.example.com/PanelA#fragment',
            'https://panel.example.com/a/../PanelA',
            'https://panel.example.com/a//PanelA',
            'https://panel.example.com/a\\PanelA',
            'https://panel.example.com/%50anelA',
            'https://panel.example.com/%2e%2e/PanelA',
        ] as $url) {
            try {
                PanelEndpointIdentity::canonicalUrl($url);
                $this->fail("Expected {$url} to be rejected.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
