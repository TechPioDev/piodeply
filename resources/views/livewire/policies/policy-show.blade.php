<div wire:poll.15s>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ $policy->label() }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    {{ $policy->scopeName() }}
                    · {{ $policy->mode->label() }} · {{ $policy->priorityLabel() }} priority
                    · Window: {{ $policy->windowLabel() }}
                    @if ($policy->test_delay_days > 0 || $policy->production_delay_days > 0)
                        · Rings: test +{{ $policy->test_delay_days }}d, production +{{ $policy->production_delay_days }}d
                    @endif
                    @if ($policy->creator) · created by {{ $policy->creator->name }} @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @can('update', $policy)
                    <a href="{{ route('policies.edit', $policy) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Edit
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

            @can('enforce', $policy)
                @if ($policy->mode === \App\Enums\PolicyMode::Enforce)
                    <div class="flex justify-end">
                        <x-button type="button" wire:click="enforceNow" wire:loading.attr="disabled">Enforce now</x-button>
                    </div>
                @endif
            @endcan

            {{-- Compliance summary — click a card to filter the table --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
                @php
                    $cards = [
                        ['key' => '',              'label' => 'Target',        'value' => $summary['target'],        'tone' => 'text-slate-800'],
                        ['key' => 'compliant',     'label' => 'Compliant',     'value' => $summary['compliant'],     'tone' => 'text-green-600'],
                        ['key' => 'pending',       'label' => 'Pending',       'value' => $summary['pending'],       'tone' => 'text-blue-600'],
                        ['key' => 'scheduled',     'label' => 'Scheduled',     'value' => $summary['scheduled'],     'tone' => 'text-violet-600'],
                        ['key' => 'failed',        'label' => 'Failed',        'value' => $summary['failed'],        'tone' => 'text-red-600'],
                        ['key' => 'non_compliant', 'label' => 'Non-compliant', 'value' => $summary['non_compliant'], 'tone' => 'text-amber-600'],
                        ['key' => 'offline',       'label' => 'Offline',       'value' => $summary['offline'],       'tone' => 'text-slate-500'],
                    ];
                @endphp
                @foreach ($cards as $card)
                    <button type="button" wire:click="filterBy('{{ $card['key'] }}')"
                            @class(['pd-card p-4 text-left transition',
                                    'ring-2 ring-teal-500' => $statusFilter === $card['key'] && $card['key'] !== ''])>
                        <p class="text-xs uppercase tracking-wider text-slate-400">{{ $card['label'] }}</p>
                        <p class="text-2xl font-bold {{ $card['tone'] }}">{{ $card['value'] }}</p>
                    </button>
                @endforeach
            </div>

            <div class="pd-card p-4 flex items-center justify-between">
                <div class="text-sm text-slate-600">
                    <span class="font-semibold">Compliance:</span>
                    @if ($summary['percent'] === null)
                        <span class="text-slate-400">no computers in scope</span>
                    @else
                        @php $pct = $summary['percent']; @endphp
                        <span @class(['font-bold', 'text-green-600' => $pct >= 90, 'text-amber-600' => $pct >= 60 && $pct < 90, 'text-red-600' => $pct < 60])>{{ $pct }}%</span>
                        <span class="text-slate-400">· {{ $summary['excluded'] }} excluded ·
                            last enforced {{ $policy->last_enforced_at?->diffForHumans() ?? 'never' }}</span>
                    @endif
                </div>
                @if ($statusFilter !== '')
                    <button type="button" wire:click="filterBy('{{ $statusFilter }}')" class="text-xs text-teal-600 hover:underline">
                        Clear filter
                    </button>
                @endif
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Computer</th>
                            <th class="pd-th">Status</th>
                            <th class="pd-th">Installed version</th>
                            <th class="pd-th">Reason</th>
                            <th class="pd-th">Agent</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($rows as $row)
                            <tr @class(['opacity-60' => $row['status'] === 'excluded'])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('computers.show', $row['computer']) }}" class="pd-link">{{ $row['computer']->hostname }}</a>
                                    <span class="ml-1 text-xs text-slate-400">{{ $row['computer']->ring->label() }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $statusBadge = match ($row['status']) {
                                            'compliant' => 'bg-green-50 text-green-700 border-green-200',
                                            'pending' => 'bg-blue-50 text-blue-700 border-blue-200',
                                            'scheduled' => 'bg-violet-50 text-violet-700 border-violet-200',
                                            'failed' => 'bg-red-50 text-red-700 border-red-200',
                                            'non_compliant' => 'bg-amber-50 text-amber-700 border-amber-200',
                                            'excluded' => 'bg-slate-100 text-slate-500 border-slate-200',
                                            default => 'bg-slate-100 text-slate-700 border-slate-200',
                                        };
                                        $statusLabel = match ($row['status']) {
                                            'non_compliant' => 'Non-compliant',
                                            default => ucfirst($row['status']),
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $statusBadge }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 font-mono text-sm">
                                    {{ $row['installed_version'] ?? '—' }}
                                </td>
                                <td class="px-6 py-3 text-slate-500 text-sm max-w-md truncate" title="{{ $row['reason'] }}">
                                    {{ $row['reason'] }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $row['offline'] ? 'bg-slate-100 text-slate-500 border-slate-200' : 'bg-green-50 text-green-700 border-green-200' }}">
                                        {{ $row['offline'] ? 'Offline' : 'Online' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm">
                                    @can('update', $policy)
                                        <button type="button" wire:click="toggleExclusion({{ $row['computer']->id }})"
                                                class="text-xs {{ $row['status'] === 'excluded' ? 'text-teal-600' : 'text-slate-400' }} hover:underline">
                                            {{ $row['status'] === 'excluded' ? 'Include' : 'Exclude' }}
                                        </button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">
                                {{ $statusFilter === '' ? 'No computers in this '.project_term_lower().' yet.' : 'No machines match this filter.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{-- Change history --}}
            <div class="pd-card p-6">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Change history</h3>
                @forelse ($history as $entry)
                    <div class="flex items-start gap-3 py-2 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                        <span class="mt-1 h-2 w-2 rounded-full shrink-0 {{ match($entry->description) {
                            'created' => 'bg-teal-500',
                            'updated' => 'bg-blue-400',
                            'exclusion_toggled' => 'bg-amber-400',
                            default => 'bg-slate-300',
                        } }}"></span>
                        <div class="min-w-0 text-sm">
                            <p class="text-slate-700">
                                <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $entry->description)) }}</span>
                                <span class="text-slate-400">· {{ $entry->causer?->name ?? 'System' }}
                                    · {{ $entry->created_at->diffForHumans() }}</span>
                            </p>
                            @if ($entry->description === 'updated' && $entry->properties->has('attributes'))
                                <p class="text-xs text-slate-500 truncate">
                                    @foreach ($entry->properties['attributes'] as $field => $newValue)
                                        {{ $field }}:
                                        <span class="line-through">{{ is_array($entry->properties['old'][$field] ?? null) ? json_encode($entry->properties['old'][$field]) : ($entry->properties['old'][$field] ?? '—') }}</span>
                                        → <span class="font-medium">{{ is_array($newValue) ? json_encode($newValue) : $newValue }}</span>{{ ! $loop->last ? ' · ' : '' }}
                                    @endforeach
                                </p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">No changes recorded yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
