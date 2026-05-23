<?php

declare(strict_types=1);

namespace App\Actions\Workflow;

use App\Models\Workflow;

class DeleteWorkflowAction
{
    public function execute(Workflow $workflow): void
    {
        $workflow->delete();
    }
}
