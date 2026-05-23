<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'workflow_id' => Workflow::factory(),
            'workflow_version_id' => WorkflowVersion::factory(),
            'created_by' => User::factory(),
            'status' => 'PENDING',
            'input' => [],
            'timeout_ms' => 30000,
        ];
    }
}
