<?php

namespace App\Enums;

use App\Notifications\SwageNotification;
use App\Plugins\Attention;

/**
 * Where a server's webhook posts notifications, which decides the shape of what is sent.
 */
enum WebhookKind: string
{
    case Slack = 'slack';
    case Discord = 'discord';
    case Json = 'json';

    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Discord => 'Discord',
            self::Json => __('JSON'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Slack => __('A Slack incoming webhook URL, from hooks.slack.com.'),
            self::Discord => __('A Discord channel\'s webhook URL, from its Integrations settings.'),
            self::Json => __('Any HTTPS URL. Swage posts each notification to it as JSON.'),
        };
    }

    /**
     * The request body for a notification.
     *
     * @return array<string, mixed>
     */
    public function payload(SwageNotification $notification): array
    {
        return match ($this) {
            self::Slack => [
                'text' => "*{$notification->title()}*\n{$notification->message()}\n<{$notification->url()}|".__('Open in Swage').'>',
            ],
            self::Discord => [
                'embeds' => [[
                    'title' => $notification->title(),
                    'description' => $notification->message(),
                    'url' => $notification->url(),
                    'color' => match ($notification->severity()) {
                        Attention::HIGH => 0xDC2626,
                        Attention::MEDIUM => 0xD97706,
                        default => 0x71717A,
                    },
                ]],
            ],
            self::Json => [
                'type' => $notification->type()->value,
                'title' => $notification->title(),
                'message' => $notification->message(),
                'severity' => $notification->severity(),
                'url' => $notification->url(),
                'server' => $notification->server()?->only(['id', 'name', 'ip_address']),
                'site' => $notification->site()?->only(['id', 'domain']),
                'sent_at' => now()->toIso8601String(),
            ],
        };
    }
}
