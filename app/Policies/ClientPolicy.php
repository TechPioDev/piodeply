<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    /**
     * Tenancy belongs on every ability, not just the read ones -- a
     * permission answers "may this user update a client?", never "which
     * one?". A tenant-bound user (their own Manager/ClientOwner included,
     * both of whom carry clients.update for their own "organisation" page)
     * may only ever act on their own client. Unbound staff are unrestricted.
     */
    private function withinTenant(User $user, Client $client): bool
    {
        return $user->tenantClientId() === null
            || $user->tenantClientId() === $client->id;
    }

    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ClientsView->value);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->can(Permission::ClientsView->value)
            && $this->withinTenant($user, $client);
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
        return $user->can(Permission::ClientsUpdate->value)
            && $this->withinTenant($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->can(Permission::ClientsDelete->value)
            && $this->withinTenant($user, $client);
    }

    public function restore(User $user, Client $client): bool
    {
        return $user->can(Permission::ClientsDelete->value)
            && $this->withinTenant($user, $client);
    }
}
