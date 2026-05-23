<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Exceptions\InvalidWorkflowDefinitionException;

class DagValidator
{
    private const ALLOWED_TYPES = ['HTTP', 'DELAY', 'CONDITION', 'SCRIPT'];

    private const MIN_GLOBAL_TIMEOUT_MS = 1000;

    private const MAX_GLOBAL_TIMEOUT_MS = 600000;

    private const MAX_RETRY_ATTEMPTS = 5;

    /**
     * @throws InvalidWorkflowDefinitionException
     */
    public function validate(array $definition): array
    {
        $this->assertSchemaVersion($definition);
        $this->assertGlobalTimeout($definition);

        $steps = $definition['steps'] ?? [];

        if (empty($steps)) {
            throw new InvalidWorkflowDefinitionException('Workflow must contain at least one step.');
        }

        $this->assertUniqueIds($steps);
        $this->assertStepShapes($steps);
        $this->assertDependenciesExist($steps);
        $this->assertNoCycles($steps);

        return $definition;
    }

    private function assertSchemaVersion(array $definition): void
    {
        if (($definition['schemaVersion'] ?? null) !== 1) {
            throw new InvalidWorkflowDefinitionException('Unsupported schemaVersion. Expected 1.');
        }
    }

    private function assertGlobalTimeout(array $definition): void
    {
        $timeout = $definition['globalTimeoutMs'] ?? null;

        if (! is_int($timeout) || $timeout < self::MIN_GLOBAL_TIMEOUT_MS || $timeout > self::MAX_GLOBAL_TIMEOUT_MS) {
            throw new InvalidWorkflowDefinitionException(sprintf(
                'globalTimeoutMs must be between %d and %d.',
                self::MIN_GLOBAL_TIMEOUT_MS,
                self::MAX_GLOBAL_TIMEOUT_MS,
            ));
        }
    }

    private function assertUniqueIds(array $steps): void
    {
        $seen = [];

        foreach ($steps as $step) {
            $id = $step['id'] ?? null;

            if (! is_string($id) || $id === '') {
                throw new InvalidWorkflowDefinitionException('Each step must declare a non-empty id.');
            }

            if (isset($seen[$id])) {
                throw new InvalidWorkflowDefinitionException(sprintf('Duplicate step id: %s', $id));
            }

            $seen[$id] = true;
        }
    }

    private function assertStepShapes(array $steps): void
    {
        foreach ($steps as $step) {
            $type = $step['type'] ?? null;

            if (! in_array($type, self::ALLOWED_TYPES, true)) {
                throw new InvalidWorkflowDefinitionException(sprintf('Unknown step type: %s', is_string($type) ? $type : 'null'));
            }

            $maxAttempts = $step['retry']['maxAttempts'] ?? 1;

            if (! is_int($maxAttempts) || $maxAttempts < 1 || $maxAttempts > self::MAX_RETRY_ATTEMPTS) {
                throw new InvalidWorkflowDefinitionException(sprintf(
                    'Step %s retry maxAttempts must be between 1 and %d.',
                    $step['id'],
                    self::MAX_RETRY_ATTEMPTS,
                ));
            }
        }
    }

    private function assertDependenciesExist(array $steps): void
    {
        $ids = array_column($steps, 'id');

        foreach ($steps as $step) {
            foreach (($step['dependsOn'] ?? []) as $dep) {
                if (! in_array($dep, $ids, true)) {
                    throw new InvalidWorkflowDefinitionException(sprintf(
                        'Step %s depends on unknown step %s.',
                        $step['id'],
                        $dep,
                    ));
                }
            }
        }
    }

    /**
     * DFS-based cycle detection over the dependency graph.
     */
    private function assertNoCycles(array $steps): void
    {
        $graph = [];
        foreach ($steps as $step) {
            $graph[$step['id']] = $step['dependsOn'] ?? [];
        }

        $WHITE = 0;
        $GRAY = 1;
        $BLACK = 2;
        $color = array_fill_keys(array_keys($graph), $WHITE);

        $visit = function (string $node) use (&$visit, &$color, $graph, $GRAY, $BLACK): void {
            if ($color[$node] === $GRAY) {
                throw new InvalidWorkflowDefinitionException('Workflow contains a cycle.');
            }

            if ($color[$node] === $BLACK) {
                return;
            }

            $color[$node] = $GRAY;
            foreach ($graph[$node] as $next) {
                $visit($next);
            }
            $color[$node] = $BLACK;
        };

        foreach (array_keys($graph) as $node) {
            $visit($node);
        }
    }
}
