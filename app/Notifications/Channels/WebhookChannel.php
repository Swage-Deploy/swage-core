<?php

namespace App\Notifications\Channels;

use App\Models\ServerWebhook;
use App\Notifications\SwageNotification;
use App\Notifications\WebhookUrl;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts notifications to a server's webhook. A webhook that fails records why, shown on the server's Notifications
 * tab, rather than being retried.
 */
class WebhookChannel
{
    public function __construct(protected WebhookUrl $webhookUrl) {}

    /**
     * Post the notification, returning why it failed or null when it was delivered.
     */
    public function send(ServerWebhook $webhook, SwageNotification $notification): ?string
    {
        $error = $this->webhookUrl->problem($webhook->url);

        if ($error === null) {
            try {
                $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                    ->withUserAgent('Swage')
                    ->post($webhook->url, $webhook->kind->payload($notification));

                $error = $response->successful() ? null : __('The webhook answered with HTTP :status.', ['status' => $response->status()]);
            } catch (Throwable) {
                $error = __('Unable to reach :host.', ['host' => $webhook->host()]);
            }
        }

        $webhook->update($error === null ? ['last_sent_at' => now(), 'last_error' => null] : ['last_error' => $error]);

        return $error;
    }
}
