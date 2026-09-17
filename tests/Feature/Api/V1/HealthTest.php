<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_public_and_reports_a_reachable_database(): void
    {
        // Act
        $response = $this->getJson('/api/v1/health');

        // Assert
        $response->assertOk()
            ->assertJson([
                'status' => true,
                'data' => [
                    'api_version' => 'v1',
                    'database_connected' => true,
                ],
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['application', 'environment', 'api_version', 'database_connected'],
            ]);
    }

    public function test_unknown_api_route_returns_the_envelope_not_an_html_page(): void
    {
        // Act
        $response = $this->getJson('/api/v1/does-not-exist');

        // Assert
        $response->assertNotFound()
            ->assertJson([
                'status' => false,
                'message' => 'العنصر المطلوب غير موجود',
                'data' => null,
            ]);
    }
}
