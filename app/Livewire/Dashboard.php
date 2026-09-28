<?php

namespace App\Livewire;

use App\Enums\JobStatus;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Dashboard extends Component
{
    /**
     * Managed software whose reported version differs from the package's
     * pinned latest version. Only computable where a latest version is
     * pinned (binary packages); winget entries resolve latest at install.
     */

    private function licenseUsage(): int
    {
        return ComputerSoftware::query()
            ->join('packages', function ($join) {
                $join->on('packages.winget_id', '=', 'computer_software.name')
                    ->where('computer_software.source', 'winget');
            })
            ->whereIn('packages.license', ['Commercial', 'Trialware'])
            ->count();
    }

    /** @return list<array{name: string, online: int, offline: int}> */
    private function fleetByClient(): array
    {
        return Client::query()
            ->with(['projects' => fn ($q) => $q->withTrashed()])
            ->get()
            ->map(function (Client $client) {
                $computers = Computer::whereIn('project_id', $client->projects->pluck('id'));
                $online = (clone $computers)->online()->count();
                $total = $computers->count();

                return [
                    'name'    => $client->company_name,
                    'online'  => $online,
                    'offline' => $total - $online,
                    'total'   => $total,
                ];
            })
            ->filter(fn (array $row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->take(8)
            ->values()
            ->all();
    }

    /** @return list<array{day: string, label: string, succeeded: int, failed: int, other: int}> */
    private function deploymentsSeries(): array
    {
        $since = now()->subDays(13)->startOfDay();

        $jobs = DeploymentJob::query()
            ->where('created_at', '>=', $since)
            ->get(['created_at', 'status'])
            ->groupBy(fn (DeploymentJob $job) => $job->created_at->toDateString());

        return collect(range(13, 0))
            ->map(function (int $daysAgo) use ($jobs) {
                $date = now()->subDays($daysAgo);
                $day = $date->toDateString();
                $group = $jobs->get($day, collect());

                return [
                    'day'       => $day,
                    'label'     => $date->format('d M'),
                    'succeeded' => $group->where('status', JobStatus::Succeeded)->count(),
                    'failed'    => $group->where('status', JobStatus::Failed)->count(),
                    'other'     => $group->whereNotIn('status', [JobStatus::Succeeded, JobStatus::Failed])->count(),
                ];
            })
            ->all();
    }

    public function render()
    {
        // Client-bound users get their own portal view, scoped to their data.
        $tenantId = auth()->user()->tenantClientId();
        if ($tenantId !== null) {
            return $this->renderClientPortal($tenantId);
        }

        $fleetUpdates = app(\App\Services\FleetUpdateService::class);
        $updates = $fleetUpdates->pending();   // one pass; byPackage reuses it
        $fleetHealth = $this->fleetHealth();

        $stats = [
            'online'    => Computer::online()->count(),
            'offline'   => Computer::offline()->count(),
            'pending'   => DeploymentJob::whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
            // Folded to one row per task: an old failed attempt later
            // superseded by a successful retry is history, not a currently
            // failing machine — counting the raw row would overstate it,
            // exactly the gap fixed on the Deployments queue's own cards.
            'failed'    => DeploymentJob::onlyLatestPerTask()->where('status', JobStatus::Failed)->count(),
            'outdated'  => $updates->count(),
            // One update across sixty machines is one decision, not sixty.
            'outdated_machines' => $updates->pluck('computer_id')->unique()->count(),
            // Machines whose PioDeploy agent itself is behind the latest build.
            'outdated_agents' => Computer::agentOutdated()->count(),
            'latest_agent'    => Computer::latestAgentVersion(),
            'software'  => ComputerSoftware::count(),
            'not_ready' => app(\App\Services\ReadinessService::class)->notReadyCount(),
            'licenses'  => $this->licenseUsage(),
            'clients'   => Client::count(),
            'projects'  => Project::count(),
            'packages'  => Package::active()->count(),
            'today'     => Activity::whereDate('created_at', Carbon::today())->count(),
            'health'    => $fleetHealth['avg'],
        ];

        return view('livewire.dashboard', [
            'stats'         => $stats,
            'updatesByPackage' => $fleetUpdates->byPackage(pending: $updates)->take(6),
            'fleetByClient' => $this->fleetByClient(),
            'series'        => $this->deploymentsSeries(),
            'activity'      => Activity::with('causer')->latest()->limit(8)->get(),
            'browserPolicySummary' => app(\App\Services\BrowserPolicyService::class)->fleetSummary(),
            'deviceHealth'  => [
                'total'    => array_sum($fleetHealth['tiers']),
                'tiers'    => $fleetHealth['tiers'],
                'hardware' => $fleetHealth['hardware'],
                'issues'   => $this->deviceHealthIssues(),
            ],
        ])->layout('layouts.app');
    }

    private function renderClientPortal(int $clientId)
    {
        // A Client-role account with no client binding (tenant id 0) has
        // nothing to show — a friendly notice beats a 404.
        $client = Client::find($clientId);
        if ($client === null) {
            return view('livewire.client-unbound')->layout('layouts.app');
        }

        $projects = Project::where('client_id', $clientId)
            // Per-project confinement: an assigned technician's dashboard
            // covers exactly their projects.
            ->when(auth()->user()->visibleProjectIds() !== null,
                fn ($q) => $q->whereIn('id', auth()->user()->visibleProjectIds()))
            ->withCount('computers')
            ->orderBy('name')
            ->get();

        $computers = Computer::whereIn('project_id', $projects->pluck('id'));

        // pending($clientId) alone would out-run a project-confined
        // technician's visibility — narrow to the same computer set the
        // rest of this portal is already scoped to.
        $confinedIds = (clone $computers)->pluck('id')->all();
        $updates = app(\App\Services\FleetUpdateService::class)
            ->pending($clientId)
            ->whereIn('computer_id', $confinedIds);

        // ReadinessService::notReadyCount($clientId) counts the whole client,
        // which would outrun a project-confined technician's visibility —
        // same reasoning as $confinedIds above. Filtering the already-scoped
        // $computers set keeps it exact.
        $readiness = app(\App\Services\ReadinessService::class);
        $notReady = (clone $computers)->whereNotNull('environment')->get()
            ->filter(fn (Computer $c) => ! $readiness->isReady($c))
            ->count();

        $stats = [
            'online'  => (clone $computers)->online()->count(),
            'offline' => (clone $computers)->offline()->count(),
            'pending' => DeploymentJob::whereIn('computer_id', (clone $computers)->pluck('id'))
                ->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
            // Folded to one row per task -- same reasoning as the staff
            // dashboard's identical fix: an old failed attempt a later retry
            // superseded is not a currently failing machine.
            'failed'  => DeploymentJob::whereIn('computer_id', (clone $computers)->pluck('id'))
                ->onlyLatestPerTask()->where('status', JobStatus::Failed)->count(),
            'health'  => $this->fleetHealth((clone $computers)->pluck('id')->all())['avg'],
            // The staff dashboard's "Updates available" tile, scoped to this
            // client — previously the portal had no software-update
            // visibility at all, only agent/deployment state.
            'outdated'          => $updates->count(),
            'outdated_machines' => $updates->pluck('computer_id')->unique()->count(),
            // The staff dashboard's "Not ready to deploy" tile — the
            // Enrolment page already promises this shows per-machine, but
            // the portal had no fleet-wide count of it at all.
            'not_ready' => $notReady,
        ];

        return view('livewire.client-dashboard', [
            'client'     => $client,
            'projects'   => $projects,
            'computers'  => (clone $computers)->orderBy('hostname')->limit(10)->get(),
            'stats'      => $stats,
            'recentJobs' => DeploymentJob::with(['computer', 'package'])
                ->whereIn('computer_id', (clone $computers)->pluck('id'))
                ->orderByDesc('id')->limit(8)->get(),
        ])->layout('layouts.app');
    }

    /**
     * The fleet's average healthScore() — one number for "how are we
     * doing", with the weakest machine named so the number is actionable —
     * plus, in the same pass, two breakdowns the device-health summary
     * needs: how many machines fall in each health tier (the same
     * good/needs-attention/unhealthy bands the fleet health PDF report
     * already uses — score ≥90 / ≥70 / below), and how many are virtual vs
     * physical hardware. One query however large the fleet, whichever
     * caller needs which part of it.
     *
     * @param  list<int>|null  $computerIds  confine to these machines (client portal)
     * @return array{
     *   avg: array{avg: int, count: int, worst: string, worst_score: int}|null,
     *   tiers: array{healthy: int, needs_attention: int, unhealthy: int, unknown: int},
     *   hardware: array{physical: int, virtual: int, unknown: int},
     * }
     */
    private function fleetHealth(?array $computerIds = null): array
    {
        $empty = ['avg' => null, 'tiers' => ['healthy' => 0, 'needs_attention' => 0, 'unhealthy' => 0, 'unknown' => 0], 'hardware' => ['physical' => 0, 'virtual' => 0, 'unknown' => 0]];

        $computers = Computer::query()
            ->when($computerIds !== null, fn ($q) => $q->whereIn('id', $computerIds))
            // project:client_id — healthScore()'s browser check needs the
            // client id per row; without this it is one query per computer.
            ->with('project:id,client_id')
            ->withCount([
                'software as updates_available_count' => fn ($q) => $q->whereNotNull('available_version'),
                'deploymentJobs as failed_jobs_count' => fn ($q) => $q->where('status', JobStatus::Failed),
            ])
            ->get();

        if ($computers->isEmpty()) {
            return $empty;
        }

        // One query for whichever clients are represented here, not one per
        // computer — see Computer::healthScore()'s $fleetBrowserLatest param.
        $fleetBrowserLatest = app(\App\Services\BrowserVersionService::class)
            ->fleetLatestByClient($computers->pluck('id')->all());

        $tiers = $empty['tiers'];
        $hardware = $empty['hardware'];

        $scored = $computers->map(function (Computer $c) use ($fleetBrowserLatest, &$tiers, &$hardware) {
            $hardware[$c->hardwareType()]++;

            $score = $c->healthScore($fleetBrowserLatest[$c->project->client_id] ?? null)['score'];
            // Never reported at all is a different situation from reporting
            // and scoring badly — it gets its own "unknown" tier rather than
            // being folded into "unhealthy" on the strength of one score.
            $tiers[$c->last_seen_at === null ? 'unknown' : ($score >= 90 ? 'healthy' : ($score >= 70 ? 'needs_attention' : 'unhealthy'))]++;

            return ['hostname' => $c->hostname, 'score' => $score];
        });
        $worst = $scored->sortBy('score')->first();

        return [
            'avg' => [
                'avg'         => (int) round($scored->avg('score')),
                'count'       => $scored->count(),
                'worst'       => $worst['hostname'],
                'worst_score' => $worst['score'],
            ],
            'tiers'    => $tiers,
            'hardware' => $hardware,
        ];
    }

    /**
     * The drill-down rows for the device-health summary — only signals
     * PioDeploy actually tracks (no antivirus, vulnerability scanning, MDM,
     * or hypervisor host monitoring exist in this app), each linking to the
     * same filtered list a fleet operator would already reach for.
     *
     * @return list<array{key: string, label: string, count: int, severity: 'unhealthy'|'needs_attention', route: string, params: array}>
     */
    private function deviceHealthIssues(): array
    {
        return [
            [
                'key' => 'offline', 'label' => 'Machines offline',
                'count' => Computer::offline()->count(), 'severity' => 'unhealthy',
                'route' => 'computers.index', 'params' => ['connectivity' => 'offline'],
            ],
            [
                'key' => 'failed', 'label' => 'Failed deployments',
                'count' => DeploymentJob::onlyLatestPerTask()->where('status', JobStatus::Failed)->count(), 'severity' => 'unhealthy',
                'route' => 'deployments.index', 'params' => ['status' => JobStatus::Failed->value],
            ],
            [
                'key' => 'software_outdated', 'label' => 'Software updates pending',
                'count' => Computer::softwareStatus('outdated')->count(), 'severity' => 'needs_attention',
                'route' => 'computers.index', 'params' => ['softwareStatus' => 'outdated'],
            ],
            [
                'key' => 'agent_outdated', 'label' => 'Agents outdated',
                'count' => Computer::agentOutdated()->count(), 'severity' => 'needs_attention',
                'route' => 'computers.index', 'params' => ['agentStatus' => 'outdated'],
            ],
            [
                'key' => 'not_ready', 'label' => 'Not ready to deploy',
                'count' => app(\App\Services\ReadinessService::class)->notReadyCount(), 'severity' => 'needs_attention',
                'route' => 'computers.index', 'params' => [],
            ],
        ];
    }
}
