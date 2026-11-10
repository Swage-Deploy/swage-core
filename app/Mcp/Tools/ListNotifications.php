<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class ListNotifications extends SwageTool
{
    protected string $description = 'List your recent Swage notifications, newest first: failed deployments and provisioning, changed host keys, certificates not renewing, reboots needed and plugin alerts. Listing them does not mark them read.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'unread_only' => $schema->boolean()->description('Only list notifications you have not read yet.'),
            'limit' => $schema->integer()->description('How many to list, up to 50. Defaults to 20.')->min(1)->max(50),
        ];
    }

    public function handle(Request $request): Response
    {
        $notifications = ($request->get('unread_only') ? $request->user()->unreadNotifications() : $request->user()->notifications())
            ->limit(max(1, min(50, (int) $request->get('limit', 20))))
            ->get();

        return Response::json($notifications->map(fn (DatabaseNotification $notification): array => [
            'type' => $notification->data['type'] ?? null,
            'title' => $notification->data['title'] ?? null,
            'message' => $notification->data['message'] ?? null,
            'severity' => $notification->data['severity'] ?? null,
            'server' => $notification->data['server'] ?? null,
            'url' => $notification->data['url'] ?? null,
            'read' => $notification->read_at !== null,
            'at' => self::time($notification->created_at),
        ])->all());
    }
}
