<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Domain\Workflow\Enums\StepRunStatus;
use App\Domain\Workflow\Handlers\LogStepHandler;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogStepHandlerTest extends TestCase
{
    private LogStepHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new LogStepHandler;
    }

    public function test_writes_a_resolved_log_line(): void
    {
        Log::spy();

        $result = $this->handler->handle([
            'level' => 'info',
            'message' => 'User {{ fetch.json.id }} status={{ fetch.status }}',
            'context' => ['source' => 'workflow', 'name' => '{{ fetch.json.name }}'],
        ], [
            'fetch' => ['status' => 200, 'json' => ['id' => 42, 'name' => 'Audrey']],
        ]);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertSame('User 42 status=200', $result->output['message']);
        $this->assertSame('Audrey', $result->output['context']['name']);

        Log::shouldHaveReceived('log')
            ->with('info', '[workflow] User 42 status=200', ['source' => 'workflow', 'name' => 'Audrey'])
            ->once();
    }

    public function test_unresolved_placeholders_render_empty(): void
    {
        Log::spy();

        $result = $this->handler->handle([
            'level' => 'info',
            'message' => 'missing={{ does.not.exist }} ok',
        ], []);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertSame('missing= ok', $result->output['message']);
    }

    public function test_invalid_level_is_rejected(): void
    {
        $result = $this->handler->handle([
            'level' => 'panic',
            'message' => 'hi',
        ], []);

        $this->assertSame(StepRunStatus::FAILED, $result->status);
        $this->assertStringContainsString('Unsupported log level', (string) $result->error);
    }

    public function test_empty_message_is_rejected(): void
    {
        $result = $this->handler->handle([
            'level' => 'info',
            'message' => '   ',
        ], []);

        $this->assertSame(StepRunStatus::FAILED, $result->status);
        $this->assertStringContainsString('non-empty', (string) $result->error);
    }

    public function test_default_level_is_info(): void
    {
        Log::spy();

        $result = $this->handler->handle([
            'message' => 'hello',
        ], []);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertSame('info', $result->output['level']);
    }
}
