<div>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Computers') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">Every machine reporting in, and what it is running.</p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search hostname, serial, IP, MAC…" aria-label="Search computers"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-80">
                @unless ($isTenant ?? false)
<select wire:model.live="clientId" aria-label="Filter by client"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All clients</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                    @endforeach
                </select>
@endunless
                <select wire:model.live="projectId" aria-label="Filter by {{ project_term_lower() }}"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All {{ project_terms_lower() }}</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="connectivity" aria-label="Filter by connectivity"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">Online + offline</option>
                    <option value="online">Online</option>
                    <option value="offline">Offline</option>
                </select>
                <select wire:model.live="agentStatus" aria-label="Filter by agent version"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">Any agent version</option>
                    <option value="outdated">Agent outdated</option>
                    <option value="current">Agent up to date</option>
                </select>
                <select wire:model.live="softwareStatus" aria-label="Filter by software status"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">Any software status</option>
                    <option value="outdated">Update required</option>
                    <option value="uptodate">Up to date</option>
                    <option value="pending">Pending job</option>
                </select>
                <select wire:model.live="hardwareType" aria-label="Filter by hardware type"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">Physical + virtual</option>
                    <option value="physical">Physical</option>
                    <option value="virtual">Virtual machine</option>
                </select>
                @unless ($isTenant ?? false)
<label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="showTrashed" class="rounded border-slate-300">
                    Show deleted
                </label>
@endunless
            </div>

            @php
                $pct = fn (int $n) => $stats['total'] > 0 ? round($n / $stats['total'] * 100).'%' : '—';
                $cards = [
                    ['label' => 'Total computers', 'value' => $stats['total'], 'sub' => $isTenant ? 'Your machines' : 'All clients', 'tone' => 'teal',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25"/>'],
                    ['label' => 'Online', 'value' => $stats['online'], 'sub' => $pct($stats['online']), 'tone' => 'green',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                    ['label' => 'Offline', 'value' => $stats['offline'], 'sub' => $pct($stats['offline']), 'tone' => 'slate',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>'],
                    ['label' => 'Update available', 'value' => $stats['update_available'], 'sub' => 'Updates itself', 'tone' => $stats['update_available'] > 0 ? 'amber' : 'slate',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>'],
                    ['label' => 'Needs re-enrolling', 'value' => $stats['stranded'], 'sub' => 'Cannot self-update', 'tone' => $stats['stranded'] > 0 ? 'red' : 'slate',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>'],
                ];
                $tones = [
                    'teal'  => 'bg-teal-50 text-teal-700 border-teal-100',
                    'green' => 'bg-green-50 text-green-700 border-green-100',
                    'amber' => 'bg-amber-50 text-amber-700 border-amber-100',
                    'red'   => 'bg-red-50 text-red-700 border-red-100',
                    'slate' => 'bg-slate-100 text-slate-600 border-slate-200',
                ];
            @endphp
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                @foreach ($cards as $card)
                    <div class="pd-card p-4">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $tones[$card['tone']] }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $card['icon'] !!}</svg>
                            </span>
                            <p class="text-sm font-semibold text-slate-700 leading-tight">{{ $card['label'] }}</p>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($card['value']) }}</p>
                        <p class="text-xs text-slate-400">{{ $card['sub'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Software across the fleet — a second row, deliberately: these
                 count machines by their SOFTWARE state, a different question
                 from the row above. "Total tracked" is items, not machines,
                 so it isn't a filter target; the other three set
                 softwareStatus and filter the same list below. --}}
            @php
                $swPct = fn (int $n) => $stats['total'] > 0 ? round($n / $stats['total'] * 100).'%' : '—';
                $softwareCards = [
                    ['label' => 'Total tracked', 'value' => $stats['software_total'], 'sub' => 'Software items detected', 'tone' => 'teal', 'filter' => null,
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375"/>'],
                    ['label' => 'Update required', 'value' => $stats['software_outdated'], 'sub' => $swPct($stats['software_outdated']).' of machines', 'tone' => $stats['software_outdated'] > 0 ? 'amber' : 'slate', 'filter' => 'outdated',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>'],
                    ['label' => 'Up to date', 'value' => $stats['software_uptodate'], 'sub' => $swPct($stats['software_uptodate']).' of machines', 'tone' => 'green', 'filter' => 'uptodate',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                    ['label' => 'Pending', 'value' => $stats['software_pending'], 'sub' => 'Machines with a job running', 'tone' => $stats['software_pending'] > 0 ? 'sky' : 'slate', 'filter' => 'pending',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                ];
                $swTones = $tones + ['sky' => 'bg-sky-50 text-sky-700 border-sky-100'];
            @endphp
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ($softwareCards as $card)
                    @php $tag = $card['filter'] === null ? 'div' : 'a'; @endphp
                    <{{ $tag }}
                        @if ($card['filter'] !== null)
                            href="#computers-list" wire:click="$set('softwareStatus', '{{ $card['filter'] }}')"
                        @endif
                        @class([
                            'pd-card p-4 block text-left',
                            'hover:border-teal-200 transition-colors cursor-pointer' => $card['filter'] !== null,
                            'ring-2 ring-teal-500' => $card['filter'] !== null && $softwareStatus === $card['filter'],
                        ])>
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $swTones[$card['tone']] }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $card['icon'] !!}</svg>
                            </span>
                            <p class="text-sm font-semibold text-slate-700 leading-tight">{{ $card['label'] }}</p>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($card['value']) }}</p>
                        <p class="text-xs text-slate-400">{{ $card['sub'] }}</p>
                    </{{ $tag }}>
                @endforeach
            </div>

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">
                    {{ session('error') }}
                </div>
            @endif


            {{-- Rows wrap instead of scrolling sideways. Seven columns of
                 machine detail never fit a laptop screen, so the table forced a
                 horizontal scrollbar and hid the status column — the one thing
                 an operator scans for. Each machine is now a block that reflows:
                 identity on the left, facts in the middle, state on the right. --}}
            <div class="pd-card" id="computers-list">
                <ul class="divide-y divide-slate-100">
                    @forelse ($computers as $computer)
                        {{-- py-3, not py-4 — matches Clients; see that file for why. --}}
                        <li @class(['px-5 py-3 hover:bg-slate-50/60 transition-colors', 'opacity-60' => $computer->trashed()])>
                            <div class="flex flex-wrap items-start gap-x-6 gap-y-3">

                                {{-- Identity --}}
                                <div class="min-w-[13rem] grow basis-64">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <a href="{{ route('computers.show', $computer) }}"
                                           class="font-semibold pd-link">{{ $computer->hostname }}</a>
                                        @if ($computer->trashed())
                                            <span class="text-xs rounded-full bg-red-50 text-red-600 border border-red-200 px-2 py-0.5">deleted</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        {{ $computer->project->client->company_name }}
                                        <span class="mx-1 text-slate-300">·</span>{{ $computer->project->name }}
                                    </p>
                                    @if ($computer->serial_number)
                                        <p class="text-[11px] text-slate-400 font-mono mt-0.5 break-all">{{ $computer->serial_number }}</p>
                                    @endif
                                </div>

                                {{-- Machine facts --}}
                                <div class="min-w-[11rem] grow basis-48 text-sm text-slate-600">
                                    <p>{{ $computer->os_name }}</p>
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        build {{ $computer->windows_build ?? '—' }}
                                        <span class="mx-1 text-slate-300">·</span>
                                        <span class="font-mono">{{ $computer->private_ip ?? 'no IP' }}</span>
                                    </p>
                                </div>

                                {{-- Agent --}}
                                <div class="min-w-[9rem] shrink-0 text-sm text-slate-600">
                                    <span class="font-mono text-xs">{{ $computer->agent_version ?? '—' }}</span>
                                    @if ($computer->isAgentOutdated())
                                        <span class="ml-1 inline-flex items-center text-xs font-semibold rounded-full px-2 py-0.5 border bg-amber-50 text-amber-700 border-amber-200"
                                              title="Latest is {{ \App\Models\Computer::latestAgentVersion() }} — the agent self-updates on its next check-in">
                                            Update available
                                        </span>
                                    @endif
                                </div>

                                {{-- State + actions, pinned right on wide screens --}}
                                <div class="flex items-center gap-4 shrink-0 ml-auto">
                                    <div class="text-right">
                                        @if ($computer->isOnline())
                                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-full px-2.5 py-1 border bg-green-50 text-green-700 border-green-200">
                                                <span class="h-1.5 w-1.5 rounded-full bg-green-500" aria-hidden="true"></span> Online
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-full px-2.5 py-1 border bg-slate-100 text-slate-600 border-slate-200">
                                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span> Offline
                                            </span>
                                        @endif
                                        <p class="text-xs text-slate-400 mt-1">
                                            {{ $computer->last_seen_at?->diffForHumans() ?? 'never seen' }}
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-1">
                                        @if ($computer->trashed())
                                            @can('restore', $computer)
                                                <x-icon-button icon="restore" label="Restore" wire:click="restore({{ $computer->id }})" />
                                            @endcan
                                            @can('forceDelete', $computer)
                                                <x-icon-button icon="delete" variant="danger" label="Delete permanently"
                                                               wire:click="forceDelete({{ $computer->id }})"
                                                               wire:confirm="Permanently delete “{{ $computer->hostname }}” and all its history? Only possible once its agent is uninstalled — this cannot be undone." />
                                            @endcan
                                        @else
                                            @can('update', $computer)
                                                <x-icon-button icon="reassign" label="Reassign project" :href="route('computers.edit', $computer)" />
                                            @endcan
                                            @can('delete', $computer)
                                                <x-icon-button icon="delete" variant="danger" label="Retire"
                                                               wire:click="delete({{ $computer->id }})"
                                                               wire:confirm="Retire “{{ $computer->hostname }}”? It moves to the retired list (Show deleted) with its history kept. If its agent reports again it is revived automatically. To remove it for good: uninstall the agent, then delete permanently from the retired list." />
                                            @endcan
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="px-6 py-12 text-center">
                            <p class="text-slate-500">No computers found.</p>
                            <p class="text-xs text-slate-400 mt-1">Machines appear here within a minute of their agent starting.</p>
                        </li>
                    @endforelse
                </ul>
            </div>

            {{ $computers->links() }}
        </div>
    </div>
</div>
