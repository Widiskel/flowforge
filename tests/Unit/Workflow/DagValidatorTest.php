<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Domain\Workflow\Exceptions\InvalidWorkflowDefinitionException;
use App\Domain\Workflow\Services\DagValidator;
use Tests\TestCase;

class DagValidatorTest extends TestCase
{
    private DagValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new DagValidator;
    }

    public function test_accepts_valid_definition(): void
    {
        $result = $this->validator->validate($this->definition());

        $this->assertSame($this->definition(), $result);
    }

    public function test_rejects_empty_steps(): void
    {
        $this->expectException(InvalidWorkflowDefinitionException::class);
        $this->expectExceptionMessage('Workflow must contain at least one step.');

        $this->validator->validate($this->definition(['steps' => []]));
    }

    public function test_rejects_duplicate_step_ids(): void
    {
        $definition = $this->definition([
            'steps' => [
                $this->step('same'),
                $this->step('same'),
            ],
        ]);

        $this->expectException(InvalidWorkflowDefinitionException::class);
        $this->expectExceptionMessage('Duplicate step id: same');

        $this->validator->validate($definition);
    }

    public function test_rejects_unknown_dependency(): void
    {
        $definition = $this->definition([
            'steps' => [
                $this->step('a', ['missing']),
            ],
        ]);

        $this->expectException(InvalidWorkflowDefinitionException::class);
        $this->expectExceptionMessage('Step a depends on unknown step missing.');

        $this->validator->validate($definition);
    }

    public function test_rejects_cycles(): void
    {
        $definition = $this->definition([
            'steps' => [
                $this->step('a', ['b']),
                $this->step('b', ['a']),
            ],
        ]);

        $this->expectException(InvalidWorkflowDefinitionException::class);
        $this->expectExceptionMessage('Workflow contains a cycle.');

        $this->validator->validate($definition);
    }

    public function test_rejects_unknown_step_type(): void
    {
        $definition = $this->definition([
            'steps' => [
                $this->step('a', [], ['type' => 'SHELL']),
            ],
        ]);

        $this->expectException(InvalidWorkflowDefinitionException::class);
        $this->expectExceptionMessage('Unknown step type: SHELL');

        $this->validator->validate($definition);
    }

    public function test_rejects_timeout_outside_bounds(): void
    {
        $definition = $this->definition(['globalTimeoutMs' => 999]);

        $this->expectException(InvalidWorkflowDefinitionException::class);
        $this->expectExceptionMessage('globalTimeoutMs must be between 1000 and 600000.');

        $this->validator->validate($definition);
    }

    public function test_rejects_retry_bounds(): void
    {
        $definition = $this->definition([
            'steps' => [
                $this->step('a', [], ['retry' => ['maxAttempts' => 6]]),
            ],
        ]);

        $this->expectException(InvalidWorkflowDefinitionException::class);
        $this->expectExceptionMessage('Step a retry maxAttempts must be between 1 and 5.');

        $this->validator->validate($definition);
    }

    private function definition(array $overrides = []): array
    {
        $definition = [
            'schemaVersion' => 1,
            'name' => 'Incident notifier',
            'globalTimeoutMs' => 300000,
            'steps' => [
                $this->step('fetch_status'),
                $this->step('notify', ['fetch_status'], ['type' => 'SCRIPT']),
            ],
        ];

        foreach ($overrides as $key => $value) {
            $definition[$key] = $value;
        }

        return $definition;
    }

    private function step(string $id, array $dependsOn = [], array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'type' => 'HTTP',
            'name' => ucfirst(str_replace('_', ' ', $id)),
            'dependsOn' => $dependsOn,
            'timeoutMs' => 10000,
            'retry' => ['maxAttempts' => 3],
            'config' => ['method' => 'GET', 'url' => 'https://example.test/status'],
        ], $overrides);
    }
}
