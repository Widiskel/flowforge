<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStepRun>
 */
class WorkflowStepRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'workflow_run_id' => WorkflowRun::factory(),
            'step_id' => fake()->uuid(),
            'step_type' => 'http',
            'status' => 'PENDING',
            'attempt_count' => 1,
            'output' => null,
            'error_message' => null,
        ];
    }
}
