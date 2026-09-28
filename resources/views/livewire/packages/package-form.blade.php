<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ $package ? 'Edit ' . $package->name : 'New Package' }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            @if ($fulfillingRequest)
                <div class="pd-card p-3 mb-4 text-sm text-teal-800 !bg-teal-50 border-teal-200">
                    Building this for <strong>{{ $fulfillingRequest->client->company_name }}</strong>'s request
                    @if ($fulfillingRequest->notes)
                        — "{{ $fulfillingRequest->notes }}"
                    @endif
                    . Saving will mark the request fulfilled and notify {{ $fulfillingRequest->requester->name }}.
                </div>
            @endif
            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-label for="name" value="Name" />
                        <x-input id="name" type="text" class="mt-1 block w-full" wire:model="name" required />
                        <x-input-error for="name" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="package_category_id" value="Category" />
                        <select id="package_category_id" wire:model="package_category_id"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <option value="">— select —</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="package_category_id" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="vendor" value="Vendor" />
                        <x-input id="vendor" type="text" class="mt-1 block w-full" wire:model="vendor" />
                    </div>
                    <div>
                        <x-label for="license" value="License" />
                        <x-input id="license" type="text" class="mt-1 block w-full" wire:model="license" placeholder="e.g. GPL-3.0, Commercial" />
                    </div>
                    <div class="md:col-span-2">
                        <x-label for="homepage" value="Homepage" />
                        <x-input id="homepage" type="url" class="mt-1 block w-full" wire:model="homepage" placeholder="https://…" />
                        <x-input-error for="homepage" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="installer_type" value="Installer type" />
                        <select id="installer_type" wire:model.live="installer_type"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-label for="architecture" value="Architecture" />
                        <select id="architecture" wire:model="architecture"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            @foreach ($architectures as $arch)
                                <option value="{{ $arch->value }}">{{ $arch->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($installer_type === 'winget')
                        <div class="md:col-span-2">
                            <x-label for="winget_id" value="winget ID" />
                            <x-input id="winget_id" type="text" class="mt-1 block w-full font-mono" wire:model="winget_id" placeholder="e.g. Google.Chrome" />
                            <x-input-error for="winget_id" class="mt-1" />
                            <label class="mt-3 flex items-start gap-2 text-sm text-slate-600">
                                <x-checkbox wire:model="winget_scopeless" class="mt-0.5" />
                                <span>
                                    Install without <code class="font-mono text-xs">--scope machine</code>
                                    — for installers that are machine-wide but whose winget manifest
                                    declares no scope (the .NET runtimes are the classic case; forcing
                                    machine scope fails with "no applicable installer"). Leave off for
                                    normal packages: it protects against per-user installers vanishing
                                    into the SYSTEM profile.
                                </span>
                            </label>
                        </div>
                    @elseif ($installer_type === 'choco')
                        <div class="md:col-span-2">
                            <x-label for="choco_id" value="Chocolatey ID" />
                            <x-input id="choco_id" type="text" class="mt-1 block w-full font-mono" wire:model="choco_id" placeholder="e.g. googlechrome" />
                            <x-input-error for="choco_id" class="mt-1" />
                        </div>
                    @else
                        <div class="md:col-span-2 rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                            Binary installer — add versions with a download URL and SHA-256 on the package page after saving.
                        </div>
                    @endif

                    <div class="md:col-span-2">
                        <x-label for="management_mode" value="Management" />
                        <select id="management_mode" wire:model.live="management_mode"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            @foreach (\App\Enums\PackageMode::cases() as $mode)
                                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="management_mode" class="mt-1" />
                        {{-- tryFrom, not from(): this is wire:model.live, so an
                             invalid value (a tampered request, not just a bad
                             click) reaches this render before "required in:"
                             validation ever runs on submit. from() would crash
                             the page instead of letting the error render. --}}
                        @if ($management_mode !== 'deploy' && ($mode = \App\Enums\PackageMode::tryFrom($management_mode)))
                            <p class="mt-1.5 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-2">
                                {{ $mode->clientExplanation() }}
                                Nobody can queue a deployment for this package while it is set this way.
                            </p>
                        @elseif ($management_mode === 'deploy')
                            <p class="mt-1 text-xs text-slate-500">
                                <strong>Deploy</strong> installs and updates it, as normal. Change this only for
                                software this platform genuinely cannot install machine-wide — Windows-managed
                                apps (Edge) or Store/MSIX packages (Teams) — so it stays visible and explained
                                rather than quietly deactivated.
                            </p>
                        @endif
                    </div>
                </div>

                <div>
                    <x-label for="description" value="Description" />
                    <textarea id="description" rows="3" wire:model="description"
                              class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm"></textarea>
                </div>

                <div class="flex justify-end gap-3 border-t pt-4">
                    <a href="{{ route('packages.index') }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Cancel
                    </a>
                    <x-button>{{ $package ? 'Save changes' : 'Create package' }}</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
