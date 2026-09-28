<div>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Needs attention') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">
                Everything waiting on a technician or administrator — failed deployments, machines that stopped
                checking in, {{ policy_terms_lower() }} with failing machines, and approvals waiting on a decision.
            </p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="pd-card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Machine</th>
                            <th class="pd-th">{{ project_term() }}</th>
                            <th class="pd-th">Issue</th>
                            <th class="pd-th">Severity</th>
                            <th class="pd-th">Detected</th>
                            <th class="pd-th">Status</th>
                            <th class="pd-th">Recommended action</th>
                            <th class="pd-th"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($items as $item)
                            @php
                                $tone = match ($item['severity']) {
                                    'Critical' => ['bg' => 'bg-red-50', 'border' => 'border-red-200', 'text' => 'text-red-700'],
                                    'Warning'  => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700'],
                                    default    => ['bg' => 'bg-blue-50', 'border' => 'border-blue-200', 'text' => 'text-blue-700'],
                                };
                            @endphp
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-700">
                                    @if ($item['computer'])
                                        <a href="{{ route('computers.show', $item['computer']) }}" class="pd-link">{{ $item['computer']->hostname }}</a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">{{ $item['site'] ?? '—' }}</td>
                                <td class="px-6 py-3 text-sm text-slate-800 font-medium max-w-xs">{{ $item['issue'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-xs font-semibold rounded-full px-2.5 py-1 border {{ $tone['bg'] }} {{ $tone['border'] }} {{ $tone['text'] }}">
                                        {{ $item['severity'] }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500" title="{{ $item['detected_at'] }}">
                                    {{ $item['detected_at']->diffForHumans() }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600">{{ $item['status'] }}</td>
                                <td class="px-6 py-3 text-sm text-slate-500 max-w-sm">{{ $item['recommended_action'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        @if ($item['action_url'])
                                            <a href="{{ $item['action_url'] }}" class="text-sm font-medium text-teal-700 hover:text-teal-800">
                                                {{ $item['action_label'] }}
                                            </a>
                                        @endif
                                        @if ($item['dismissable'] && $item['latest_job'] && \Illuminate\Support\Facades\Gate::allows('manage', $item['latest_job']))
                                            <button type="button"
                                                    wire:click="dismiss('{{ $item['cause_key'] }}', {{ $item['latest_job']->id }})"
                                                    wire:confirm="Mark this handled? It reappears automatically if it fails again."
                                                    class="text-sm font-medium text-slate-500 hover:text-slate-700">
                                                Mark handled
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center">
                                    <p class="text-slate-500">Nothing needs attention.</p>
                                    <p class="text-xs text-slate-400 mt-1">Failed deployments, offline machines, failing {{ policy_terms_lower() }}, and pending approvals all appear here the moment they need a look.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
