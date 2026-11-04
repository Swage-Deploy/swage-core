<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Server;
use App\Plugins\Attention;
use InvalidArgumentException;

/**
 * An alert a plugin raises about a server, such as the security scanner finding an attack. It reaches the server's
 * owner and Managers, by email for those who get plugin alerts by email, and the server's webhooks.
 *
 * Plugins send one with ServerAlert::send(). Part of the plugin API since Swage v1.3.16, so check that the class
 * exists before using it.
 */
class ServerAlert extends SwageNotification
{
    /**
     * @param  string  $source  Who raised it, such as "Security scanner".
     * @param  string  $title  A short summary, such as "Brute-force attack on SSH".
     * @param  string  $message  What happened and what to do about it.
     * @param  string  $alertSeverity  Attention::HIGH, MEDIUM or LOW.
     * @param  string|null  $link  The page to look at, such as the plugin's tab. Defaults to the server's page.
     */
    public function __construct(
        public Server $server,
        public string $source,
        public string $alertTitle,
        public string $alertMessage,
        public string $alertSeverity = Attention::HIGH,
        public ?string $link = null,
    ) {
        if (! in_array($alertSeverity, [Attention::HIGH, Attention::MEDIUM, Attention::LOW], true)) {
            throw new InvalidArgumentException("[{$alertSeverity}] is not a severity Swage shows.");
        }
    }

    /**
     * Send an alert about a server to the people and webhooks it concerns.
     */
    public static function send(Server $server, string $source, string $title, string $message, string $severity = Attention::HIGH, ?string $link = null): void
    {
        app(Notifier::class)->send(new self($server, $source, $title, $message, $severity, $link));
    }

    public function type(): NotificationType
    {
        return NotificationType::ServerAlert;
    }

    public function severity(): string
    {
        return $this->alertSeverity;
    }

    public function title(): string
    {
        return __(':source: :title on :server', ['source' => $this->source, 'title' => $this->alertTitle, 'server' => $this->server->name]);
    }

    public function message(): string
    {
        return $this->alertMessage;
    }

    public function url(): string
    {
        return $this->link ?? route('servers.show', $this->server);
    }

    public function server(): Server
    {
        return $this->server;
    }
}
