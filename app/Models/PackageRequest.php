<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A client asking the MSP to add software to their catalogue — never a
 * direct catalogue write. The MSP only ever ships tested packages, so every
 * ask is reviewed by staff first; approving one means building the real
 * Package (winget/choco id, installer type, architecture — nothing a client
 * can supply themselves), not rubber-stamping the request as-is.
 */
class PackageRequest extends Model
{
    use HasFactory;
    use LogsActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'client_id', 'requested_by', 'name', 'vendor', 'homepage', 'notes',
        'status', 'decided_by', 'decided_at', 'decision_note', 'created_package_id',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function createdPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'created_package_id');
    }

    /** Still needs a staff decision. */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Staff see every client's requests; a tenant sees only their own. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $tenantId = $user->tenantClientId();

        return $query->when($tenantId !== null, fn (Builder $q) => $q->where('client_id', $tenantId));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('packages')
            ->logOnly(['name', 'vendor', 'status', 'decision_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
