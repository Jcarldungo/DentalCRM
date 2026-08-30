<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function headerProvider(): array
    {
        return [
            'nosniff' => ['X-Content-Type-Options', 'nosniff'],
            'framing' => ['X-Frame-Options', 'DENY'],
            'referrer' => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
            'permissions' => ['Permissions-Policy', 'camera=(), microphone=(), geolocation=()'],
        ];
    }

    #[DataProvider('headerProvider')]
    public function test_every_public_response_carries_the_header(string $header, string $value): void
    {
        $this->get(route('home'))->assertHeader($header, $value);
    }

    #[DataProvider('headerProvider')]
    public function test_every_staff_response_carries_the_header(string $header, string $value): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertHeader($header, $value);
    }

    public function test_the_policy_blocks_framing_and_inline_script(): void
    {
        $policy = $this->get(route('home'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
    }

    /**
     * The only inline script is Ziggy's @routes blob, so a nonce is what
     * makes a strict script-src affordable — but only if the nonce in the
     * header is the one actually stamped on the tag.
     */
    public function test_the_csp_nonce_matches_the_one_rendered_into_the_page(): void
    {
        $response = $this->get(route('home'));

        preg_match("/'nonce-([A-Za-z0-9]+)'/", $response->headers->get('Content-Security-Policy'), $fromHeader);
        $this->assertNotEmpty($fromHeader, 'The CSP carries no nonce.');

        $response->assertSee('nonce="'.$fromHeader[1].'"', false);
    }

    /**
     * Recharts, FullCalendar, and Inertia's progress bar all emit inline
     * styles that a nonce cannot cover, and FullCalendar embeds its
     * navigation icons as a data: font. Both allowances are deliberate —
     * this test exists so removing either is a decision, not an accident.
     */
    public function test_the_documented_style_and_font_allowances_are_present(): void
    {
        $policy = $this->get(route('home'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringContainsString('font-src \'self\' data:', $policy);
    }

    public function test_hsts_is_sent_only_over_a_secure_request(): void
    {
        $this->get(route('home'))->assertHeaderMissing('Strict-Transport-Security');

        $this->get(str_replace('http://', 'https://', route('home')))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
