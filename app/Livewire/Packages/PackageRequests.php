<?php

namespace App\Livewire\Packages;

use App\Livewire\Concerns\WithCompactPagination;
use App\Models\PackageRequest;
use App\Services\PackageRequestService;
use Livewire\Component;

/**
 * One page, two audiences (the same split ComputersIndex/Dashboard use): a
 * tenant files requests and watches their own list decide; staff see every
 * client's queue and act on it. "Acting" on an approval means jumping into
 * the real package-create form (PackageForm::fulfillsRequestId) — this
 * component itself never writes to the catalogue.
 */
class PackageRequests extends Component
{
    use WithCompactPagination;

    public string $name = '';

    public ?string $vendor = null;

    public ?string $homepage = null;

    public ?string $notes = null;

    /** Staff-side filter; a tenant's own list is short enough to show whole. */
    public bool $openOnly = true;

    public ?int $rejectingId = null;

    public string $rejectionReason = '';

    public function updating($name, $value): void
    {
        if (in_array($name, ['openOnly'], true)) {
            $this->resetPage();
        }
    }

    public function submit(PackageRequestService $service): void
    {
        $this->authorize('create', PackageRequest::class);

        $validated = $this->validate([
            'name'     => ['required', 'string', 'max:255'],
            'vendor'   => ['nullable', 'string', 'max:255'],
            'homepage' => ['nullable', 'url', 'max:255'],
            'notes'    => ['nullable', 'string', 'max:1000'],
        ]);

        $service->request(auth()->user(), $validated);

        $this->reset('name', 'vendor', 'homepage', 'notes');
        session()->flash('status', 'Request sent — we\'ll let you know once it\'s reviewed.');
    }

    public function startReject(int $requestId): void
    {
        $request = PackageRequest::findOrFail($requestId);
        $this->authorize('review', $request);

        $this->rejectingId = $requestId;
        $this->rejectionReason = '';
    }

    public function confirmReject(PackageRequestService $service): void
    {
        $request = PackageRequest::findOrFail($this->rejectingId);
        $this->authorize('review', $request);

        try {
            $service->reject($request, auth()->user(), $this->rejectionReason);
            session()->flash('status', "Request for \"{$request->name}\" rejected — the requester has been notified.");
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->rejectingId = null;
        $this->rejectionReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectionReason = '';
    }

    public function render()
    {
        $this->authorize('viewAny', PackageRequest::class);

        $tenantId = auth()->user()->tenantClientId();
        $isTenant = $tenantId !== null;

        $requests = PackageRequest::query()
            ->with(['client', 'requester', 'decider', 'createdPackage'])
            ->visibleTo(auth()->user())
            ->when(! $isTenant && $this->openOnly, fn ($q) => $q->where('status', PackageRequest::STATUS_PENDING))
            ->latest()
            ->paginate(15);

        return view('livewire.packages.package-requests', [
            'requests'  => $requests,
            'isTenant'  => $isTenant,
            'openCount' => PackageRequest::query()
                ->where('status', PackageRequest::STATUS_PENDING)
                ->when($isTenant, fn ($q) => $q->where('client_id', $tenantId))
                ->count(),
        ])->layout('layouts.app');
    }
}
