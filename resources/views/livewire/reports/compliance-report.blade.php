<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">Policy compliance report</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @can(\App\Enums\Permission::ReportsExport->value)
                <div class="flex justify-end">
                    <x-secondary-button type="button" wire:click="export">Export CSV</x-secondary-button>
                </div>
            @endcan
            <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Policies</p>
                    <p class="text-2xl font-bold text-slate-800">{{ $overall['policies'] }}</p></div>
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Machine targets</p>
                    <p class="text-2xl font-bold text-slate-800">{{ $overall['target'] }}</p></div>
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Compliant</p>
                    <p class="text-2xl font-bold text-green-600">{{ $overall['compliant'] }}</p></div>
                <div class="pd-card p-4" title="The policy's own condition is genuinely met — this only means the machine's package manager has since reported something newer.">
                    <p class="text-xs uppercase tracking-wider text-slate-400">Compliant but outdated</p>
                    <p class="text-2xl font-bold {{ $overall['compliant_outdated'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $overall['compliant_outdated'] }}</p>
                </div>
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Failed</p>
                    <p class="text-2xl font-bold text-red-600">{{ $overall['failed'] }}</p></div>
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Overall</p>
                    @php $pct = $overall['percent']; @endphp
                    <p class="text-2xl font-bold {{ $pct === null ? 'text-slate-400' : ($pct >= 90 ? 'text-green-600' : ($pct >= 60 ? 'text-amber-600' : 'text-red-600')) }}">
                        {{ $pct === null ? '—' : $pct . '%' }}
                    </p></div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
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
                            <th class="pd-th">Policy</th>
                            <th class="pd-th">Client / {{ project_term() }}</th>
                            <th class="pd-th">Mode</th>
                            <th class="pd-th text-right">Target</th>
                            <th class="pd-th text-right">Compliant</th>
                            <th class="pd-th text-right">Pending</th>
                            <th class="pd-th text-right">Scheduled</th>
                            <th class="pd-th text-right">Failed</th>
                            <th class="pd-th text-right">Drift</th>
                            <th class="pd-th text-right">%</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($rows as $row)
                            @php $summary = $row['summary']; $policy = $row['policy']; @endphp
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('policies.show', $policy) }}" class="pd-link">{{ $policy->label() }}</a>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">
                                    @if ($policy->scope_type === 'project')
                                        {{ $policy->project?->client?->company_name }} / {{ $policy->project?->name }}
                                    @else
                                        {{ $policy->scopeName() }}
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">{{ $policy->mode->label() }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-slate-700">{{ $summary['target'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-green-600">
                                    {{ $summary['compliant'] }}
                                    @if ($summary['compliant_outdated'] > 0)
                                        <span class="ml-1 text-xs font-normal text-amber-600" title="Policy condition met, but a newer version has been reported">({{ $summary['compliant_outdated'] }} outdated)</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-blue-600">{{ $summary['pending'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-violet-600">{{ $summary['scheduled'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right {{ $summary['failed'] > 0 ? 'text-red-600 font-semibold' : 'text-slate-400' }}">{{ $summary['failed'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right {{ $summary['non_compliant'] > 0 ? 'text-amber-600' : 'text-slate-400' }}">{{ $summary['non_compliant'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right font-semibold">
                                    @php $rowPct = $summary['percent']; @endphp
                                    <span class="{{ $rowPct === null ? 'text-slate-400' : ($rowPct >= 90 ? 'text-green-600' : ($rowPct >= 60 ? 'text-amber-600' : 'text-red-600')) }}">
                                        {{ $rowPct === null ? '—' : $rowPct . '%' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-6 py-8 text-center text-slate-500">No active policies.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
