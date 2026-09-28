<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Computer extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'ring', 'agent_uuid', 'agent_version', 'last_seen_at',
        'hostname', 'serial_number', 'manufacturer', 'model',
        'os_name', 'os_version', 'windows_build',
        'cpu', 'ram_bytes', 'disk_total_bytes', 'disk_free_bytes',
        'public_ip', 'private_ip', 'mac_address',
        'secure_boot', 'tpm_enabled', 'tpm_version', 'environment',
    ];

    protected function casts(): array
    {
        return [
            'ring' => \App\Enums\DeploymentRing::class,
            'last_seen_at' => 'datetime',
            'reinstall_requested_at' => 'datetime',
            'uninstall_requested_at' => 'datetime',
            'agent_uninstalled_at' => 'datetime',
            'ram_bytes' => 'integer',
            'disk_total_bytes' => 'integer',
            'disk_free_bytes' => 'integer',
            'secure_boot' => 'boolean',
            'tpm_enabled' => 'boolean',
            'environment' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function deploymentJobs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeploymentJob::class);
    }

    public function software(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ComputerSoftware::class);
    }

    public function browserPolicyResults(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BrowserPolicyResult::class);
    }

    public function groups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ComputerGroup::class)->withTimestamps();
    }

    /** @return list<string> */
    public static function softwareSources(): array
    {
        return ComputerSoftware::SOURCES;
    }

    public function client(): HasOneThrough
    {
        return $this->hasOneThrough(
            Client::class,
            Project::class,
            'id',         // projects.id
            'id',         // clients.id
            'project_id', // computers.project_id
            'client_id',  // projects.client_id
        )->withTrashed('projects.deleted_at');
    }

    /* ---- Online status is derived from the last heartbeat ---- */

    public static function onlineThreshold(): int
    {
        return (int) app(\App\Services\SettingsService::class)
            ->get('agent.online_threshold_seconds');
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::onlineThreshold()));
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>', now()->subSeconds(self::onlineThreshold()));
    }

    public function scopeOffline(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('last_seen_at')
            ->orWhere('last_seen_at', '<=', now()->subSeconds(self::onlineThreshold())));
    }

    /**
     * One number an owner can read at a glance: 100 minus a deduction for
     * every unhealthy signal, with the reasons listed alongside so the
     * score is explainable, never mysterious.
     *
     * Uses withCount-loaded attributes (updates_available_count,
     * failed_jobs_count) when the caller preloaded them — report lists do —
     * and falls back to queries for a single machine.
     *
     * @return array{score: int, notes: list<string>}
     */
    /**
     * @param  array<string, string>|null  $fleetBrowserLatest  browser value => newest
     *         version seen on this computer's client's fleet. Pass this when
     *         scoring many computers of the same client in a loop (the
     *         dashboard average, the fleet health report, the PDF export) —
     *         BrowserVersionService::fleetLatestByClient() computes it once
     *         for everyone instead of once per row. Omitted, it is resolved
     *         for just this one computer — fine for a single computer-show page.
     */
    public function healthScore(?array $fleetBrowserLatest = null): array
    {
        $score = 100;
        $notes = [];
        $take = function (int $points, string $reason) use (&$score, &$notes): void {
            $score -= $points;
            $notes[] = "{$reason} (−{$points})";
        };

        if ($this->last_seen_at === null) {
            $take(40, 'Agent has never reported in');
        } elseif ($this->last_seen_at->lt(now()->subDay())) {
            $take(25, 'Offline for '.$this->last_seen_at->diffForHumans(null, true));
        }

        if ($this->disk_total_bytes && $this->disk_free_bytes !== null) {
            $freePercent = $this->disk_free_bytes / $this->disk_total_bytes * 100;
            if ($freePercent < 10) {
                $take(20, 'Critically low disk: '.round($freePercent).'% free');
            } elseif ($freePercent < 20) {
                $take(10, 'Low disk: '.round($freePercent).'% free');
            }
        }

        if ($this->isAgentOutdated()) {
            $take(10, "Agent {$this->agent_version} behind ".self::latestAgentVersion());
        }

        if ($this->secure_boot === false) {
            $take(10, 'Secure Boot disabled');
        }
        if ($this->tpm_enabled === false) {
            $take(10, 'TPM disabled');
        }

        $updates = $this->updates_available_count
            ?? $this->software()->whereNotNull('available_version')->count();
        if ($updates >= 10) {
            $take(15, "{$updates} software updates pending");
        } elseif ($updates >= 1) {
            $take(5, "{$updates} software ".str('update')->plural($updates).' pending');
        }

        $failed = $this->failed_jobs_count
            ?? DeploymentJob::where('computer_id', $this->id)->where('status', JobStatus::Failed)->count();
        if ($failed > 0) {
            $take(10, "{$failed} failed deployment ".str('job')->plural($failed));
        }

        // Edge cannot be deployed at all (PackageMode::OsManaged) and
        // winget's own reporting for a self-updating browser can lag what
        // is really installed — this is the only check in the score that
        // answers "is it actually current" for that class of software.
        $browserLatest = $fleetBrowserLatest ?? app(\App\Services\BrowserVersionService::class)
            ->fleetLatestForClient($this->project->client_id);
        $stuckBrowsers = app(\App\Services\BrowserVersionService::class)->stuckNotes($this, $browserLatest);
        if ($stuckBrowsers !== []) {
            $take(10, implode('; ', $stuckBrowsers));
        }

        return ['score' => max(0, $score), 'notes' => $notes];
    }

    /* ---- Agent version ---- */

    /** The newest agent build the server publishes and self-updates toward. */
    public static function latestAgentVersion(): string
    {
        return \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION;
    }

    /**
     * A machine is on an outdated agent when it has reported a version and
     * that version is behind the latest published build. A machine that has
     * never reported one is "unknown", not "outdated" — it is not counted, so
     * a never-enrolled stub can't inflate the update backlog.
     */
    public function isAgentOutdated(): bool
    {
        return $this->agent_version !== null
            && version_compare($this->agent_version, self::latestAgentVersion(), '<');
    }

    /**
     * Machines whose reported agent version is not the latest. Uses a string
     * inequality (SQL-friendly); the fleet only ever runs versions the server
     * has published, so "not equal to latest" and "older than latest" are the
     * same set in practice. isAgentOutdated() does the exact semver compare.
     */
    public function scopeAgentOutdated(Builder $query): Builder
    {
        return $query->whereNotNull('agent_version')
            ->where('agent_version', '!=', self::latestAgentVersion());
    }

    /**
     * PioDeploy has no hypervisor integration — this is inferred from the
     * manufacturer/model text the agent already reports (the same fields
     * every BIOS/WMI-based inventory tool uses for this), not a guaranteed
     * platform API. Good enough to answer "roughly how many of these are
     * VMs", not a claim of certainty for any one machine.
     */
    private const VIRTUAL_HARDWARE_SIGNATURES = [
        'VMware', 'Virtual Machine', 'VirtualBox', 'innotek', 'QEMU', 'KVM',
        'Xen', 'Parallels', 'Google Compute Engine', 'Amazon EC2',
    ];

    /** @return 'physical'|'virtual'|'unknown' */
    public function hardwareType(): string
    {
        if (blank($this->manufacturer) && blank($this->model)) {
            return 'unknown';
        }

        $haystack = ($this->manufacturer ?? '') . ' ' . ($this->model ?? '');
        foreach (self::VIRTUAL_HARDWARE_SIGNATURES as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return 'virtual';
            }
        }

        return 'physical';
    }

    /** @param  'physical'|'virtual'|'unknown'  $type */
    public function scopeHardwareType(Builder $query, string $type): Builder
    {
        $isVirtual = function (Builder $q): void {
            $q->where(function (Builder $sub) {
                foreach (self::VIRTUAL_HARDWARE_SIGNATURES as $needle) {
                    $sub->orWhere('manufacturer', 'like', "%{$needle}%")
                        ->orWhere('model', 'like', "%{$needle}%");
                }
            });
        };

        return match ($type) {
            'virtual' => $query->where($isVirtual),
            'physical' => $query
                ->where(fn (Builder $q) => $q->whereNotNull('manufacturer')->orWhereNotNull('model'))
                ->whereNot($isVirtual),
            'unknown' => $query->whereNull('manufacturer')->whereNull('model'),
            default => $query,
        };
    }

    /**
     * The machines a user may see in a picker: staff see all; a tenant sees
     * only machines in their own client's projects, narrowed to any
     * projects a technician is confined to.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $tenantId = $user->tenantClientId();
        $allowed = $user->visibleProjectIds();
        $machines = $user->grantedComputerIds();

        return $query
            ->when($tenantId !== null || $allowed !== null, fn (Builder $q) => $q->whereHas(
                'project',
                fn ($p) => $p
                    ->when($tenantId !== null, fn ($pp) => $pp->where('client_id', $tenantId))
                    ->when($allowed !== null, fn ($pp) => $pp->whereIn('projects.id', $allowed))
            ))
            // A custom client role with an explicit machine list narrows
            // further: the holder sees exactly those machines, nothing else.
            ->when($machines !== null, fn (Builder $q) => $q->whereIn('computers.id', $machines));
    }

    /**
     * Machines by their reported CATALOGUE SOFTWARE state — outdated /
     * uptodate / pending — not the agent's own version (see
     * scopeAgentOutdated) and not any single package's state (see
     * ComputerSoftware::hasUpdate). One definition shared by the Computers
     * list, the fleet health report and their summary cards, so "outdated"
     * can never mean something different in one place than another.
     */
    public function scopeSoftwareStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'outdated' => $query->whereHas('software', fn ($s) => $s->withUpdateAvailable()),
            'uptodate' => $query->whereHas('software')
                ->whereDoesntHave('software', fn ($s) => $s->withUpdateAvailable()),
            'pending' => $query->whereHas('deploymentJobs', fn ($j) => $j->whereIn('status', [
                JobStatus::Pending, JobStatus::Blocked, JobStatus::Running,
            ])),
            default => $query,
        };
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('hostname', 'like', "%{$term}%")
            ->orWhere('serial_number', 'like', "%{$term}%")
            ->orWhere('model', 'like', "%{$term}%")
            ->orWhere('public_ip', 'like', "%{$term}%")
            ->orWhere('private_ip', 'like', "%{$term}%")
            ->orWhere('mac_address', 'like', "%{$term}%"));
    }

    /* ---- Presentation helpers ---- */

    public function ramForHumans(): ?string
    {
        return $this->ram_bytes === null ? null : self::bytesForHumans($this->ram_bytes);
    }

    public function diskForHumans(): ?string
    {
        if ($this->disk_total_bytes === null) {
            return null;
        }

        $total = self::bytesForHumans($this->disk_total_bytes);

        return $this->disk_free_bytes === null
            ? $total
            : self::bytesForHumans($this->disk_free_bytes) . ' free / ' . $total;
    }

    public static function bytesForHumans(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $value >= 100 ? 0 : 1) . ' ' . $units[$i];
    }

    /**
     * Heartbeats mutate last_seen_at constantly — keep them out of the
     * activity log; only meaningful assignment/identity changes are logged.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('computers')
            ->logOnly(['project_id', 'hostname', 'agent_version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
