<?php

namespace App\Services;

use App\Mail\PackageRequestDecidedMail;
use App\Models\Package;
use App\Models\PackageRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * The whole "client asks, staff decides" loop for one request: filing it
 * (with a staff notification), rejecting it, and linking it to the real
 * Package staff built once they approved it. Never mutates the catalogue
 * itself — that stays PackageForm/PackageService's job, on purpose, since a
 * package needs technical fields (winget id, installer type...) no client
 * request can supply.
 */
class PackageRequestService
{
    public function request(User $requester, array $attributes): PackageRequest
    {
        $tenantId = $requester->tenantClientId();

        if ($tenantId === null) {
            throw new \DomainException('Only a client-side user can request a package.');
        }

        $request = PackageRequest::create($attributes + [
            'client_id'    => $tenantId,
            'requested_by' => $requester->id,
            'status'       => PackageRequest::STATUS_PENDING,
        ]);

        activity('packages')
            ->causedBy($requester)
            ->performedOn($request)
            ->withProperties(['name' => $request->name, 'client_id' => $tenantId])
            ->log('package_requested');

        app(NotificationService::class)->notify(
            'package.requested',
            "Package requested: {$request->name} ({$requester->client->company_name})",
            [
                'requester' => $requester->name,
                'client'    => $requester->client->company_name,
                'package'   => $request->name,
                'vendor'    => $request->vendor ?? '—',
            ]
        );

        return $request;
    }

    public function reject(PackageRequest $request, User $decider, string $reason): void
    {
        if (! $request->isOpen()) {
            throw new \DomainException('This request has already been decided.');
        }

        $request->forceFill([
            'status'        => PackageRequest::STATUS_REJECTED,
            'decided_by'    => $decider->id,
            'decided_at'    => now(),
            'decision_note' => trim($reason) !== '' ? trim($reason) : null,
        ])->save();

        activity('packages')
            ->causedBy($decider)
            ->performedOn($request)
            ->withProperties(['status' => 'rejected'])
            ->log('package_request_rejected');

        Mail::to($request->requester->email)->queue(new PackageRequestDecidedMail($request));
    }

    /**
     * Called once staff has actually built the Package from the request
     * (via PackageForm's fulfillsRequestId flow) — never before, since
     * "approved" without a real package would leave the requester with
     * nothing to deploy.
     */
    public function linkFulfilledPackage(PackageRequest $request, Package $package, User $decider): void
    {
        if (! $request->isOpen()) {
            throw new \DomainException('This request has already been decided.');
        }

        $request->forceFill([
            'status'              => PackageRequest::STATUS_APPROVED,
            'decided_by'          => $decider->id,
            'decided_at'          => now(),
            'created_package_id'  => $package->id,
        ])->save();

        activity('packages')
            ->causedBy($decider)
            ->performedOn($request)
            ->withProperties(['status' => 'approved', 'package_id' => $package->id])
            ->log('package_request_approved');

        Mail::to($request->requester->email)->queue(new PackageRequestDecidedMail($request));
    }
}
