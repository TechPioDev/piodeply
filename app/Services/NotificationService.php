<?php

namespace App\Services;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fans an event out to every subscribed channel. Delivery is synchronous
 * (no queue worker is assumed on XAMPP) and fault-isolated: a dead
 * webhook or SMTP hiccup is recorded on the channel and never breaks the
 * caller — notifications ride along agent API requests.
 */
class NotificationService
{
    private const WEBHOOK_TIMEOUT_SECONDS = 5;

    /**
     * @param array<string, scalar|null> $data Structured details, included
     *        in webhook payloads and rendered as lines in emails.
     */
    public function notify(string $event, string $title, array $data = []): int
    {
        $channels = NotificationChannel::subscribedTo($event)->get();

        $sent = 0;
        foreach ($channels as $channel) {
            if ($this->deliver($channel, $event, $title, $data)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function deliver(NotificationChannel $channel, string $event, string $title, array $data = []): bool
    {
        try {
            match ($channel->type) {
                NotificationChannel::TYPE_EMAIL => $this->sendEmail($channel, $title, $data),
                NotificationChannel::TYPE_WEBHOOK => $this->sendWebhook($channel, $event, $title, $data),
                default => throw new \RuntimeException("Unknown channel type '{$channel->type}'."),
            };

            $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->saveQuietly();

            return true;
        } catch (\Throwable $e) {
            Log::warning("Notification delivery failed for channel '{$channel->name}': {$e->getMessage()}");
            $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();

            return false;
        }
    }

    private function sendEmail(NotificationChannel $channel, string $title, array $data): void
    {
        $body = $title . "\n\n" . $this->lines($data) . "\n\n— PioDeploy";

        Mail::to($channel->destination)->send(new \App\Mail\ChannelNotification($title, $body));
    }

    private function sendWebhook(NotificationChannel $channel, string $event, string $title, array $data): void
    {
        $response = Http::timeout(self::WEBHOOK_TIMEOUT_SECONDS)
            ->post($channel->destination, $this->webhookPayload($event, $title, $data));

        if ($response->failed()) {
            throw new \RuntimeException("Webhook returned HTTP {$response->status()}.");
        }
    }

    /**
     * One payload, every reader. Teams has two webhook systems and Slack and
     * Discord each want their own shape, so all of them travel together and
     * each renders the keys it understands, ignoring the rest:
     *   - Teams Workflows (Power Automate, the current system) reads
     *     type=message + attachments as an Adaptive Card.
     *   - Teams legacy connector reads the MessageCard (@type/sections).
     *   - Slack reads "text"; Discord reads "content".
     * None of them ever sees Slack's *bold*, which shows as literal asterisks
     * in Teams.
     *
     * @param array<string, scalar|null> $data
     * @return array<string, mixed>
     */
    private function webhookPayload(string $event, string $title, array $data): array
    {
        [$emoji, $hex, $adaptiveColour] = $this->accent($event);

        $plain = trim("{$emoji} {$title}\n\n" . $this->lines($data));

        $facts = collect($data)
            ->map(fn ($value, $key) => [
                'name'  => ucfirst(str_replace('_', ' ', $key)),   // MessageCard
                'title' => ucfirst(str_replace('_', ' ', $key)),   // Adaptive FactSet
                'value' => (string) ($value ?? '—'),
            ])
            ->values();

        return [
            // Teams Workflows — an Adaptive Card in an attachment.
            'type'        => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content'     => [
                    'type'    => 'AdaptiveCard',
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'version' => '1.4',
                    'body'    => [
                        [
                            'type'   => 'TextBlock',
                            'text'   => "{$emoji} {$title}",
                            'weight' => 'Bolder',
                            'size'   => 'Medium',
                            'color'  => $adaptiveColour,
                            'wrap'   => true,
                        ],
                        [
                            'type'  => 'FactSet',
                            'facts' => $facts->map(fn ($f) => ['title' => $f['title'], 'value' => $f['value']])->all(),
                        ],
                    ],
                ],
            ]],

            // Slack
            'text'    => $plain,
            // Discord
            'content' => $plain,

            // Teams legacy connector — a MessageCard.
            '@type'      => 'MessageCard',
            '@context'   => 'https://schema.org/extensions',
            'themeColor' => $hex,
            'summary'    => $title,
            'title'      => "{$emoji} {$title}",
            'sections'   => [[
                'facts'    => $facts->map(fn ($f) => ['name' => $f['name'], 'value' => $f['value']])->all(),
                'markdown' => false,
            ]],

            // Structured, for anything reading the raw body.
            'event'   => $event,
            'data'    => $data,
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Per event: an emoji, the MessageCard hex colour, and the Adaptive Card
     * colour name — so severity reads at a glance in whichever card renders.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function accent(string $event): array
    {
        return match ($event) {
            'job.failed'            => ['⚠️', 'D64545', 'Attention'],
            'agent.offline'         => ['🔌', 'F59E0B', 'Warning'],
            'browser_policy.failed' => ['🛡️', 'F59E0B', 'Warning'],
            'policy.drift'          => ['📋', '2563EB', 'Accent'],
            'computer.registered'   => ['🟢', '22C55E', 'Good'],
            'lead.received'         => ['✉️', '0F766E', 'Good'],
            'signup.received'       => ['✉️', '0F766E', 'Good'],
            'package.requested'     => ['📦', '0F766E', 'Good'],
            default                 => ['🔔', '0F766E', 'Accent'],
        };
    }

    private function lines(array $data): string
    {
        return collect($data)
            ->map(fn ($value, $key) => ucfirst(str_replace('_', ' ', $key)) . ': ' . ($value ?? '—'))
            ->join("\n");
    }
}
