<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-slate-900 leading-tight">{{ __('Packages') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">Approved software repository — {{ $packages->total() }} packages</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('packages.requests') }}"
                   class="relative inline-flex items-center gap-2 px-3 py-2 bg-white border border-slate-200 rounded-lg font-semibold text-sm text-slate-600 shadow-sm hover:border-teal-300 hover:text-teal-700 transition">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                    Package requests
                    @if ($openPackageRequests > 0)
                        <span class="pd-badge pd-badge-amber">{{ $openPackageRequests }}</span>
                    @endif
                </a>
                @can('create', \App\Models\Package::class)
                    <a href="{{ route('packages.create') }}"
                       class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 rounded-lg font-semibold text-sm text-white shadow-sm hover:bg-teal-800 transition">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        New Package
                    </a>
                @endcan
                @can('create', \App\Models\PackageRequest::class)
                    <a href="{{ route('packages.requests') }}"
                       class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 rounded-lg font-semibold text-sm text-white shadow-sm hover:bg-teal-800 transition">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Request a package
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="pd-card p-3 text-sm text-emerald-700 !bg-emerald-50 border-emerald-200" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @php
                $packageCards = [
                    ['label' => 'Total packages', 'value' => $stats['total'], 'sub' => 'In the catalogue', 'tone' => 'teal', 'filter' => null,
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375"/>'],
                    ['label' => 'Deployable', 'value' => $stats['deployable'], 'sub' => 'A click actually installs it', 'tone' => 'green', 'filter' => 'deployable',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                    ['label' => 'Active but blocked', 'value' => $stats['blocked'], 'sub' => 'OS-managed, Store, or unsupported', 'tone' => $stats['blocked'] > 0 ? 'amber' : 'slate', 'filter' => 'blocked',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 17h.01"/>'],
                    ['label' => 'Inactive', 'value' => $stats['inactive'], 'sub' => 'Removed from the catalogue', 'tone' => 'slate', 'filter' => 'inactive',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>'],
                ];
                $packageTones = [
                    'teal'  => 'bg-teal-50 text-teal-700 border-teal-100',
                    'green' => 'bg-green-50 text-green-700 border-green-100',
                    'amber' => 'bg-amber-50 text-amber-700 border-amber-100',
                    'slate' => 'bg-slate-100 text-slate-600 border-slate-200',
                ];
            @endphp
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ($packageCards as $card)
                    @php $tag = $card['filter'] === null ? 'div' : 'button'; @endphp
                    <{{ $tag }}
                        @if ($card['filter'] !== null)
                            type="button" wire:click="$set('managementStatus', '{{ $managementStatus === $card['filter'] ? '' : $card['filter'] }}')"
                        @endif
                        @class([
                            'pd-card p-4 block text-left w-full',
                            'hover:border-teal-200 transition-colors cursor-pointer' => $card['filter'] !== null,
                            'ring-2 ring-teal-500' => $card['filter'] !== null && $managementStatus === $card['filter'],
                        ])>
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $packageTones[$card['tone']] }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $card['icon'] !!}</svg>
                            </span>
                            <p class="text-sm font-semibold text-slate-700 leading-tight">{{ $card['label'] }}</p>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($card['value']) }}</p>
                        <p class="text-xs text-slate-400">{{ $card['sub'] }}</p>
                    </{{ $tag }}>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Search name, vendor, package id…" aria-label="Search packages"
                           class="pd-input pl-9 w-80">
                </div>
                <select wire:model.live="categoryId" aria-label="Filter by category" class="pd-select">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="installerType" aria-label="Filter by installer type" class="pd-select">
                    <option value="">All types</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="activeOnly" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Active only
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="showTrashed" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Show deleted
                </label>
                @if ($managementStatus !== '')
                    <button type="button" wire:click="$set('managementStatus', '')" class="text-xs pd-action">Clear status filter</button>
                @endif
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead>
                        <tr>
                            <th class="pd-th">Package</th>
                            <th class="pd-th">Category</th>
                            <th class="pd-th">Type</th>
                            <th class="pd-th">Latest version</th>
                            <th class="pd-th">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($packages as $package)
                            <tr @class(['opacity-50' => $package->trashed()])>
                                <td class="px-6 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <span class="pd-tile">
                                            <x-category-icon :name="$package->category->name" />
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <a href="{{ route('packages.show', $package) }}" class="pd-link text-[15px]">{{ $package->name }}</a>
                                                @if ($package->isPrivate())
                                                    <span class="ml-1 pd-badge pd-badge-amber"
                                                          title="Private package — only {{ auth()->user()->tenantClientId() === null ? $package->client?->company_name : 'your organisation' }} can deploy it">
                                                        {{ auth()->user()->tenantClientId() === null ? 'Private · '.$package->client?->company_name : 'Private' }}
                                                    </span>
                                                @endif
                                                @if ($package->trashed())
                                                    <span class="pd-badge pd-badge-red">deleted</span>
                                                @endif
                                            </div>
                                            <p class="text-xs text-slate-400 truncate">
                                                {{ $package->vendor }}
                                                @if ($package->winget_id)
                                                    <span class="text-slate-300">·</span>
                                                    <code class="font-mono text-slate-500">{{ $package->winget_id }}</code>
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-sm text-slate-600">{{ $package->category->name }}</td>
                                <td class="px-6 py-3.5 whitespace-nowrap">
                                    <span class="pd-badge pd-badge-sky">{{ $package->installer_type->label() }}</span>
                                    <span class="ml-1 text-[11px] font-mono text-slate-400">{{ $package->architecture->value }}</span>
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-sm">
                                    @if ($package->installer_type->requiresBinary())
                                        <span class="text-slate-700 font-medium">{{ $package->latestVersion?->version ?? '—' }}</span>
                                        <span class="text-xs text-slate-400">({{ $package->versions_count }})</span>
                                    @else
                                        <span class="text-xs text-slate-400 italic">auto (winget)</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap">
                                    @if (! $package->is_active)
                                        <span class="pd-badge pd-badge-slate"><span class="pd-dot"></span>inactive</span>
                                    @elseif ($package->isDeployable())
                                        <span class="pd-badge pd-badge-green"><span class="pd-dot"></span>active</span>
                                    @else
                                        {{-- Active is true, but "a click here queues a job" is false —
                                             the exact gap PackageMode exists to stop hiding (see its
                                             own docblock). Never show plain "active" for these. --}}
                                        <span class="pd-badge pd-badge-amber" title="{{ $package->management_mode->clientExplanation() }}">
                                            <span class="pd-dot"></span>{{ $package->management_mode->label() }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-right space-x-1">
                                    @if ($package->trashed())
                                        @can('restore', $package)
                                            <x-icon-button icon="restore" label="Restore" wire:click="restore({{ $package->id }})" />
                                        @endcan
                                    @else
                                        @can('update', $package)
                                            <x-icon-button icon="power" variant="amber"
                                                           :label="$package->is_active ? 'Deactivate' : 'Activate'"
                                                           wire:click="toggleActive({{ $package->id }})" />
                                            <x-icon-button icon="edit" label="Edit" :href="route('packages.edit', $package)" />
                                        @endcan
                                        @can('delete', $package)
                                            <x-icon-button icon="delete" variant="danger" label="Delete"
                                                           wire:click="delete({{ $package->id }})"
                                                           wire:confirm="Delete package “{{ $package->name }}”? It can be restored later." />
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-12 text-center text-slate-400">No packages found.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $packages->links() }}
        </div>
    </div>
</div>
