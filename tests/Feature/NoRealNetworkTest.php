<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The rule that no test reaches a real website. The guard lives in Tests\TestCase, so this test must not set it up
 * itself: if the base class ever stops forbidding stray requests, a request nobody faked would go out, and this fails.
 */
class NoRealNetworkTest extends TestCase
{
    public function test_a_request_nobody_faked_is_an_error_not_a_network_call(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a matching fake');

        Http::get('https://a-real-website.example/jobs');
    }

    public function test_a_faked_request_still_works(): void
    {
        Http::fake(['https://faked.example/*' => Http::response('ok', 200)]);

        $this->assertSame('ok', Http::get('https://faked.example/jobs')->body());
    }
}
