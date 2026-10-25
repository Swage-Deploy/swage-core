<?php

namespace App\SourceControl;

use App\Models\User;

class NotConnectedException extends SourceControlException
{
    public function __construct(public readonly User $user, public readonly SourceControlProvider $provider)
    {
        parent::__construct("{$user->name} has not connected a {$provider->name()} account. Connect one from the source control settings page and try again.");
    }
}
