<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Package;
use App\Models\User;

/**
 * Two audiences, one catalogue: staff curate the shared catalogue and may
 * see everything; a tenant works with the catalogue plus packages private
 * to their own client — editing and deleting only their own. Staff may view
 * a client's private package (support needs eyes) but the deploy-side guard
 * in DeploymentService keeps even staff from ever using it for another
 * client.
 *
 * Creating a NEW package is staff-only: the MSP only ships tested software,
 * not whatever a client happens to type in (see PackageRequestPolicy for
 * the tenant-side "ask for it" flow this replaced). A tenant used to have
 * packages.manage and could add anything directly — that ability is
 * withdrawn here, not just hidden in the UI, since a policy check is the
 * only thing that actually closes a direct-URL route to packages.create.
 */
class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PackagesView->value);
    }

    public function view(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesView->value)
            && ($package->client_id === null
                || $user->tenantClientId() === null
                || $user->tenantClientId() === $package->client_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PackagesManage->value) && $user->tenantClientId() === null;
    }

    public function update(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value) && $this->owns($user, $package);
    }

    public function delete(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value) && $this->owns($user, $package);
    }

    public function restore(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value) && $this->owns($user, $package);
    }

    /**
     * Staff own the catalogue and, for support, may edit private packages
     * too. A tenant owns exactly their client's packages — never the
     * shared catalogue, never another tenant's.
     */
    private function owns(User $user, Package $package): bool
    {
        return $user->tenantClientId() === null
            || $user->tenantClientId() === $package->client_id;
    }
}
