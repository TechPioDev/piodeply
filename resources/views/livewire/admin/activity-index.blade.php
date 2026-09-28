<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Activity log') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search action or user…" aria-label="Search activity"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-72">
                <select wire:model.live="logFilter" aria-label="Filter by log"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All logs</option>
                    @foreach ($logNames as $logName)
                        <option value="{{ $logName }}">{{ ucfirst($logName) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">When</th>
                            <th class="pd-th">Log</th>
                            <th class="pd-th">Action</th>
                            <th class="pd-th">By</th>
                            <th class="pd-th">Subject</th>
                            <th class="pd-th">Previous value</th>
                            <th class="pd-th">New value</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($activities as $activity)
                            @php($values = $this->valuesFor($activity))
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 text-sm" title="{{ $activity->created_at }}">
                                    {{ $activity->created_at->format('Y-m-d H:i:s') }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-slate-100 text-slate-600 border-slate-200">
                                        {{ $activity->log_name }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-700 text-sm font-medium">
                                    {{ str_replace('_', ' ', $activity->description) }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">
                                    {{ $activity->causer?->name ?? 'System' }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 text-sm">
                                    @if ($activity->subject_type)
                                        {{ class_basename($activity->subject_type) }} #{{ $activity->subject_id }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-slate-500 text-xs font-mono max-w-xs truncate" title="{{ $values['previous'] }}">
                                    {{ $values['previous'] ?? '—' }}
                                </td>
                                <td class="px-6 py-3 text-slate-500 text-xs font-mono max-w-xs truncate" title="{{ $values['new'] }}">
                                    {{ $values['new'] ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No activity recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $activities->links() }}
        </div>
    </div>
</div>
