<?php

namespace App\Actions\Sites;

use App\Enums\DeploymentStatus;
use App\Jobs\RunSiteCommand;
use App\Models\Site;
use App\Models\SiteCommand;
use App\Models\User;
use App\Sites\ArtisanCommand;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RunArtisanCommand
{
    /**
     * Run an Artisan command, typed as what follows "php artisan", in the site's live release in the background.
     *
     * @throws ValidationException
     */
    public function handle(Site $site, User $user, ?string $input): SiteCommand
    {
        Validator::make(['command' => $input], ['command' => ['required', 'string', 'max:1000']])->validate();

        try {
            $artisan = ArtisanCommand::parse($input);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['command' => $exception->getMessage()]);
        }

        $command = $site->commands()->create([
            'user_id' => $user->id,
            'command' => implode(' ', array_map(fn (string $argument): string => preg_match('/^[A-Za-z0-9:_.,=\/@%+-]+$/D', $argument) ? $argument : escapeshellarg($argument), $artisan->arguments)),
            'status' => DeploymentStatus::Pending,
        ]);

        RunSiteCommand::dispatch($command);

        return $command;
    }
}
