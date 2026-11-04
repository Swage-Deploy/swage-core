<?php

namespace App\Notifications;

use App\Enums\MemberRole;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends a notification to the people it concerns: the owner and Managers of its server (and of its site), and the
 * server's webhooks. One about the panel itself goes to administrators.
 */
class Notifier
{
    /**
     * Send the notification. Failing to send one never stops the work that raised it.
     */
    public function send(SwageNotification $notification): void
    {
        try {
            $server = $notification->server();

            Notification::send($server ? $this->recipients($server, $notification->site()) : User::where('is_admin', true)->get(), $notification);

            foreach ($server?->webhooks ?? [] as $webhook) {
                $webhook->notify($notification);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The people told about a server, or one of its sites. Viewers aren't, since they can't act on it.
     *
     * @return Collection<int, User>
     */
    public function recipients(Server $server, ?Site $site = null): Collection
    {
        return User::query()
            ->whereKey($server->user_id)
            ->orWhereHas('memberServers', fn ($query) => $query->whereKey($server->id)->where('server_members.role', MemberRole::Manager->value))
            ->when($site, fn ($query) => $query->orWhereHas('memberSites', fn ($query) => $query->whereKey($site->id)->where('site_members.role', MemberRole::Manager->value)))
            ->get();
    }
}
