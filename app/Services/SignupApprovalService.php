<?php

namespace App\Services;

use App\Enums\Role;
use App\Mail\AccountApprovedMail;
use App\Services\ClientSubscriptionService;
use App\Models\Client;
use App\Models\Signup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Turns a verified signup into a working account: a Client (the tenant),
 * an owner User bound to it, and the welcome email. One transaction — a
 * half-created account (client without login, login without tenant) is
 * worse than a failed approval that can simply be clicked again.
 */
class SignupApprovalService
{
    public function approve(Signup $signup, User $approver): User
    {
        if (! $signup->isOpen()) {
            throw new \DomainException('This signup has already been decided.');
        }

        if (User::where('email', $signup->email)->exists()) {
            throw new \DomainException("A user with {$signup->email} already exists — approve manually via Users.");
        }

        // Client emails are unique in the database. Without this check the
        // insert below throws a raw constraint violation and the whole
        // Signups page 500s, so the operator sees a server error instead of
        // being told what is actually wrong.
        //
        // withTrashed matters: the unique index counts deleted rows, so a
        // soft-deleted client still owns its address. Checking only live
        // clients let the guard pass and the insert fail anyway — deleting
        // the clashing client made the 500 come back.
        $existing = Client::withTrashed()->where('email', $signup->email)->first();

        if ($existing !== null && $existing->trashed()) {
            throw new \DomainException(
                "A deleted client ({$existing->company_name}) still holds {$signup->email}. "
                .'Deleting a client keeps its record and billing history, so the address stays taken. '
                .'Restore it and change its email, or have the applicant apply with a different address.'
            );
        }

        if ($existing !== null) {
            throw new \DomainException(
                "A client ({$existing->company_name}) already uses {$signup->email}. "
                .'Ask the applicant to sign up with a different address, or reject this application.'
            );
        }

        $owner = DB::transaction(function () use ($signup, $approver) {
            $client = Client::create([
                'company_name' => $signup->company_name,
                'email'        => $signup->email,
                'phone'        => $signup->phone,
                'country'      => $signup->country,
                'status'       => 'active',
            ]);

            $owner = new User([
                'name'  => $signup->contact_name,
                'email' => $signup->email,
            ]);
            // Hashed at signup; assigning via fill would double-hash it.
            $owner->forceFill([
                'password'          => $signup->password_hash,
                'client_id'         => $client->id,
                'email_verified_at' => now(), // payment + admin review vouch for the address
            ])->save();

            // Client Owner, client-bound: full management of their own
            // tenant (projects, computers, policies, their Team) and
            // nothing outside it — the exact scope the tenancy layer
            // already enforces and tests.
            $owner->assignRole(Role::ClientOwner->value);

            $signup->forceFill([
                'status'      => Signup::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'client_id'   => $client->id,
            ])->save();

            // The Stripe subscription created at checkout now belongs to a
            // real client — link them so renewal webhooks land somewhere.
            app(ClientSubscriptionService::class)->syncClientFromSignup($signup->fresh());

            activity('signups')
                ->causedBy($approver)
                ->performedOn($client)
                ->withProperties(['signup_id' => $signup->id, 'email' => $signup->email])
                ->log('signup_approved');

            return $owner;
        });

        // Outside the transaction: mail failure must not roll back the
        // account. queue() (not send()) is what actually hands this to the
        // worker to retry on its own -- a synchronous send() here means one
        // SMTP hiccup 500s the whole approval, even though the account was
        // already committed above, leaving the admin unsure it worked and
        // the new owner never told their account exists.
        Mail::to($signup->email)->queue(new AccountApprovedMail($signup));

        return $owner;
    }

    public function reject(Signup $signup, User $approver, string $reason): void
    {
        if (! $signup->isOpen()) {
            throw new \DomainException('This signup has already been decided.');
        }

        $signup->forceFill([
            'status'           => Signup::STATUS_REJECTED,
            'approved_by'      => $approver->id,
            'approved_at'      => now(),
            'rejection_reason' => trim($reason) !== '' ? trim($reason) : null,
        ])->save();

        activity('signups')
            ->causedBy($approver)
            ->withProperties(['signup_id' => $signup->id, 'email' => $signup->email])
            ->log('signup_rejected');
    }
}
