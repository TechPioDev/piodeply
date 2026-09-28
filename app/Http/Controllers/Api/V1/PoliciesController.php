<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Resources\SoftwarePolicyResource;
use App\Models\SoftwarePolicy;
use App\Services\PolicyService;
use Illuminate\Http\Request;

class PoliciesController extends IntegrationController
{
    public function index(Request $request)
    {
        $this->requireAbility($request, 'read', Permission::PoliciesView->value);

        return SoftwarePolicyResource::collection(
            SoftwarePolicy::query()
                ->with(['project', 'package'])
                ->visibleTo($this->tenantId($request))
                ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
                ->orderBy('priority')
                ->paginate(min(100, (int) $request->integer('per_page', 25)))
        );
    }

    /** Single policy including its live compliance summary. */
    public function show(Request $request, SoftwarePolicy $policy, PolicyService $service)
    {
        $this->requireAbility($request, 'read', Permission::PoliciesView->value);
        abort_unless($request->user()->can('view', $policy), 404);

        $policy->compliance_summary = $service->complianceSummary($policy);

        return new SoftwarePolicyResource($policy->load(['project', 'package']));
    }
}
