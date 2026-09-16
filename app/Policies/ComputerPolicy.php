<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Computer;
use App\Models\User;

class ComputerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ComputersView->value);
    }

    public function view(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersView->value)
            && ($user->tenantClientId() === null
                || $user->tenantClientId() === $computer->project->client_id)
            && $user->canAccessProject($computer->project_id);
    }

    public function update(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersManage->value);
    }

    public function delete(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersManage->value);
    }

    public function restore(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersManage->value);
    }

    /**
     * Permission only says WHO may permanently delete; whether this machine
     * MAY be (agent gone) is business state, enforced in
     * ComputerService::forceDelete so no caller can skip it.
     */
    public function forceDelete(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersManage->value);
    }
}
