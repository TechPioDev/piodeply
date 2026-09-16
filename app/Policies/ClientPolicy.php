<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ClientsView->value);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->can(Permission::ClientsView->value);
    }

    public function create(User $user): bool
    {
        // A customer's "Clients" page is their own organisation. Creating
        // clients is the platform operator's act, not a tenant's.
        return $user->can(Permission::ClientsCreate->value)
            && $user->tenantClientId() === null;
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can(Permission::ClientsUpdate->value);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->can(Permission::ClientsDelete->value);
    }

    public function restore(User $user, Client $client): bool
    {
        return $user->can(Permission::ClientsDelete->value);
    }
}
