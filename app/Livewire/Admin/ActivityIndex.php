<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Livewire\Concerns\WithCompactPagination;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail: every logged action (RBAC changes, policy edits,
 * logins, impersonation, entity changes) in one filterable stream.
 */
class ActivityIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public string $logFilter = '';

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'logFilter'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Splits one entry's properties into Previous Value / New Value where
     * the shape actually supports it, instead of a raw JSON dump for every
     * row alike. Two real shapes exist in this app today: Spatie's own
     * automatic model-diff ('attributes' + 'old', from every LogsActivity
     * model — packages, policies, computers, clients...) and the manual
     * 'from'/'to' convention a few hand-written entries already use
     * (role_assigned, client_assigned). Anything else has no clean before
     * and after to show — a 'created' row has no "previous", and a custom
     * one-off payload (signup_id, template name, counts) is not a value
     * change at all — so New Value falls back to the properties as-is
     * rather than inventing a split that is not really there.
     *
     * @return array{previous: ?string, new: ?string}
     */
    public function valuesFor(Activity $activity): array
    {
        $props = $activity->properties;

        if ($props->has('old') && $props->has('attributes')) {
            return [
                'previous' => $this->formatFields($props->get('old')),
                'new'      => $this->formatFields($props->get('attributes')),
            ];
        }

        if ($props->has('from') && $props->has('to')) {
            return [
                'previous' => $this->formatValue($props->get('from')),
                'new'      => $this->formatValue($props->get('to')),
            ];
        }

        if ($props->has('attributes') && ! $props->has('old')) {
            // A 'created' event: nothing existed before this.
            return ['previous' => null, 'new' => $this->formatFields($props->get('attributes'))];
        }

        if ($props->has('old') && ! $props->has('attributes')) {
            // A 'deleted' event: this is what it looked like right before
            // it stopped existing — that belongs under Previous, not New.
            return ['previous' => $this->formatFields($props->get('old')), 'new' => null];
        }

        if ($props->isEmpty()) {
            return ['previous' => null, 'new' => null];
        }

        // No before/after shape in this payload — show it whole rather than
        // guessing which half is "previous" and which is "new".
        return ['previous' => null, 'new' => $this->formatValue($props->toArray())];
    }

    private function formatFields(mixed $fields): ?string
    {
        if (! is_array($fields) || $fields === []) {
            return null;
        }

        return collect($fields)
            ->map(fn ($value, $key) => is_string($key) ? "{$key}: ".$this->formatValue($value) : $this->formatValue($value))
            ->implode(', ');
    }

    private function formatValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '—',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }

    public function render()
    {
        abort_unless(auth()->user()->can(Permission::ActivityView->value), 403);

        $activities = Activity::query()
            ->with('causer')
            // Tenant view: only actions taken BY their own team — never
            // platform staff activity, impersonation, or other tenants.
            ->when(auth()->user()->tenantClientId() !== null, fn ($q) => $q->whereHasMorph(
                'causer', [\App\Models\User::class],
                fn ($c) => $c->where('client_id', auth()->user()->tenantClientId())))
            ->when($this->logFilter !== '', fn ($q) => $q->where('log_name', $this->logFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('description', 'like', "%{$this->search}%")
                ->orWhereHasMorph('causer', [\App\Models\User::class], fn ($c) => $c
                    ->where('name', 'like', "%{$this->search}%"))))
            ->latest()
            ->paginate(25);

        return view('livewire.admin.activity-index', [
            'activities' => $activities,
            'logNames'   => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name'),
        ])->layout('layouts.app');
    }
}
