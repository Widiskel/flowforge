<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExecutionLog;
use App\Models\Tenant;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExecutionLog>
 */
class ExecutionLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'workflow_run_id' => WorkflowRun::factory(),
            'level' => 'info',
            'event' => 'step.started',
            'message' => fake()->sentence(),
            'context' => [],
            'created_at' => now(),
        ];
    }
}
