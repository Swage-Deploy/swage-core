<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\SelfUpdate;

class UpdateFailed extends SwageNotification
{
    public function __construct(public SelfUpdate $update) {}

    public function type(): NotificationType
    {
        return NotificationType::UpdateFailed;
    }

    public function title(): string
    {
        return $this->update->to_version
            ? __('Updating Swage to :version failed', ['version' => $this->update->to_version])
            : __('Updating Swage failed');
    }

    public function message(): string
    {
        return __('Swage keeps running :version. The update\'s output shows what went wrong.', ['version' => $this->update->from_version ?? __('its current version')]);
    }

    public function url(): string
    {
        return route('updates');
    }
}
