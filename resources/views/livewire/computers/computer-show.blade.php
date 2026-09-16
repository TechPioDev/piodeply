<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-900 leading-tight">
                {{ $computer->hostname }}
                @if ($computer->isOnline())
                    <span class="ml-2 align-middle pd-badge pd-badge-green"><span class="pd-dot"></span>Online</span>
                @else
                    <span class="ml-2 align-middle pd-badge pd-badge-slate"><span class="pd-dot"></span>Offline</span>
                @endif
            </h2>
            <div class="flex items-center gap-4">
                @if ($computer->reinstall_requested_at !== null || $computer->uninstall_requested_at !== null)
                    <span class="pd-badge pd-badge-amber" title="Delivered at the agent's next check-in">
                        {{ $computer->uninstall_requested_at !== null ? 'Uninstall' : 'Reinstall' }} pending
                    </span>
                    {{-- The header slot renders OUTSIDE the Livewire DOM, so
                         wire:click/wire:confirm never fire here — these use a
                         JS confirm + global dispatch instead. --}}
                    @can('update', $computer)
                        <button type="button" onclick="Livewire.dispatch('computer-cancel-agent-command')" class="text-sm pd-action">Cancel</button>
                    @endcan
                @else
                    @can('update', $computer)
                        <button type="button"
                            onclick="if (confirm('Reinstall the agent on {{ $computer->hostname }}? At its next check-in it re-downloads the current bundle and replaces itself. Its settings and identity are kept.')) Livewire.dispatch('computer-request-reinstall')"
                            class="text-sm pd-action"
                            title="Remote fix for a broken agent that still checks in — the machine replaces its own install">
                            Reinstall agent
                        </button>
                    @endcan
                    @can('delete', $computer)
                        <button type="button"
                            onclick="if (confirm('Remove the PioDeploy agent from {{ $computer->hostname }}? The machine will delete the service and all agent files at its next check-in. Software installed through PioDeploy stays. This computer record and its history remain here until you delete them.')) Livewire.dispatch('computer-request-uninstall')"
                            class="text-sm text-rose-600 hover:text-rose-700 font-medium"
                            title="The machine removes its own agent — service and files — at the next check-in">
                            Uninstall agent
                        </button>
                    @endcan
                @endif
                @can('update', $computer)
                    <a href="{{ route('computers.edit', $computer) }}" class="text-sm pd-action">Reassign project</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5">
            @if (session('status'))
                <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Health summary --}}
            @if (count($health) === 0)
                <div class="pd-card p-4 flex items-center gap-3">
                    <span class="h-8 w-8 rounded-full bg-emerald-50 border border-emerald-200 grid place-content-center">
                        <svg class="h-4 w-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <p class="text-sm font-semibold text-emerald-700">No issues detected on this machine.</p>
                </div>
            @else
                <div class="pd-card p-4 space-y-2" role="alert">
                    <p class="text-sm font-semibold text-slate-800">Attention required</p>
                    @foreach ($health as $check)
                        <div class="flex items-start gap-2 text-sm {{ $check['level'] === 'warn' ? 'text-amber-700' : 'text-slate-500' }}">
                            @if ($check['level'] === 'warn')
                                <svg class="h-4 w-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                            @else
                                <svg class="h-4 w-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                            @endif
                            <span>{{ $check['message'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Readiness: the machine cannot sync or deploy software until
                 these are fixed. Every check is one this fleet has hit. --}}
            @if (! empty($readinessIssues))
                <div class="pd-card p-4 border-red-200 bg-red-50/40 space-y-3" role="alert">
                    <p class="text-sm font-semibold text-red-700 flex items-center gap-2">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                        Not ready to deploy software
                    </p>
                    @foreach ($readinessIssues as $issue)
                        <div class="text-sm">
                            <p class="font-semibold text-slate-800">{{ $issue['title'] }}</p>
                            <p class="text-slate-600">{{ $issue['fix'] }}</p>
                            @if ($issue['detail'])
                                <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $issue['detail'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Deployment stats --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-teal-700 leading-tight">{{ $stats['succeeded'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Deployments</p>
                    <p class="text-xs text-slate-400">succeeded</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-sky-600 leading-tight">{{ $stats['in_flight'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">In flight</p>
                    <p class="text-xs text-slate-400">pending / running / blocked</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold {{ $stats['failed'] > 0 ? 'text-red-600' : 'text-slate-300' }} leading-tight">{{ $stats['failed'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Failed</p>
                    <p class="text-xs text-slate-400">out of retries</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-slate-700 leading-tight">
                        {{ $stats['last_deploy'] ? \Illuminate\Support\Carbon::parse($stats['last_deploy'])->diffForHumans(short: true) : '—' }}
                    </p>
                    <p class="text-sm font-semibold text-slate-700">Last deployment</p>
                    <p class="text-xs text-slate-400">{{ $stats['last_deploy'] ? \Illuminate\Support\Carbon::parse($stats['last_deploy'])->format('Y-m-d H:i') : 'never' }}</p>
                </div>
            </div>

            {{-- Deploy widget --}}
            @can('create', \App\Models\DeploymentJob::class)
                @livewire('deployments.deploy-to-computer', ['computer' => $computer], key('deploy-'.$computer->id))
            @endcan

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                @php
                    $sections = [
                        'Assignment' => [
                            'Client' => $computer->project->client->company_name,
                            project_term() => $computer->project->name,
                            'Enrolled' => $computer->created_at->format('Y-m-d') . ' (' . $computer->created_at->diffForHumans() . ')',
                            'Agent version' => $computer->agent_version
                                ? $computer->agent_version . ($computer->isAgentOutdated()
                                    ? ' — update available (latest ' . \App\Models\Computer::latestAgentVersion() . ')'
                                    : ' (up to date)')
                                : 'unknown',
                            'Agent UUID' => $computer->agent_uuid,
                            'Last seen' => $computer->last_seen_at ? $computer->last_seen_at->format('Y-m-d H:i:s') . ' (' . $computer->last_seen_at->diffForHumans() . ')' : 'never',
                            'Inventory updated' => $computer->updated_at->diffForHumans(),
                        ],
                        'System' => [
                            'Manufacturer' => $computer->manufacturer,
                            'Model' => $computer->model,
                            'Serial number' => $computer->serial_number,
                            'OS' => $computer->os_name,
                            'OS version' => $computer->os_version,
                            'Windows build' => $computer->windows_build,
                        ],
                        'Network' => [
                            'Private IP' => $computer->private_ip,
                            'Public IP' => $computer->public_ip,
                            'MAC address' => $computer->mac_address,
                        ],
                    ];
                @endphp

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Assignment</h3>
                    <dl class="space-y-2">
                        @foreach ($sections['Assignment'] as $label => $value)
                            <div class="flex justify-between gap-4 text-sm">
                                <dt class="text-slate-400 shrink-0">{{ $label }}</dt>
                                <dd class="text-slate-900 text-right break-all">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">System</h3>
                    <dl class="space-y-2">
                        @foreach ($sections['System'] as $label => $value)
                            <div class="flex justify-between gap-4 text-sm">
                                <dt class="text-slate-400 shrink-0">{{ $label }}</dt>
                                <dd class="text-slate-900 text-right break-all">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Hardware</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-400">CPU</dt>
                            <dd class="text-slate-900 text-right">{{ $computer->cpu ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-400">RAM</dt>
                            <dd class="text-slate-900 text-right">{{ $computer->ramForHumans() ?? '—' }}</dd>
                        </div>
                        <div>
                            <div class="flex justify-between gap-4 mb-1.5">
                                <dt class="text-slate-400">System disk</dt>
                                <dd class="text-slate-900 text-right">{{ $computer->diskForHumans() ?? '—' }}</dd>
                            </div>
                            @if ($diskUsedPercent !== null)
                                <div class="h-2 rounded-full bg-slate-100 overflow-hidden"
                                     role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $diskUsedPercent }}"
                                     aria-label="Disk usage {{ $diskUsedPercent }} percent">
                                    <div class="h-full rounded-full {{ $diskUsedPercent >= 90 ? 'bg-red-500' : ($diskUsedPercent >= 75 ? 'bg-amber-400' : 'bg-teal-500') }}"
                                         style="width: {{ $diskUsedPercent }}%"></div>
                                </div>
                                <p class="text-xs text-slate-400 mt-1">{{ $diskUsedPercent }}% used</p>
                            @endif
                        </div>
                    </dl>
                </div>

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Network</h3>
                    <dl class="space-y-2">
                        @foreach ($sections['Network'] as $label => $value)
                            <div class="flex justify-between gap-4 text-sm">
                                <dt class="text-slate-400 shrink-0">{{ $label }}</dt>
                                <dd class="text-slate-900 text-right font-mono text-[13px]">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Security posture</h3>
                    <dl class="space-y-2">
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-slate-400">Secure Boot</dt>
                            <dd>
                                @if ($computer->secure_boot === null) <span class="text-slate-400">unknown</span>
                                @elseif ($computer->secure_boot) <span class="text-emerald-700 font-semibold">Enabled</span>
                                @else <span class="text-red-600 font-semibold">Disabled</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-slate-400">TPM</dt>
                            <dd>
                                @if ($computer->tpm_enabled === null) <span class="text-slate-400">unknown</span>
                                @elseif ($computer->tpm_enabled) <span class="text-emerald-700 font-semibold">Enabled{{ $computer->tpm_version ? ' (v' . $computer->tpm_version . ')' : '' }}</span>
                                @else <span class="text-red-600 font-semibold">Disabled</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                {{-- Recent activity --}}
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Recent activity</h3>
                    @if ($recentActivity->isEmpty())
                        <p class="text-sm text-slate-400">No changes recorded.</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($recentActivity as $activity)
                                <li class="text-sm flex justify-between gap-3">
                                    <span class="text-slate-700">
                                        {{ ucfirst($activity->description) }}
                                        @if ($activity->causer)
                                            <span class="text-slate-400">by {{ $activity->causer->name }}</span>
                                        @endif
                                    </span>
                                    <span class="text-slate-400 whitespace-nowrap">{{ $activity->created_at->diffForHumans(short: true) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Browsers: installed version compared to the newest seen
                 anywhere on this client's fleet — never against Microsoft's
                 release feed, which this platform has no way to verify. --}}
            @if (! empty($browsers))
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Browsers</h3>
                    <dl class="divide-y divide-slate-100">
                        @foreach ($browsers as $row)
                            <div class="flex items-center justify-between gap-4 py-2 text-sm">
                                <dt class="text-slate-600 font-medium">{{ $row['browser']->label() }}</dt>
                                <dd class="flex items-center gap-2 text-right">
                                    <span class="{{ $row['behind'] ? 'text-amber-700' : 'text-slate-700' }}">{{ $row['version'] }}</span>
                                    @if ($row['stuck'])
                                        <span class="pd-badge pd-badge-red" title="Unchanged since {{ $row['since']->format('j M Y') }} while the fleet moved to {{ $row['fleet_latest'] }} — the browser's own updater may be disabled.">
                                            stuck {{ (int) $row['since']->diffInDays(now()) }}d
                                        </span>
                                    @elseif ($row['behind'])
                                        <span class="text-xs text-slate-400">fleet: {{ $row['fleet_latest'] }}</span>
                                    @else
                                        <span class="pd-badge pd-badge-green">current</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif

            {{-- Browser policies --}}
            @if ($browserPolicyRows->isNotEmpty())
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Browser policies</h3>
                    <div class="space-y-3">
                        @foreach ($browserPolicyRows as $row)
                            <div class="flex flex-wrap items-center gap-3 text-sm {{ $row['excluded'] ? 'opacity-50' : '' }}">
                                <a href="{{ route('browser-policies.show', $row['policy']) }}" class="pd-link font-medium w-64 truncate">
                                    {{ $row['policy']->name }}
                                </a>
                                @if ($row['excluded'])
                                    <span class="text-xs text-slate-400">Excluded from this machine</span>
                                @elseif ($row['results']->isEmpty())
                                    <span class="text-xs text-blue-600">Awaiting agent</span>
                                @else
                                    @foreach ($row['results'] as $browser => $result)
                                        <span class="text-xs" title="{{ $result->detail }}">
                                            <span class="text-slate-500">{{ \App\Enums\Browser::from($browser)->label() }}:</span>
                                            <span @class([
                                                'font-semibold',
                                                'text-green-600' => $result->status === 'compliant',
                                                'text-red-600' => in_array($result->status, ['non_compliant', 'error'], true),
                                                'text-blue-600' => $result->status === 'pending_restart',
                                                'text-amber-600' => $result->status === 'unsupported',
                                                'text-slate-400' => $result->status === 'not_installed',
                                            ])>{{ str_replace('_', ' ', $result->status) }}</span>
                                        </span>
                                    @endforeach
                                    <span class="text-xs text-slate-400">· checked {{ $row['results']->max('reported_at')?->diffForHumans() }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Software summary: click any card to jump to and filter the
                 table below to exactly that slice — same softwareFilter
                 property the dropdown drives, so the two stay in sync. --}}
            @php
                $softwareCards = [
                    ['label' => 'Total tracked', 'value' => $softwareTotal, 'sub' => 'All detected', 'tone' => 'teal', 'filter' => 'all',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375"/>'],
                    ['label' => 'Update required', 'value' => $softwareOutdated, 'sub' => 'Needs attention', 'tone' => $softwareOutdated > 0 ? 'amber' : 'slate', 'filter' => 'outdated',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>'],
                    ['label' => 'Up to date', 'value' => $softwareUpToDate, 'sub' => $softwareTotal > 0 ? round($softwareUpToDate / $softwareTotal * 100).'%' : '—', 'tone' => 'green', 'filter' => 'uptodate',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                    ['label' => 'Pending', 'value' => $softwarePending, 'sub' => 'Queued / running', 'tone' => $softwarePending > 0 ? 'sky' : 'slate', 'filter' => 'pending',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                ];
                $softwareTones = [
                    'teal'  => 'bg-teal-50 text-teal-700 border-teal-100',
                    'green' => 'bg-green-50 text-green-700 border-green-100',
                    'amber' => 'bg-amber-50 text-amber-700 border-amber-100',
                    'sky'   => 'bg-sky-50 text-sky-700 border-sky-100',
                    'slate' => 'bg-slate-100 text-slate-600 border-slate-200',
                ];
            @endphp
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ($softwareCards as $card)
                    <a href="#installed-software" wire:click="$set('softwareFilter', '{{ $card['filter'] }}')"
                       class="pd-card p-4 block text-left hover:border-teal-200 transition-colors {{ $softwareFilter === $card['filter'] ? 'ring-2 ring-teal-500' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $softwareTones[$card['tone']] }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $card['icon'] !!}</svg>
                            </span>
                            <p class="text-sm font-semibold text-slate-700 leading-tight">{{ $card['label'] }}</p>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($card['value']) }}</p>
                        <p class="text-xs text-slate-400">{{ $card['sub'] }}</p>
                    </a>
                @endforeach
            </div>

            {{-- Installed software --}}
            <div class="pd-card" id="installed-software">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-3">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Installed software
                        <span class="ml-1 text-slate-400 font-normal normal-case">
                            (@if ($softwareOutdated > 0)<span class="text-amber-600 font-medium">{{ $softwareOutdated }} outdated</span> · @endif{{ $softwareDeployed }} by PioDeploy · {{ $softwareManaged }} managed · {{ $softwareTotal }} detected)
                        </span>
                    </h3>
                    <div class="flex items-center gap-4">
                        <select wire:model.live="softwareFilter" aria-label="Filter software"
                                class="border-slate-300 rounded-md shadow-sm text-sm py-1.5">
                            <option value="managed">In catalogue</option>
                            <option value="deployed">Deployed by PioDeploy</option>
                            <option value="outdated">Update available</option>
                            <option value="uptodate">Up to date</option>
                            <option value="pending">Pending job</option>
                            <option value="all">All software</option>
                        </select>
                        <input type="search" wire:model.live.debounce.300ms="softwareSearch"
                               placeholder="Search software…" aria-label="Search installed software"
                               class="pd-input w-64 py-1.5">
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="pd-th">Application</th>
                                <th class="pd-th">Installed (current)</th>
                                <th class="pd-th">Latest</th>
                                <th class="pd-th">Update required</th>
                                <th class="pd-th">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($softwareItems as $item)
                                <tr>
                                    <td class="px-6 py-2.5 text-sm text-slate-800">
                                        <span class="{{ $item->source === 'winget' ? 'font-mono text-[13px]' : 'font-medium' }}">{{ $item->name }}</span>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-400">
                                            @if ($item->publisher)<span class="max-w-[14rem] truncate">{{ $item->publisher }}</span>@endif
                                            <span class="pd-badge {{ $item->source === 'winget' ? 'pd-badge-sky' : ($item->source === 'choco' ? 'pd-badge-amber' : 'pd-badge-slate') }}">{{ $item->source }}</span>
                                            @if ($item->source === 'winget' && $managedPackages->has($item->name))
                                                <a href="{{ route('packages.show', $managedPackages[$item->name]) }}"
                                                   class="pd-badge pd-badge-teal hover:bg-teal-100">managed</a>
                                            @endif
                                            @if ($deployedNames->contains($item->name))
                                                <span class="pd-badge pd-badge-sky" title="A PioDeploy job installed this on {{ $computer->hostname }}">PioDeploy</span>
                                            @endif
                                        </p>
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-sm text-slate-600 font-mono text-[13px]">{{ $item->version ?? '—' }}</td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-sm font-mono text-[13px] {{ $item->hasUpdate() ? 'text-amber-600 font-semibold' : 'text-slate-400' }}">
                                        {{ $item->available_version ?? '—' }}
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        @if ($item->hasUpdate())
                                            <span class="inline-flex text-xs font-semibold rounded-md px-2 py-0.5 border bg-amber-50 text-amber-700 border-amber-300">Yes</span>
                                        @else
                                            <span class="inline-flex text-xs font-semibold rounded-md px-2 py-0.5 border bg-green-50 text-green-700 border-green-300">No</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        @php $inFlight = $inFlightByWingetId[$item->name] ?? null; @endphp
                                        @if ($inFlight)
                                            {{-- A job is already queued/running for this app: show it,
                                                 so a click reflects instantly and never double-queues. --}}
                                            <span class="inline-flex items-center gap-1 text-xs font-semibold rounded-full px-3 py-1 border bg-blue-50 text-blue-700 border-blue-200">
                                                <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                                {{ $inFlight === \App\Enums\JobStatus::Running ? 'Updating…' : 'Queued' }}
                                            </span>
                                        @elseif ($item->hasUpdate())
                                            @can('create', \App\Models\DeploymentJob::class)
                                                @if (in_array($item->source, ['winget', 'choco'], true))
                                                    {{-- Clickable whether catalogued or not: an uncatalogued
                                                         app is adopted into the catalogue on click, then updated. --}}
                                                    <button type="button" wire:click="queueUpdate({{ $item->id }})"
                                                            wire:loading.attr="disabled" wire:target="queueUpdate({{ $item->id }})"
                                                            class="inline-flex items-center gap-1 text-xs font-semibold rounded-full px-3 py-1 border bg-amber-50 text-amber-700 border-amber-300 hover:bg-amber-100 transition-colors disabled:opacity-60"
                                                            title="{{ $managedPackages->has($item->name) ? 'Queue an update to '.$item->available_version : 'Add to your catalogue and update to '.$item->available_version }} on {{ $computer->hostname }}">
                                                        <span wire:loading.remove wire:target="queueUpdate({{ $item->id }})">Update now →</span>
                                                        <span wire:loading wire:target="queueUpdate({{ $item->id }})">Queuing…</span>
                                                    </button>
                                                @else
                                                    <span class="inline-flex text-xs font-semibold rounded-full px-3 py-1 border bg-amber-50 text-amber-700 border-amber-300"
                                                          title="No winget/Chocolatey id — cannot be managed automatically">Update available</span>
                                                @endif
                                            @else
                                                <span class="inline-flex text-xs font-semibold rounded-full px-3 py-1 border bg-amber-50 text-amber-700 border-amber-300">Update available</span>
                                            @endcan
                                        @else
                                            <span class="inline-flex text-xs font-semibold rounded-full px-3 py-1 border bg-green-50 text-green-700 border-green-300">Up to date</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">
                                    @if ($softwareTotal === 0)
                                        No software inventory reported yet — it arrives with the agent's next report.
                                    @elseif ($softwareSearch !== '')
                                        No software matches your search.
                                    @elseif ($softwareFilter === 'deployed')
                                        No PioDeploy installs recorded on this machine yet.
                                    @elseif ($softwareFilter === 'managed')
                                        No catalogue software detected out of {{ $softwareTotal }} entries — switch to
                                        <b>All software</b> to see them.
                                        @if ($computer->agent_version && version_compare($computer->agent_version, '1.3.1', '<'))
                                            <span class="block mt-2 text-amber-600">
                                                <b>This agent is {{ $computer->agent_version }}.</b>
                                                <span>Agents before 1.3.1 cannot scan winget as SYSTEM, so nothing matches the catalogue — upgrade the agent.</span>
                                            </span>
                                        @endif
                                    @else
                                        No software matches your search.
                                    @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($softwareItems->count() === 150)
                    <p class="px-6 py-2 text-xs text-slate-400 border-t border-slate-100">Showing first 150 matches — refine the search to narrow down.</p>
                @endif
            </div>

            {{-- Why software is, or is not, where it should be. Answers the
                 question the job list cannot: nothing happened, and why. --}}
            <div class="pd-card">
                <div class="flex items-center justify-between px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Software status
                        <span class="ml-1 text-slate-400 font-normal normal-case">— what each policy wants here, and why</span>
                    </h3>
                    <a href="{{ route('policies.index') }}" class="text-sm pd-action">Policies →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="pd-th">Package</th>
                                <th class="pd-th">Policy wants</th>
                                <th class="pd-th">Installed</th>
                                <th class="pd-th">State</th>
                                <th class="pd-th">Why</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($policyExplanations as $row)
                                @php
                                    $badge = match ($row['status']) {
                                        'compliant'     => 'pd-badge-green',
                                        'non_compliant' => 'pd-badge-red',
                                        'failed'        => 'pd-badge-red',
                                        'pending'       => 'pd-badge-sky',
                                        'scheduled'     => 'pd-badge-amber',
                                        default         => 'pd-badge-slate',
                                    };
                                @endphp
                                <tr>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <a href="{{ route('packages.show', $row['policy']->package) }}"
                                           class="pd-link text-sm">{{ $row['policy']->package->name }}</a>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600">
                                        {{ $row['policy']->action->label() }}
                                        @if ($row['policy']->mode !== \App\Enums\PolicyMode::Enforce)
                                            <span class="ml-1 text-xs text-slate-400">({{ $row['policy']->mode->label() }})</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500 font-mono text-[13px]">
                                        {{ $row['installed_version'] ?? '—' }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="pd-badge {{ $badge }}"><span class="pd-dot"></span>{{ str($row['status'])->replace('_', ' ')->ucfirst() }}</span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-slate-600">{{ $row['reason'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">
                                    No software policies target this machine's project, so nothing is being enforced here.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Recent deployments --}}
            <div class="pd-card">
                <div class="flex items-center justify-between px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Recent deployments</h3>
                    <a href="{{ route('deployments.index') }}" class="text-sm pd-action">View all →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="pd-th">Package</th>
                                <th class="pd-th">Action</th>
                                <th class="pd-th">Status</th>
                                <th class="pd-th">Attempts</th>
                                <th class="pd-th">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($recentJobs as $job)
                                <tr>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <a href="{{ route('packages.show', $job->package) }}" class="pd-link text-sm">{{ $job->package->name }}</a>
                                        @if ($label = $job->versionLabel())
                                            <span class="block text-xs text-slate-400 font-mono">{{ $label }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600">
                                        {{ $job->action->label() }}
                                        @if ($job->repeat_count > 1)
                                            <span class="ml-1 text-xs text-slate-400"
                                                  title="Requested {{ $job->repeat_count }} times — showing the latest">
                                                ×{{ $job->repeat_count }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        @php
                                            $badge = match ($job->status) {
                                                \App\Enums\JobStatus::Succeeded => 'pd-badge-green',
                                                \App\Enums\JobStatus::Failed => 'pd-badge-red',
                                                \App\Enums\JobStatus::Running => 'pd-badge-sky',
                                                \App\Enums\JobStatus::Blocked => 'pd-badge-amber',
                                                default => 'pd-badge-slate',
                                            };
                                        @endphp
                                        <span class="pd-badge {{ $badge }}"><span class="pd-dot"></span>{{ $job->status->label() }}</span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">{{ $job->attempts }}/{{ $job->max_attempts }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">{{ $job->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">No deployments yet — queue one above.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Deployment log: every attempt with the reason it ended that
                 way, and the agent's own output behind it. Collapsed by
                 default — it grows long and is a drill-down, not a summary. --}}
            <div class="pd-card" x-data="{ showLog: false }">
                <div class="px-6 py-5 flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Deployment log
                        <span class="ml-1 text-slate-400 font-normal normal-case">— {{ $jobLog->count() }} {{ Str::plural('attempt', $jobLog->count()) }} and why each ended that way</span>
                    </h3>
                    <button type="button" @click="showLog = !showLog"
                            class="shrink-0 inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-600">
                        <span x-text="showLog ? 'Hide log' : 'Show log'"></span>
                        <svg class="h-3.5 w-3.5 transition-transform" :class="showLog ? 'rotate-180' : ''"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                </div>
                <ul class="divide-y divide-slate-100" x-show="showLog" x-cloak style="display: none;">
                    @forelse ($jobLog as $job)
                        @php
                            $dot = match ($job->status) {
                                \App\Enums\JobStatus::Succeeded => 'bg-green-500',
                                \App\Enums\JobStatus::Failed    => 'bg-red-500',
                                \App\Enums\JobStatus::Running   => 'bg-sky-500',
                                \App\Enums\JobStatus::Blocked   => 'bg-amber-500',
                                default                         => 'bg-slate-300',
                            };
                        @endphp
                        <li class="px-6 py-3" x-data="{ open: false }">
                            <div class="flex items-start gap-3">
                                <span class="mt-1.5 h-1.5 w-1.5 rounded-full shrink-0 {{ $dot }}"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-700">
                                        <span class="font-medium">{{ $job->action->label() }}</span>
                                        {{ $job->package->name }}
                                        @if ($label = $job->versionLabel())
                                            <span class="text-xs text-slate-400 font-mono">({{ $label }})</span>
                                        @endif
                                    </p>
                                    <p class="text-sm text-slate-500">{{ $job->reasonLabel() }}</p>
                                    @if ($hint = $job->failureHint())
                                        <p class="text-xs text-slate-500 mt-0.5 bg-slate-50 border border-slate-200 rounded p-2">{{ $hint }}</p>
                                    @endif

                                    @if ($job->output_log || $job->exit_code !== null)
                                        <button type="button" @click="open = ! open"
                                                class="mt-1 text-xs pd-link" :aria-expanded="open ? 'true' : 'false'">
                                            <span x-text="open ? 'Hide agent output' : 'Show agent output'">Show agent output</span>
                                            @if ($job->exit_code !== null)
                                                <span class="text-slate-400 font-mono">· exit {{ $job->exit_code }}</span>
                                            @endif
                                        </button>
                                        <pre x-show="open" x-cloak x-collapse
                                             class="mt-2 bg-slate-900 text-slate-100 rounded-lg p-3 overflow-x-auto text-xs whitespace-pre-wrap break-words max-h-72">{{ $job->output_log ?: '(the agent reported no output)' }}</pre>
                                    @endif
                                </div>
                                <time class="text-xs text-slate-400 whitespace-nowrap shrink-0"
                                      datetime="{{ ($job->finished_at ?? $job->created_at)->toIso8601String() }}">
                                    {{ ($job->finished_at ?? $job->created_at)->diffForHumans() }}
                                </time>
                            </div>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-center text-slate-400">
                            Nothing has been deployed to this machine yet, so there is nothing to explain.
                        </li>
                    @endforelse
                </ul>
                @if ($jobLog->count() >= 30)
                    <p class="px-6 py-2 text-xs text-slate-400 border-t border-slate-100">
                        Showing the 30 most recent attempts —
                        <a href="{{ route('deployments.index') }}" class="pd-link">Deployments</a> has the full history.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
