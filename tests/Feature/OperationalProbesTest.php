<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalProbesTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_endpoint_returns_200(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    public function test_healthz_ready_returns_healthy_status(): void
    {
        $response = $this->getJson('/api/healthz/ready');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'checks' => [
                'database',
                'cache',
                'migrations',
            ],
            'timestamp',
        ]);
        $response->assertJsonPath('status', 'healthy');
    }

    public function test_healthz_startup_returns_healthy_status(): void
    {
        $response = $this->getJson('/api/healthz/startup');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'healthy');
    }

    public function test_actuator_health_returns_spring_boot_shape(): void
    {
        $response = $this->getJson('/api/actuator/health');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'components' => [
                'database' => ['status'],
                'cache' => ['status'],
                'migrations' => ['status'],
            ],
            'timestamp',
        ]);
        $response->assertJsonPath('status', 'UP');
        $response->assertJsonPath('components.database.status', 'UP');
    }

    public function test_probes_are_publicly_accessible_without_auth(): void
    {
        $response = $this->getJson('/api/healthz/ready');

        $response->assertStatus(200);
        $response->assertJsonMissing(['error' => 'Unauthenticated.']);
    }
}
