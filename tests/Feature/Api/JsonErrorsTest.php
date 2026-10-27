<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * API errors are always JSON, whatever the Accept header or locale (FR-019).
 */
class JsonErrorsTest extends TestCase
{
    public function test_unknown_api_route_returns_json_404_even_for_html_requests(): void
    {
        $this->get('/api/v1/does-not-exist', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_404_structure_is_identical_in_both_locales(): void
    {
        $en = $this->get('/api/v1/does-not-exist', ['Accept' => 'text/html', 'Accept-Language' => 'en']);
        $ar = $this->get('/api/v1/does-not-exist', ['Accept' => 'text/html', 'Accept-Language' => 'ar']);

        $en->assertNotFound();
        $ar->assertNotFound();
        $this->assertSame(array_keys($en->json()), array_keys($ar->json()));
    }
}
