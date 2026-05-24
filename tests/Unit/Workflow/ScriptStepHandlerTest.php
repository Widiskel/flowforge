<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Domain\Workflow\Enums\StepRunStatus;
use App\Domain\Workflow\Handlers\ScriptStepHandler;
use Tests\TestCase;

class ScriptStepHandlerTest extends TestCase
{
    private ScriptStepHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ScriptStepHandler;
    }

    public function test_returns_explicit_value(): void
    {
        $this->skipUnlessNodeAvailable();

        $result = $this->handler->handle([
            'script' => 'return { sum: $doc.input.fetch.json.id + 8 };',
        ], [
            'fetch' => ['json' => ['id' => 42]],
        ]);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertSame(50, $result->output['output']['sum']);
    }

    public function test_assigning_to_doc_output_works(): void
    {
        $this->skipUnlessNodeAvailable();

        $result = $this->handler->handle([
            'script' => '$doc.output = { hello: "world" };',
        ], []);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertSame('world', $result->output['output']['hello']);
    }

    public function test_doc_input_carries_upstream_context(): void
    {
        $this->skipUnlessNodeAvailable();

        $result = $this->handler->handle([
            'script' => 'return Object.keys($doc.input);',
        ], [
            'fetch_user' => ['id' => 1],
            'normalize' => ['ok' => true],
        ]);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertEqualsCanonicalizing(['fetch_user', 'normalize'], $result->output['output']);
    }

    public function test_doc_config_carries_step_config_minus_script(): void
    {
        $this->skipUnlessNodeAvailable();

        $result = $this->handler->handle([
            'script' => 'return $doc.config;',
            'mode' => 'demo',
            'tags' => ['a', 'b'],
        ], []);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertSame('demo', $result->output['output']['mode']);
        $this->assertSame(['a', 'b'], $result->output['output']['tags']);
        $this->assertArrayNotHasKey('script', $result->output['output']);
    }

    public function test_console_log_is_captured(): void
    {
        $this->skipUnlessNodeAvailable();

        $result = $this->handler->handle([
            'script' => 'console.log("hello", { a: 1 }); return null;',
        ], []);

        $this->assertSame(StepRunStatus::SUCCESS, $result->status);
        $this->assertNotEmpty($result->output['logs']);
        $this->assertSame('log', $result->output['logs'][0]['level']);
        $this->assertStringContainsString('hello', $result->output['logs'][0]['message']);
    }

    public function test_thrown_error_is_propagated(): void
    {
        $this->skipUnlessNodeAvailable();

        $result = $this->handler->handle([
            'script' => 'throw new Error("boom");',
        ], []);

        $this->assertSame(StepRunStatus::FAILED, $result->status);
        $this->assertSame('boom', $result->error);
    }

    public function test_empty_script_is_rejected(): void
    {
        $result = $this->handler->handle(['script' => '   '], []);

        $this->assertSame(StepRunStatus::FAILED, $result->status);
        $this->assertStringContainsString('non-empty', (string) $result->error);
    }

    public function test_oversized_script_is_rejected(): void
    {
        $result = $this->handler->handle([
            'script' => str_repeat('a', 16_385),
        ], []);

        $this->assertSame(StepRunStatus::FAILED, $result->status);
        $this->assertStringContainsString('max', (string) $result->error);
    }

    private function skipUnlessNodeAvailable(): void
    {
        $node = (string) config('flowforge.node_binary', 'node');
        $check = @shell_exec(sprintf('%s --version 2>/dev/null', escapeshellcmd($node)));
        if (! $check) {
            $this->markTestSkipped('Node.js binary not available; skipping JavaScript handler test.');
        }
    }
}
