<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Root redirects to official public landing.
     */
    public function test_root_route_redirects_to_public_website(): void
    {
        $response = $this->get('/');

        $response->assertStatus(301);
        $response->assertRedirect('https://www.fatturafacile.eu');
    }

    /**
     * Swagger UI is publicly accessible without authentication.
     */
    public function test_swagger_ui_is_publicly_accessible(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertStatus(200);
    }

    /**
     * Swagger docs endpoint is publicly accessible.
     */
    public function test_swagger_docs_endpoint_is_accessible(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
    }

    /**
     * Swagger docs json endpoint is publicly accessible.
     */
    public function test_swagger_docs_json_is_accessible(): void
    {
        $response = $this->get('/docs/api-docs.json');

        $response->assertStatus(200);
    }
}
