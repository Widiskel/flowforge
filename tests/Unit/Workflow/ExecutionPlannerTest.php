<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Domain\Workflow\Services\ExecutionPlanner;
use Tests\TestCase;

class ExecutionPlannerTest extends TestCase
{
    public function test_groups_steps_into_dependency_safe_batches(): void
    {
        $planner = new ExecutionPlanner;

        $batches = $planner->planBatches([
            'steps' => [
                ['id' => 'fetch_customer', 'dependsOn' => []],
                ['id' => 'fetch_balance', 'dependsOn' => []],
                ['id' => 'decide', 'dependsOn' => ['fetch_customer', 'fetch_balance']],
                ['id' => 'notify', 'dependsOn' => ['decide']],
            ],
        ]);

        $this->assertSame([
            ['fetch_customer', 'fetch_balance'],
            ['decide'],
            ['notify'],
        ], $batches);
    }
}
