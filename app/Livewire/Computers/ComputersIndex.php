<?php

namespace App\Livewire\Computers;

use App\Models\Computer;
use App\Repositories\Contracts\ComputerRepositoryInterface;
use App\Services\ComputerService;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\WithCompactPagination;

class ComputersIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public ?int $clientId = null;

    public ?int $projectId = null;

    public string $connectivity = ''; // '', 'online', 'offline'

    // Bound to the URL so the dashboard's "Agents outdated" card can deep-link
    // straight to the filtered list (?agentStatus=outdated).
    #[Url]
    public string $agentStatus = ''; // '', 'outdated', 'current'

    // '', 'outdated', 'uptodate', 'pending' — the catalogue software a
    // machine is running, not the agent itself. Bound to the URL for the
    // same reason as agentStatus: the summary cards below deep-link here.
    #[Url]
    public string $softwareStatus = '';

    // '', 'virtual', 'physical' — bound to the URL for the same reason:
    // the dashboard's device-health hardware-mix tiles deep-link here.
    #[Url]
    public string $hardwareType = '';

    public bool $showTrashed = false;

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'clientId', 'projectId', 'connectivity', 'agentStatus', 'softwareStatus', 'hardwareType', 'showTrashed'], true)) {
            $this->resetPage();
        }
    }

    public function delete(int $computerId, ComputerService $service): void
    {
        $computer = Computer::findOrFail($computerId);
        $this->authorize('delete', $computer);

        $service->delete($computer);
    }

    public function restore(int $computerId, ComputerService $service): void
    {
        $computer = Computer::withTrashed()->findOrFail($computerId);
        $this->authorize('restore', $computer);

        $service->restore($computer);
    }

    /**
     * Permanent removal, from the retired view only. The service enforces
     * the agent-first rule: a machine whose agent still checks in cannot be
     * deleted — retire it and uninstall the agent first.
     */
    public function forceDelete(int $computerId, ComputerService $service): void
    {
        $computer = Computer::withTrashed()->findOrFail($computerId);
        $this->authorize('forceDelete', $computer);

        try {
            $service->forceDelete($computer);
            session()->flash('status', "{$computer->hostname} permanently deleted.");
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * The first agent that can reliably update itself. Anything below this
     * generates its own update helper with the bug that stopped the swap, so
     * it can never move forward on its own — it needs one manual re-enrolment.
     */
    private const SELF_UPDATE_FLOOR = '1.4.13';

    /**
     * The figures above the table.
     *
     * Scoped exactly like the list itself — a client-bound user counts their
     * own machines and nobody else's, and staff are still limited to the
     * projects they can see. A headline number that ignored tenancy would
     * leak the size of other people's fleets.
     *
     * @return array{total:int, online:int, offline:int, outdated:int}
     */
    private function stats(?int $tenantId): array
    {
        // null means "not confined to specific projects" — staff, or a tenant
        // without a site-scoped role. Passing it to whereIn would be a bug.
        $allowed = auth()->user()->visibleProjectIds();

        $visible = Computer::query()
            ->when($allowed !== null, fn ($q) => $q->whereIn('project_id', $allowed))
            ->when($tenantId !== null, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('client_id', $tenantId)));

        $threshold = now()->subSeconds(
            (int) app(\App\Services\SettingsService::class)->get('agent.online_threshold_seconds', 300)
        );

        $total = (clone $visible)->count();
        $online = (clone $visible)->where('last_seen_at', '>=', $threshold)->count();

        return [
            'total'   => $total,
            'online'  => $online,
            'offline' => $total - $online,

            // Behind the published agent, but able to fetch it themselves at
            // the next check-in. Nothing to do.
            'update_available' => (clone $visible)
                ->whereNotNull('agent_version')
                ->where('agent_version', '>=', self::SELF_UPDATE_FLOOR)
                ->where('agent_version', '<', \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION)
                ->count(),

            // Too old to self-update: these carry the updater that could never
            // apply an update, so they sit there until someone re-enrols them
            // by hand. Counted apart because it is the only figure here that
            // asks the operator to do something.
            'stranded' => (clone $visible)
                ->where(fn ($q) => $q
                    ->whereNull('agent_version')
                    ->orWhere('agent_version', '<', self::SELF_UPDATE_FLOOR))
                ->count(),

            // The software row below counts MACHINES, not software instances
            // — this list is a list of machines, so that is the unit every
            // other card here already uses, and the one a click can filter
            // this same list down to.
            'software_total' => \App\Models\ComputerSoftware::whereIn(
                'computer_id', (clone $visible)->pluck('id')
            )->count(),
            'software_outdated' => (clone $visible)->softwareStatus('outdated')->count(),
            'software_uptodate' => (clone $visible)->softwareStatus('uptodate')->count(),
            'software_pending'  => (clone $visible)->softwareStatus('pending')->count(),
        ];
    }

    public function render(ComputerRepositoryInterface $computers)
    {
        $this->authorize('viewAny', Computer::class);

        // Tenancy: client-bound users are locked to their own client.
        $tenantId = auth()->user()->tenantClientId();

        return view('livewire.computers.computers-index', [
            'computers' => $computers->searchPaginated(
                search: $this->search,
                projectId: $this->projectId,
                clientId: $tenantId ?? $this->clientId,
                online: $this->connectivity === '' ? null : $this->connectivity === 'online',
                withTrashed: $tenantId === null && $this->showTrashed,
                agentStatus: $this->agentStatus,
                allowedProjectIds: auth()->user()->visibleProjectIds(),
                softwareStatus: $this->softwareStatus,
                hardwareType: $this->hardwareType,
            ),
            'clients'  => $tenantId === null
                ? \App\Models\Client::orderBy('company_name')->get(['id', 'company_name'])
                : collect(),
            'projects' => \App\Models\Project::when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
                ->orderBy('name')->get(['id', 'name', 'client_id']),
            'isTenant' => $tenantId !== null,
            'stats'    => $this->stats($tenantId),
        ])->layout('layouts.app');
    }
}
