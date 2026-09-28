<?php

namespace App\Livewire\Packages;

use App\Models\Package;
use App\Models\PackageRequest;
use App\Repositories\Contracts\PackageRepositoryInterface;
use App\Services\PackageService;
use Livewire\Component;
use App\Livewire\Concerns\WithCompactPagination;

class PackagesIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public ?int $categoryId = null;

    public string $installerType = '';

    public bool $activeOnly = false;

    public bool $showTrashed = false;

    public string $managementStatus = ''; // '', 'deployable', 'blocked', 'inactive'

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'categoryId', 'installerType', 'activeOnly', 'showTrashed', 'managementStatus'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Scoped the same as the list itself (tenancy, private packages), so
     * the cards can never claim a bigger or smaller catalogue than what a
     * click on them actually filters down to. Package::visibleTo() is the
     * canonical definition of that (already relied on by the show-page
     * guard and the tenancy tests) — reused here rather than a second
     * hand-rolled copy of the same whereNull/orWhere condition.
     */
    private function stats(): array
    {
        $visible = Package::visibleTo(auth()->user());

        return [
            'total'      => (clone $visible)->count(),
            'deployable' => (clone $visible)->managementStatus('deployable')->count(),
            'blocked'    => (clone $visible)->managementStatus('blocked')->count(),
            'inactive'   => (clone $visible)->managementStatus('inactive')->count(),
        ];
    }

    public function toggleActive(int $packageId, PackageService $service): void
    {
        $package = Package::findOrFail($packageId);
        $this->authorize('update', $package);

        $service->setActive($package, ! $package->is_active);
    }

    public function delete(int $packageId, PackageService $service): void
    {
        $package = Package::findOrFail($packageId);
        $this->authorize('delete', $package);

        $service->delete($package);
    }

    public function restore(int $packageId, PackageService $service): void
    {
        $package = Package::withTrashed()->findOrFail($packageId);
        $this->authorize('restore', $package);

        $service->restore($package);
    }

    public function render(PackageRepositoryInterface $packages)
    {
        $this->authorize('viewAny', Package::class);

        return view('livewire.packages.packages-index', [
            'packages'   => $packages->searchPaginated(
                search: $this->search,
                categoryId: $this->categoryId,
                installerType: $this->installerType ?: null,
                activeOnly: $this->activeOnly ?: null,
                withTrashed: $this->showTrashed,
                visibleToClientId: auth()->user()->tenantClientId(),
                managementStatus: $this->managementStatus,
            ),
            'stats'      => $this->stats(),
            'categories' => \App\Models\PackageCategory::orderBy('sort_order')->get(['id', 'name']),
            'types'      => \App\Enums\InstallerType::cases(),
            // Surfaced next to the create/request button so both audiences
            // can find the requests queue: staff see everyone's pending
            // count, a tenant sees their own.
            'openPackageRequests' => PackageRequest::query()
                ->where('status', PackageRequest::STATUS_PENDING)
                ->visibleTo(auth()->user())
                ->count(),
        ])->layout('layouts.app');
    }
}
