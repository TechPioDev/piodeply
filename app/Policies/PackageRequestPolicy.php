<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PackageRequest;
use App\Models\User;

/**
 * Filing a request is a tenant-only action (staff curate the catalogue
 * directly, via PackagePolicy — they have no reason to "request" from
 * themselves). Reviewing one — approving via the real package build, or
 * rejecting — is staff-only, mirroring how Signups are only ever decided by
 * staff, never the applicant's own side.
 */
class PackageRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PackagesView->value);
    }

    public function view(User $user, PackageRequest $request): bool
    {
        return $user->can(Permission::PackagesView->value)
            && ($user->tenantClientId() === null || $user->tenantClientId() === $request->client_id);
    }

    public function create(User $user): bool
    {
        return $user->tenantClientId() !== null && $user->can(Permission::PackagesView->value);
    }

    public function review(User $user, PackageRequest $request): bool
    {
        return $user->can(Permission::PackagesManage->value) && $user->tenantClientId() === null;
    }
}
