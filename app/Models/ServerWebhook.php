<?php

namespace App\Models;

use App\Enums\WebhookKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A URL a server's notifications are posted to, such as a Slack or Discord channel's webhook. The URL is itself the
 * secret that lets anyone post to the channel, so it is encrypted and never shown again.
 *
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property WebhookKind $kind
 * @property string $url
 * @property Carbon|null $last_sent_at
 * @property string|null $last_error
 */
#[Fillable(['name', 'kind', 'url', 'last_sent_at', 'last_error'])]
#[Hidden(['url'])]
class ServerWebhook extends Model
{
    use Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WebhookKind::class,
            'url' => 'encrypted',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * The URL's host, which is safe to show.
     */
    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }
}
