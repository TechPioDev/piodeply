<div wire:poll.30s>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-slate-900 leading-tight">Dashboard</h2>
            <p class="text-sm text-slate-500 mt-0.5">Fleet, deployments and activity at a glance</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">

            {{-- Primary tiles: an icon to read at a glance, a number that
                 counts up on load, and a lift on hover. The Failed tile draws
                 the eye in red only when something is actually wrong. --}}
            @php
                $health = $stats['health'];
                $tiles = [
                    ['route' => 'reports.computers', 'value' => $health['avg'] ?? 0, 'label' => 'Fleet health',
                     'sub' => $health === null ? 'no machines yet'
                            : ($health['avg'] >= 90 ? 'average of '.$health['count'].' '.Str::plural('machine', $health['count'])
                            : 'weakest: '.$health['worst'].' ('.$health['worst_score'].')'),
                     'tone' => $health === null ? 'muted' : ($health['avg'] >= 90 ? 'emerald' : ($health['avg'] >= 70 ? 'amber' : 'red')),
                     'icon' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
                    ['route' => 'computers.index', 'value' => $stats['online'], 'label' => 'Computers online',
                     'sub' => 'heartbeat within '.round(\App\Models\Computer::onlineThreshold() / 60).' min',
                     'tone' => 'emerald', 'icon' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>'],
                    ['route' => 'computers.index', 'value' => $stats['offline'], 'label' => 'Computers offline',
                     'sub' => 'no recent heartbeat',
                     'tone' => $stats['offline'] > 0 ? 'slate' : 'muted', 'icon' => '<path d="M3 3l18 18M9 5h9a2 2 0 0 1 2 2v9m-2 2H6a2 2 0 0 1-2-2V7"/><path d="M8 21h8M12 17v4"/>'],
                    ['route' => 'deployments.index', 'value' => $stats['pending'], 'label' => 'Jobs in flight',
                     'sub' => 'pending / running / blocked',
                     'tone' => $stats['pending'] > 0 ? 'sky' : 'muted', 'icon' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>'],
                    ['route' => 'deployments.index', 'value' => $stats['failed'], 'label' => 'Failed jobs',
                     'sub' => 'out of retries — need attention',
                     'tone' => $stats['failed'] > 0 ? 'red' : 'muted', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>'],
                ];
                $tones = [
                    'emerald' => ['num' => 'text-emerald-600', 'ic' => 'text-emerald-600 bg-emerald-50'],
                    'amber'   => ['num' => 'text-amber-600',   'ic' => 'text-amber-600 bg-amber-50'],
                    'slate'   => ['num' => 'text-slate-700',   'ic' => 'text-slate-500 bg-slate-100'],
                    'sky'     => ['num' => 'text-sky-600',     'ic' => 'text-sky-600 bg-sky-50'],
                    'red'     => ['num' => 'text-red-600',     'ic' => 'text-red-600 bg-red-50'],
                    'muted'   => ['num' => 'text-slate-300',   'ic' => 'text-slate-300 bg-slate-50'],
                ];
            @endphp
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                @foreach ($tiles as $tile)
                    @php $t = $tones[$tile['tone']]; @endphp
                    <a href="{{ route($tile['route']) }}"
                       class="pd-card p-4 flex items-start justify-between gap-3 transition-all duration-200
                              hover:-translate-y-0.5 hover:shadow-md hover:border-teal-300"
                       @if ($tile['tone'] === 'red') style="border-color:#fecaca" @endif>
                        <div>
                            <p class="text-2xl font-bold leading-tight {{ $t['num'] }}"
                               x-data="{ n: 0 }" x-init="$nextTick(() => { let t = {{ (int) $tile['value'] }}; if (t === 0) return; let s = performance.now(); let f = (now) => { let p = Math.min(1, (now - s) / 700); n = Math.round(t * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(f); }; requestAnimationFrame(f); })"
                               x-text="n">{{ $tile['value'] }}</p>
                            <p class="text-sm font-semibold text-slate-700 mt-1">{{ $tile['label'] }}</p>
                            <p class="text-xs text-slate-400">{{ $tile['sub'] }}</p>
                        </div>
                        <span class="grid place-items-center h-9 w-9 rounded-lg shrink-0 {{ $t['ic'] }}">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $tile['icon'] !!}</svg>
                        </span>
                    </a>
                @endforeach
            </div>

            {{-- Secondary tiles --}}
            <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                <a href="{{ route('computers.index', ['softwareStatus' => 'outdated']) }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold {{ $stats['outdated'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $stats['outdated'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Updates available</p>
                    @if ($stats['outdated'] > 0)
                        <p class="text-[11px] text-slate-400 mt-0.5">
                            on {{ $stats['outdated_machines'] }} {{ Str::plural('machine', $stats['outdated_machines']) }}
                        </p>
                    @endif
                </a>
                <a href="{{ route('computers.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold {{ $stats['not_ready'] > 0 ? 'text-red-600' : 'text-slate-300' }}">{{ $stats['not_ready'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Not ready to deploy</p>
                    @if ($stats['not_ready'] > 0)
                        <p class="text-[11px] text-slate-400 mt-0.5">winget or a runtime is missing</p>
                    @endif
                </a>
                <a href="{{ route('computers.index', ['agentStatus' => 'outdated']) }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold {{ $stats['outdated_agents'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $stats['outdated_agents'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Agents outdated</p>
                    @if ($stats['outdated_agents'] > 0)
                        <p class="text-[11px] text-slate-400 mt-0.5">behind {{ $stats['latest_agent'] }} — self-update pending</p>
                    @else
                        <p class="text-[11px] text-slate-400 mt-0.5">all on {{ $stats['latest_agent'] }}</p>
                    @endif
                </a>
                <div class="pd-card p-4">
                    <p class="text-xl font-bold text-slate-700">{{ number_format($stats['software']) }}</p>
                    <p class="text-xs font-semibold text-slate-600">Software detected</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-xl font-bold text-slate-700">{{ $stats['licenses'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Commercial installs</p>
                </div>
                <a href="{{ route('clients.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold text-slate-700">{{ $stats['clients'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Clients</p>
                </a>
                <a href="{{ route('projects.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold text-slate-700">{{ $stats['projects'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">{{ project_terms() }}</p>
                </a>
                <a href="{{ route('packages.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold text-slate-700">{{ $stats['packages'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Active packages</p>
                </a>
            </div>

            {{-- Device health summary: only signals PioDeploy actually tracks
                 (this app has no antivirus, vulnerability scanner, MDM, or
                 hypervisor-host monitoring, so those rows are left out
                 rather than faked). Hardware mix and every issue count are
                 clickable straight through to the filtered list. --}}
            @if ($deviceHealth['total'] > 0)
                @php
                    $dh = $deviceHealth;
                    $dhTotal = $dh['total'];
                    $pctHealthy = $dhTotal > 0 ? round($dh['tiers']['healthy'] / $dhTotal * 100) : 0;
                    $tierBar = [
                        ['label' => 'Healthy', 'value' => $dh['tiers']['healthy'], 'bar' => 'bg-emerald-500', 'dot' => 'bg-emerald-500'],
                        ['label' => 'Needs attention', 'value' => $dh['tiers']['needs_attention'], 'bar' => 'bg-amber-400', 'dot' => 'bg-amber-400'],
                        ['label' => 'Unhealthy', 'value' => $dh['tiers']['unhealthy'], 'bar' => 'bg-red-500', 'dot' => 'bg-red-500'],
                        ['label' => 'Unknown', 'value' => $dh['tiers']['unknown'], 'bar' => 'bg-slate-300', 'dot' => 'bg-slate-300'],
                    ];
                    $issuesBySeverity = collect($dh['issues'])->filter(fn ($i) => $i['count'] > 0)->groupBy('severity');
                    $severityLabels = ['unhealthy' => 'Unhealthy', 'needs_attention' => 'Needs attention'];
                    $severityColor = ['unhealthy' => 'text-red-600', 'needs_attention' => 'text-amber-600'];
                @endphp
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Device health summary</h3>
                        <a href="{{ route('reports.computers') }}" class="text-xs text-teal-600 hover:underline">Full fleet health report →</a>
                    </div>

                    <p class="text-2xl font-bold text-slate-900 mt-2">{{ $pctHealthy }}% healthy</p>
                    <div class="flex h-3 rounded-full overflow-hidden bg-slate-100 mt-3" role="img"
                         aria-label="Device health: {{ collect($tierBar)->map(fn ($r) => "{$r['label']} {$r['value']}")->join(', ') }}">
                        @foreach ($tierBar as $seg)
                            @if ($seg['value'] > 0)
                                <span class="{{ $seg['bar'] }} h-full border-r-2 border-white last:border-r-0"
                                      style="width: {{ $seg['value'] / $dhTotal * 100 }}%"></span>
                            @endif
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-x-5 gap-y-1.5 mt-3 text-xs text-slate-500">
                        @foreach ($tierBar as $seg)
                            <span class="flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full {{ $seg['dot'] }}"></span>{{ $seg['label'] }} ({{ $seg['value'] }})
                            </span>
                        @endforeach
                    </div>

                    {{-- Hardware mix — inferred from reported manufacturer/model text; PioDeploy has no hypervisor API to confirm it. --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-5">
                        <a href="{{ route('computers.index', ['hardwareType' => 'physical']) }}" class="pd-card p-3 hover:border-teal-300 transition-colors">
                            <p class="text-lg font-bold text-slate-800">{{ $dh['hardware']['physical'] }}</p>
                            <p class="text-xs text-slate-500">Physical machines</p>
                        </a>
                        <a href="{{ route('computers.index', ['hardwareType' => 'virtual']) }}" class="pd-card p-3 hover:border-teal-300 transition-colors">
                            <p class="text-lg font-bold text-slate-800">{{ $dh['hardware']['virtual'] }}</p>
                            <p class="text-xs text-slate-500">Virtual machines</p>
                        </a>
                        @if ($dh['hardware']['unknown'] > 0)
                            <div class="pd-card p-3">
                                <p class="text-lg font-bold text-slate-400">{{ $dh['hardware']['unknown'] }}</p>
                                <p class="text-xs text-slate-500">Hardware type unknown</p>
                            </div>
                        @endif
                    </div>

                    {{-- Issues list, grouped by severity, each row a live drill-down link. --}}
                    <div class="mt-5 pt-4 border-t border-slate-100">
                        <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Device health issues</h4>
                        @if ($issuesBySeverity->isEmpty())
                            <p class="text-sm text-emerald-600 mt-2">No open device-health issues.</p>
                        @else
                            @foreach (['unhealthy', 'needs_attention'] as $sev)
                                @if ($issuesBySeverity->has($sev))
                                    <p class="text-[11px] font-semibold {{ $severityColor[$sev] }} uppercase tracking-wide mt-3 mb-1">{{ $severityLabels[$sev] }}</p>
                                    <ul class="divide-y divide-slate-100">
                                        @foreach ($issuesBySeverity[$sev] as $issue)
                                            <li>
                                                <a href="{{ route($issue['route'], $issue['params']) }}"
                                                   class="flex items-center justify-between py-1.5 text-sm text-slate-700 hover:text-teal-600 transition-colors">
                                                    <span>{{ $issue['label'] }}</span>
                                                    <span class="font-mono font-semibold {{ $severityColor[$sev] }}">{{ $issue['count'] }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endforeach
                        @endif
                    </div>
                </div>
            @endif

            {{-- What is behind, folded by package: one update across sixty
                 machines is one decision, not sixty rows. --}}
            @if ($updatesByPackage->isNotEmpty())
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                            Updates waiting
                            <span class="ml-1 text-slate-400 font-normal normal-case">— newer versions the fleet has not taken</span>
                        </h3>
                        <a href="{{ route('policies.index') }}" class="text-xs text-teal-600 hover:underline">Policies →</a>
                    </div>

                    <div class="overflow-x-auto -mx-6">
                        <table class="min-w-full divide-y divide-slate-100">
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($updatesByPackage as $update)
                                    <tr>
                                        <td class="px-6 py-2.5 text-sm text-slate-800">{{ $update['name'] }}</td>
                                        <td class="px-6 py-2.5 whitespace-nowrap text-xs font-mono text-slate-500">
                                            {{ $update['from'] }}
                                            <span class="text-amber-600">→ {{ $update['to'] }}</span>
                                        </td>
                                        <td class="px-6 py-2.5 whitespace-nowrap text-sm text-slate-500 text-right">
                                            {{ $update['machines'] }} {{ Str::plural('machine', $update['machines']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="text-xs text-slate-400 mt-3">
                        Reported by each machine's own package manager. Nothing is installed until you say so —
                        an <strong>Update</strong> policy does it on a schedule, or deploy one from a machine's page.
                    </p>
                </div>
            @endif

            {{-- Browser policy compliance --}}
            @if (($browserPolicySummary['policies'] ?? 0) > 0)
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Browser policy compliance</h3>
                        <a href="{{ route('browser-policies.index') }}" class="text-xs text-teal-600 hover:underline">View policies</a>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 text-center">
                        <div><p class="text-2xl font-bold text-slate-800">{{ $browserPolicySummary['target'] }}</p>
                            <p class="text-xs uppercase tracking-wider text-slate-400">Devices targeted</p></div>
                        <div><p class="text-2xl font-bold text-green-600">{{ $browserPolicySummary['protected'] }}</p>
                            <p class="text-xs uppercase tracking-wider text-slate-400">Protected</p></div>
                        <div><p class="text-2xl font-bold text-red-600">{{ $browserPolicySummary['non_compliant'] }}</p>
                            <p class="text-xs uppercase tracking-wider text-slate-400">Non-compliant</p></div>
                        <div><p class="text-2xl font-bold text-blue-600">{{ $browserPolicySummary['pending'] }}</p>
                            <p class="text-xs uppercase tracking-wider text-slate-400">Pending</p></div>
                        <div><p class="text-2xl font-bold text-amber-600">{{ $browserPolicySummary['unsupported'] }}</p>
                            <p class="text-xs uppercase tracking-wider text-slate-400">Unsupported</p></div>
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                {{-- Fleet by client: stacked horizontal bars --}}
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Fleet by client</h3>
                        <div class="flex items-center gap-3 text-xs text-slate-500">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-teal-600"></span>Online</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-300"></span>Offline</span>
                        </div>
                    </div>
                    @if (count($fleetByClient) === 0)
                        <p class="text-sm text-slate-400 py-6 text-center">No enrolled computers yet.</p>
                    @else
                        @php $fleetMax = max(1, max(array_column($fleetByClient, 'total'))); @endphp
                        <div class="space-y-2.5 mt-3" role="img"
                             aria-label="Computers per client: {{ collect($fleetByClient)->map(fn ($r) => "{$r['name']} {$r['online']} online, {$r['offline']} offline")->join('; ') }}">
                            @foreach ($fleetByClient as $row)
                                <div class="grid grid-cols-[8rem_1fr_3.5rem] items-center gap-3 text-xs">
                                    <span class="text-slate-500 truncate text-right" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                    <span class="flex h-3.5 rounded-full bg-slate-100 overflow-hidden">
                                        @if ($row['online'] > 0)
                                            <span class="bg-teal-600 h-full" style="width: {{ $row['online'] / $fleetMax * 100 }}%"></span>
                                        @endif
                                        @if ($row['offline'] > 0)
                                            <span class="bg-slate-300 h-full border-l-2 border-white" style="width: {{ $row['offline'] / $fleetMax * 100 }}%"></span>
                                        @endif
                                    </span>
                                    <span class="font-mono text-slate-600">{{ $row['online'] }}/{{ $row['total'] }}</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="text-xs text-slate-400 mt-3">online / total per client</p>
                    @endif
                </div>

                {{-- Deployments last 14 days: stacked columns --}}
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Deployments — last 14 days</h3>
                        <div class="flex items-center gap-3 text-xs text-slate-500">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-teal-600"></span>Succeeded</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-400"></span>Failed</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-300"></span>Other</span>
                        </div>
                    </div>
                    @php $seriesMax = max(1, max(array_map(fn ($d) => $d['succeeded'] + $d['failed'] + $d['other'], $series))); @endphp
                    <div class="flex items-end gap-1.5 h-36 mt-4" role="img"
                         aria-label="Deployment jobs per day: {{ collect($series)->map(fn ($d) => "{$d['label']}: {$d['succeeded']} succeeded, {$d['failed']} failed, {$d['other']} other")->join('; ') }}">
                        @foreach ($series as $day)
                            @php $dayTotal = $day['succeeded'] + $day['failed'] + $day['other']; @endphp
                            <div class="flex-1 flex flex-col justify-end h-full group relative"
                                 title="{{ $day['label'] }}: {{ $day['succeeded'] }} ok · {{ $day['failed'] }} failed · {{ $day['other'] }} other">
                                @if ($dayTotal === 0)
                                    <div class="bg-slate-100 rounded-sm" style="height: 3px"></div>
                                @else
                                    @if ($day['other'] > 0)
                                        <div class="bg-slate-300 rounded-t-sm" style="height: {{ $day['other'] / $seriesMax * 100 }}%"></div>
                                    @endif
                                    @if ($day['failed'] > 0)
                                        <div class="bg-red-400 {{ $day['other'] === 0 ? 'rounded-t-sm' : '' }} border-t border-white" style="height: {{ $day['failed'] / $seriesMax * 100 }}%"></div>
                                    @endif
                                    @if ($day['succeeded'] > 0)
                                        <div class="bg-teal-600 {{ $day['failed'] === 0 && $day['other'] === 0 ? 'rounded-t-sm' : '' }} border-t border-white" style="height: {{ $day['succeeded'] / $seriesMax * 100 }}%"></div>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-between text-[10px] text-slate-400 mt-1.5">
                        <span>{{ $series[0]['label'] }}</span>
                        <span>{{ $series[count($series) - 1]['label'] }}</span>
                    </div>
                </div>
            </div>

            {{-- Recent activity — collapsed by default; it is reference, not
                 the headline, so it does not compete with the fleet at a glance. --}}
            <div class="pd-card p-6" x-data="{ open: false }">
                <button type="button" @click="open = ! open"
                        class="w-full flex items-center justify-between gap-3 text-left"
                        :aria-expanded="open ? 'true' : 'false'">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Recent activity
                        <span class="ml-1 text-slate-400 font-normal normal-case">({{ $stats['today'] }} today)</span>
                    </h3>
                    <span class="flex items-center gap-1 text-xs text-slate-400">
                        <span x-text="open ? 'Hide' : 'Show'">Show</span>
                        <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-90'"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </span>
                </button>

                <div x-show="open" x-collapse x-cloak>
                    @if ($activity->isEmpty())
                        <p class="text-sm text-slate-400 mt-3">Nothing recorded yet.</p>
                    @else
                        <ul class="divide-y divide-slate-100 mt-3">
                            @foreach ($activity as $entry)
                                <li class="py-2 flex items-center justify-between gap-3 text-sm">
                                    <span class="text-slate-700">
                                        <span class="pd-badge pd-badge-slate mr-1.5">{{ $entry->log_name }}</span>
                                        {{ str_replace('_', ' ', $entry->description) }}
                                        @if ($entry->causer)
                                            <span class="text-slate-400">by {{ $entry->causer->name }}</span>
                                        @endif
                                    </span>
                                    <span class="text-slate-400 whitespace-nowrap text-xs">{{ $entry->created_at->diffForHumans(short: true) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
