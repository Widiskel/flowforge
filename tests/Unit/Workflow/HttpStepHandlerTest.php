<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Domain\Workflow\Enums\StepRunStatus;
use App\Domain\Workflow\Handlers\HttpStepHandler;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpStepHandlerTest extends TestCase
{
    private HttpStepHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new HttpStepHandler;
        Http::preventStrayRequests();
    }

    public function test_url_with_disallowed_scheme_is_rejected_before_request(): void
    {
        $result = $this->handler->handle(['url' => 'file:///etc/passwd', 'method' => 'GET'], []);

        $this->assertSame(StepRunStatus::FAILED, $result->status);
        $this->assertStringContainsString('scheme', (string) $result->error);
    }

    public function test_unparseable_url_is_rejected(): void
    {
        $result = $this->handler->handle(['url' => 'http://', 'method' => 'GET'], []);

        $this->assertSame(StepRunStatus::FAILED, $result->status);
        $this->assertNotNull($result->error);
    }

    public function test_private_ip_is_rejected_when_allow_private_network_is_false(): void
    {
        config(['flowforge.http_step_allow_private_network' => false]);

        $result = $this->handler->handle(['url' => 'http://127.0.0.1/internal', 'method' => 'GET'], []);

        $this->assertSame(StepRunStatus::FAILED, $result->status);
        $this->assertStringContainsString('private/loopback', (string) $result->error);
    }

    public function test_private_ip_is_allowed_in_dev_mode(): void
    {
        config(['flowforge.http_step_allow_private_network' => true]);

        Http::fake([
            'http://127.0.0.1/playground' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->handler->handle(['url' => 'http://127.0.0.1/playground', 'method' => 'GET'], []);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
    }

    public function test_https_public_url_is_allowed(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->handler->handle(['url' => 'https://api.example.com/users', 'method' => 'GET'], []);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
    }

    public function test_empty_url_falls_back_to_noop(): void
    {
        $result = $this->handler->handle(['url' => ''], []);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertSame('noop', $result->output['operation'] ?? null);
    }
}
