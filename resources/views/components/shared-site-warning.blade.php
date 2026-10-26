@props(['site'])

@unless ($site->isIsolated())
    <flux:callout icon="exclamation-triangle" color="amber" {{ $attributes }}>
        <flux:callout.heading>{{ __('This site shares a Linux user with other sites') }}</flux:callout.heading>
        <flux:callout.text>{{ __('It runs as the server\'s :user user, like every other site on this server that is not isolated. Anyone who can deploy it, change its environment or run its commands can reach those sites\' files and .env too, so only share it with people you would trust with all of them. A site cannot be isolated once created: to separate it, copy its environment, remove it and create it again, since every new site is isolated.', ['user' => config('swage.user')]) }}</flux:callout.text>
    </flux:callout>
@endunless
