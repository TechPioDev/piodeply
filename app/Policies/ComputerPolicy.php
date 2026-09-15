<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Computer;
use App\Models\User;

class ComputerPolicy
{
    /**
     * Tenancy belongs on every ability, not just view() -- update/delete/
     * restore/forceDelete previously checked only the permission string,
     * so a client-bound user (or a project-confined technician) holding
     * computers.manage could act on another tenant's machine by calling a
     * mutating action directly with its id, bypassing the tenant-scoped
     * list they'd normally click through.
     */
    private function withinTenant(User $user, Computer $computer): bool
    {
        return ($user->tenantClientId() === null
                || $user->tenantClientId() === $computer->project->client_id)
            && $user->canAccessProject($computer->project_id);
    }

    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ComputersView->value);
    }

    public function view(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersView->value)
            && $this->withinTenant($user, $computer);
    }

    public function update(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersManage->value)
            && $this->withinTenant($user, $computer);
    }

    public function delete(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersManage->value)
            && $this->withinTenant($user, $computer);
    }

    public function restore(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersManage->value)
            && $this->withinTenant($user, $computer);
    }

    /**
     * Permission only says WHO may permanently delete; whether this machine
     * MAY be (agent gone) is business state, enforced in
     * ComputerService::forceDelete so no caller can skip it.
     */
    public function forceDelete(User $user, Computer $computer): bool
    {
        return $user->can(Permission::ComputersManage->value)
            && $this->withinTenant($user, $computer);
    }
}
