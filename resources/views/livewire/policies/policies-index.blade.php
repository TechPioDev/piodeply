<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ policy_terms() }}</h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('policies.templates') }}" class="text-sm pd-action">Templates</a>
                @can('create', \App\Models\SoftwarePolicy::class)
                    <a href="{{ route('policies.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-teal-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-500">
                        New {{ policy_term_lower() }}
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                {{ policy_terms() }} keep the fleet in a desired state — install, keep updated, pin or freeze versions,
                remove or block software. <strong>Enforce</strong> queues remediation jobs automatically as agents
                report in; <strong>Audit only</strong> reports compliance without changing machines.
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search package or {{ project_term_lower() }}…" aria-label="Search policies"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-72">
                <select wire:model.live="projectFilter" aria-label="Filter by {{ project_term_lower() }}"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All {{ project_terms_lower() }}</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">{{ policy_term() }}</th>
                            <th class="pd-th">{{ project_term() }}</th>
                            <th class="pd-th">Action</th>
                            <th class="pd-th">Priority</th>
                            <th class="pd-th">Compliance</th>
                            <th class="pd-th">Mode</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($policies as $policy)
                            @php $summary = $summaries[$policy->id] ?? null; @endphp
                            <tr @class(['opacity-60' => $policy->mode === \App\Enums\PolicyMode::Disabled])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('policies.show', $policy) }}" class="pd-link font-medium">{{ $policy->label() }}</a>
                                    <p class="text-xs text-slate-400">
                                        {{ $policy->package->vendor }}
                                        @if ($policy->hasWindow()) · {{ $policy->windowLabel() }} @endif
                                    </p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">
                                    @if ($policy->scope_type === 'project')
                                        {{ $policy->project?->name }}
                                        <p class="text-xs text-slate-400">{{ $policy->project?->client?->company_name }}</p>
                                    @else
                                        {{ $policy->scopeName() }}
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $actionBadge = match ($policy->action) {
                                            \App\Enums\PolicyAction::Install => 'bg-teal-50 text-teal-700 border-teal-200',
                                            \App\Enums\PolicyAction::Update => 'bg-blue-50 text-blue-700 border-blue-200',
                                            \App\Enums\PolicyAction::ForceUpdate => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                            \App\Enums\PolicyAction::Uninstall => 'bg-red-50 text-red-700 border-red-200',
                                            \App\Enums\PolicyAction::Block => 'bg-rose-50 text-rose-700 border-rose-200',
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $actionBadge }}">{{ $policy->action->label() }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $prioBadge = match ($policy->priorityLabel()) {
                                            'Critical' => 'bg-red-50 text-red-700 border-red-200',
                                            'High' => 'bg-amber-50 text-amber-700 border-amber-200',
                                            'Normal' => 'bg-slate-100 text-slate-600 border-slate-200',
                                            default => 'bg-slate-50 text-slate-400 border-slate-200',
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $prioBadge }}">{{ $policy->priorityLabel() }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if ($summary === null)
                                        <span class="text-xs text-slate-400">—</span>
                                    @elseif ($summary['target'] === 0)
                                        <span class="text-xs text-slate-400">No computers</span>
                                    @else
                                        @php
                                            $pct = $summary['percent'];
                                            $pctColor = $pct >= 90 ? 'text-green-600' : ($pct >= 60 ? 'text-amber-600' : 'text-red-600');
                                        @endphp
                                        <a href="{{ route('policies.show', $policy) }}" class="group">
                                            <span class="font-semibold {{ $pctColor }}">{{ $pct }}%</span>
                                            <span class="text-xs text-slate-500 group-hover:text-slate-700">
                                                {{ $summary['compliant'] }}/{{ $summary['target'] }} compliant
                                                @if ($summary['failed'] > 0) · <span class="text-red-600">{{ $summary['failed'] }} failed</span> @endif
                                                @if ($summary['pending'] > 0) · {{ $summary['pending'] }} pending @endif
                                                @if (($summary['scheduled'] ?? 0) > 0) · {{ $summary['scheduled'] }} scheduled @endif
                                            </span>
                                        </a>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $modeBadge = match ($policy->mode) {
                                            \App\Enums\PolicyMode::Enforce => 'bg-green-50 text-green-700 border-green-200',
                                            \App\Enums\PolicyMode::Audit => 'bg-blue-50 text-blue-700 border-blue-200',
                                            \App\Enums\PolicyMode::Disabled => 'bg-slate-100 text-slate-500 border-slate-200',
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $modeBadge }}">{{ $policy->mode->label() }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-1">
                                    @can('enforce', $policy)
                                        @if ($policy->mode === \App\Enums\PolicyMode::Enforce)
                                            <x-icon-button icon="play" label="Enforce now"
                                                           wire:click="enforceNow({{ $policy->id }})"
                                                           wire:loading.attr="disabled" />
                                        @endif
                                    @endcan
                                    @can('update', $policy)
                                        <x-icon-button icon="power" variant="amber"
                                                       label="{{ $policy->mode === \App\Enums\PolicyMode::Disabled ? 'Enable' : 'Disable' }}"
                                                       wire:click="toggle({{ $policy->id }})" />
                                        <x-icon-button icon="edit" label="Edit"
                                                       href="{{ route('policies.edit', $policy) }}" />
                                    @endcan
                                    @can('delete', $policy)
                                        <x-icon-button icon="delete" variant="danger" label="Delete"
                                                       wire:click="delete({{ $policy->id }})"
                                                       wire:confirm="Delete this policy? Queued jobs are unaffected." />
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">
                                No policies yet. Create one — e.g. “Auto Update Chrome”, “7-Zip 24.09 exactly” or
                                “Block AnyDesk” — and it applies to every machine in the {{ project_term_lower() }}.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $policies->links() }}
        </div>
    </div>
</div>
