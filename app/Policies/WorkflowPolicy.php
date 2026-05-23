<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workflow;

class WorkflowPolicy
{
    /**
     * Tenant boundary: cross-tenant access returns null (→ 404 via controller).
     */
    private function ownsTenant(User $user, Workflow $workflow): bool
    {
        return $user->tenant_id === $workflow->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workflow $workflow): bool
    {
        return $this->ownsTenant($user, $workflow);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'editor');
    }

    public function update(User $user, Workflow $workflow): bool
    {
        return $this->ownsTenant($user, $workflow) && $user->hasRole('admin', 'editor');
    }

    public function delete(User $user, Workflow $workflow): bool
    {
        return $this->ownsTenant($user, $workflow) && $user->hasRole('admin');
    }

    public function rollback(User $user, Workflow $workflow): bool
    {
        return $this->ownsTenant($user, $workflow) && $user->hasRole('admin', 'editor');
    }

    public function trigger(User $user, Workflow $workflow): bool
    {
        return $this->ownsTenant($user, $workflow) && $user->hasRole('admin', 'editor');
    }
}
