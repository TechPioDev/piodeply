<?php

namespace App\Repositories\Eloquent;

use App\Models\Computer;
use App\Repositories\Contracts\ComputerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentComputerRepository extends BaseRepository implements ComputerRepositoryInterface
{
    public function __construct(Computer $model)
    {
        parent::__construct($model);
    }

    public function searchPaginated(
        string $search = '',
        ?int $projectId = null,
        ?int $clientId = null,
        ?bool $online = null,
        bool $withTrashed = false,
        int $perPage = 15,
        string $agentStatus = '',
        ?array $allowedProjectIds = null,
        string $softwareStatus = '',
        string $hardwareType = '',
    ): LengthAwarePaginator {
        return $this->query()
            ->with('project.client')
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            // Per-project confinement for assigned technicians.
            ->when($allowedProjectIds !== null, fn ($q) => $q->whereIn('project_id', $allowedProjectIds))
            ->when($clientId !== null, fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->withTrashed()->where('client_id', $clientId)
            ))
            ->when($online === true, fn ($q) => $q->online())
            ->when($online === false, fn ($q) => $q->offline())
            ->when($agentStatus === 'outdated', fn ($q) => $q->agentOutdated())
            ->when($agentStatus === 'current', fn ($q) => $q->where('agent_version', Computer::latestAgentVersion()))
            ->when($softwareStatus !== '', fn ($q) => $q->softwareStatus($softwareStatus))
            ->when($hardwareType !== '', fn ($q) => $q->hardwareType($hardwareType))
            ->orderBy('hostname')
            ->paginate($perPage);
    }

    public function findByAgentUuid(string $agentUuid, bool $withTrashed = false): ?Computer
    {
        return $this->query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->where('agent_uuid', $agentUuid)
            ->first();
    }

    public function restore(Computer $computer): bool
    {
        return (bool) $computer->restore();
    }
}
