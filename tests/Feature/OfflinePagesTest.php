<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The panel runs on a server without internet access: pages must not load fonts, styles or scripts from other hosts.
 */
class OfflinePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_load_nothing_from_other_hosts(): void
    {
        $this->withoutVite();

        $this->assertLoadsNothingFromOtherHosts($this->get('/login'));
        if (Features::enabled(Features::registration())) {
            $this->assertLoadsNothingFromOtherHosts($this->get('/register'));
        }
        $this->assertLoadsNothingFromOtherHosts($this->get('/'));
        $this->assertLoadsNothingFromOtherHosts($this->get('/failures'));
        $this->assertLoadsNothingFromOtherHosts($this->actingAs(User::factory()->create())->get('/'));
    }

    private function assertLoadsNothingFromOtherHosts(TestResponse $response): void
    {
        $response->assertOk();
        preg_match_all('/<(?:link|script|img)\b[^>]*\b(?:href|src)="((?:https?:)?\/\/[^\/"]+)/i', $response->getContent(), $matches);
        $hosts = array_unique(array_map(fn (string $url): string => (string) parse_url($url, PHP_URL_HOST), $matches[1]));

        $this->assertSame([], array_values(array_diff($hosts, [parse_url((string) config('app.url'), PHP_URL_HOST)])));
    }
}
