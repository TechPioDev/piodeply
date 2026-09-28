<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class NotificationChannel extends Model
{
    use HasFactory;
    use LogsActivity;

    public const TYPE_EMAIL = 'email';
    public const TYPE_WEBHOOK = 'webhook';

    /**
     * Every event the platform can notify about. A channel can only ever be
     * subscribed to a key listed here (the settings form's checkboxes come
     * straight from this array) — a notify() call for a key missing from
     * this list silently reaches zero channels, forever. Found that way:
     * 'signup.received' was already being fired but had no entry here, so
     * new-signup alerts have never actually reached any configured channel.
     */
    public const EVENTS = [
        'job.failed'            => 'Deployment failed (retries exhausted)',
        'computer.registered'   => 'New computer enrolled',
        'agent.offline'         => 'Agent went offline',
        'policy.drift'          => 'Daily compliance drift digest',
        'browser_policy.failed' => 'Browser policy failed or non-compliant',
        'lead.received'         => 'Contact / access-request submitted on the website',
        'signup.received'       => 'New signup submitted',
        'package.requested'     => 'A client requested a new package',
    ];

    protected $fillable = [
        'name', 'type', 'destination', 'events', 'is_active',
        'last_sent_at', 'last_error', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'events'       => 'array',
            'is_active'    => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function scopeSubscribedTo(Builder $query, string $event): Builder
    {
        return $query->where('is_active', true)
            ->whereJsonContains('events', $event);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('notifications')
            ->logOnly(['name', 'type', 'destination', 'events', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
