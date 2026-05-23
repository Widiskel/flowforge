<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Services;

class ExecutionPlanner
{
    /**
     * @return array<int, array<int, string>>
     */
    public function planBatches(array $definition): array
    {
        $remaining = [];
        foreach ($definition['steps'] ?? [] as $step) {
            $remaining[$step['id']] = $step['dependsOn'] ?? [];
        }

        $completed = [];
        $batches = [];

        while ($remaining !== []) {
            $batch = [];

            foreach ($remaining as $id => $dependencies) {
                if (array_diff($dependencies, $completed) === []) {
                    $batch[] = $id;
                }
            }

            if ($batch === []) {
                break;
            }

            foreach ($batch as $id) {
                unset($remaining[$id]);
                $completed[] = $id;
            }

            $batches[] = $batch;
        }

        return $batches;
    }
}
