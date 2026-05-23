<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowVersion>
 */
class WorkflowVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'workflow_id' => Workflow::factory(),
            'version_number' => 1,
            'definition' => ['steps' => []],
            'source' => 'manual_update',
            'created_by' => User::factory(),
        ];
    }

    public function version1(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'version_number' => 1,
        ]);
    }

    public function version2(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'version_number' => 2,
        ]);
    }
}
