<?php

namespace App\Livewire\Packages;

use App\Enums\Architecture;
use App\Enums\InstallerType;
use App\Enums\PackageMode;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\PackageRequest;
use App\Services\PackageRequestService;
use App\Services\PackageService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class PackageForm extends Component
{
    public ?Package $package = null;

    public ?int $package_category_id = null;
    public string $name = '';
    public ?string $vendor = null;
    public ?string $homepage = null;
    public ?string $description = null;
    public ?string $license = null;
    public string $installer_type = 'winget';
    public string $architecture = 'x64';
    public ?string $winget_id = null;
    public bool $winget_scopeless = false;
    public ?string $choco_id = null;

    /**
     * Set only when staff arrived here via "Build this package" on a
     * pending PackageRequest — pre-fills the name/vendor/homepage a client
     * already gave us, and links the request to whatever package this save
     * produces (PackageRequestService::linkFulfilledPackage).
     */
    #[Url]
    public ?int $fulfillsRequestId = null;

    public ?PackageRequest $fulfillingRequest = null;

    /**
     * How the software is actually managed — separate from is_active, which
     * only ever meant "removed from the catalogue". A package can be real,
     * active, and still not something this platform installs (Edge, Teams).
     */
    public string $management_mode = 'deploy';

    public function mount(?Package $package = null): void
    {
        if ($package !== null && $package->exists) {
            $this->authorize('update', $package);
            $this->package = $package;
            $this->fill($package->only([
                'package_category_id', 'name', 'vendor', 'homepage',
                'description', 'license', 'winget_id', 'winget_scopeless', 'choco_id',
            ]));
            $this->installer_type = $package->installer_type->value;
            $this->architecture = $package->architecture->value;
            $this->management_mode = $package->management_mode->value;

            return;
        }

        $this->authorize('create', Package::class);

        if ($this->fulfillsRequestId !== null) {
            $request = PackageRequest::find($this->fulfillsRequestId);
            // A stale/decided link (already fulfilled, or rejected since the
            // list rendered) is dropped quietly rather than blocking the
            // page — staff can still build a package with nothing linked.
            if ($request !== null && $request->isOpen() && auth()->user()->can('review', $request)) {
                $this->fulfillingRequest = $request;
                $this->name = $request->name;
                $this->vendor = $request->vendor;
                $this->homepage = $request->homepage;
            } else {
                $this->fulfillsRequestId = null;
            }
        }
    }

    public function save(PackageService $service)
    {
        $this->authorize($this->package ? 'update' : 'create', $this->package ?? Package::class);

        $idRule = 'regex:' . Package::ID_PATTERN;

        $validated = $this->validate([
            'package_category_id' => ['required', 'integer', Rule::exists('package_categories', 'id')],
            'name'                => ['required', 'string', 'max:255'],
            'vendor'              => ['nullable', 'string', 'max:255'],
            'homepage'            => ['nullable', 'url', 'max:255'],
            'description'         => ['nullable', 'string', 'max:2000'],
            'license'             => ['nullable', 'string', 'max:100'],
            'installer_type'      => ['required', Rule::in(InstallerType::values())],
            'architecture'        => ['required', Rule::in(Architecture::values())],
            // A second package sharing an id is not a hypothetical: one was
            // found live, silently cloned by a code path that has since
            // been fixed. This closes the other way to create one — typing
            // in an id the catalogue already has, deleted rows excepted.
            'winget_id'           => ['nullable', 'string', 'max:255', $idRule,
                Rule::requiredIf($this->installer_type === 'winget'),
                Rule::unique('packages', 'winget_id')->whereNull('deleted_at')
                    ->when($this->package, fn ($rule) => $rule->ignore($this->package->id))],
            'winget_scopeless'    => ['boolean'],
            'choco_id'            => ['nullable', 'string', 'max:255', $idRule,
                Rule::requiredIf($this->installer_type === 'choco'),
                Rule::unique('packages', 'choco_id')->whereNull('deleted_at')
                    ->when($this->package, fn ($rule) => $rule->ignore($this->package->id))],
            'management_mode'    => ['required', Rule::in(PackageMode::values())],
        ], [
            'winget_id.regex'       => 'winget IDs may only contain letters, digits, ".", "-", "+" and "_".',
            'choco_id.regex'        => 'Chocolatey IDs may only contain letters, digits, ".", "-", "+" and "_".',
            'winget_id.required'    => 'winget packages need a winget ID.',
            'choco_id.required'     => 'Chocolatey packages need a Chocolatey ID.',
            'winget_id.unique'      => 'Another package already uses this winget ID.',
            'choco_id.unique'       => 'Another package already uses this Chocolatey ID.',
        ]);

        if ($this->package) {
            $service->update($this->package, $validated);
            session()->flash('status', 'Package saved.');

            return $this->redirectRoute('packages.show', $this->package);
        }

        // Package creation is staff-only (see PackagePolicy::create) — a
        // tenant can no longer reach this branch at all, so unlike before
        // there is no tenant-private client_id to force here. A package
        // built to fulfil a request still defaults to the shared catalogue,
        // same as any other staff-created package: most requested software
        // (Zoom, a PDF reader...) is just as useful to every other client,
        // and nothing here stops staff editing client_id later for the rare
        // genuinely client-specific case.
        $package = $service->create($validated);

        if ($this->fulfillingRequest !== null) {
            app(PackageRequestService::class)->linkFulfilledPackage($this->fulfillingRequest, $package, auth()->user());
            session()->flash('status', "Package created and \"{$this->fulfillingRequest->name}\" marked fulfilled — the requester has been notified.");
        } else {
            session()->flash('status', 'Package created. Add a version below if it ships as a binary installer.');
        }

        return $this->redirectRoute('packages.show', $package);
    }

    public function render()
    {
        return view('livewire.packages.package-form', [
            'categories'    => PackageCategory::orderBy('sort_order')->get(['id', 'name']),
            'types'         => InstallerType::cases(),
            'architectures' => Architecture::cases(),
        ])->layout('layouts.app');
    }
}
