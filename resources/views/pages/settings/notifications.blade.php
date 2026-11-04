<?php

use App\Enums\NotificationType;
use App\Notifications\TestNotification;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    /**
     * Turn email on or off for a type of notification. It always shows under Notifications in the panel.
     */
    public function toggle(string $type): void
    {
        $user = Auth::user();
        $type = NotificationType::from($type);

        abort_unless(in_array($type, NotificationType::for($user), true), 404);

        $user->update(['notification_preferences' => [
            ...$user->notification_preferences ?? [],
            $type->value => ! $user->wantsEmail($type),
        ]]);
    }

    public function sendTest(): void
    {
        try {
            Auth::user()->notifyNow(new TestNotification);
        } catch (Throwable $exception) {
            report($exception);

            // The mail server's reply can name its host and account, which only administrators set up.
            Flux::toast(variant: 'danger', text: Auth::user()->is_admin
                ? __('The notification shows in the panel, but the email couldn\'t be sent: :error', ['error' => $exception->getMessage()])
                : __('The notification shows in the panel, but the email couldn\'t be sent. Ask an administrator to check the panel\'s email settings.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Sent a test notification to :email.', ['email' => Auth::user()->email]));
    }

    /**
     * Whether the panel writes email to its log rather than sending it, as it does until an administrator sets it up.
     */
    public function mailIsSetUp(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Every notification shows under Notifications in the panel. Choose which also reach you by email, at :email.', ['email' => auth()->user()->email])">
        <div class="flex flex-col gap-6">
            @unless ($this->mailIsSetUp())
                <flux:callout icon="envelope" color="amber">
                    <flux:callout.text>{{ __('This panel can\'t send email yet, so notifications only show in the panel. An administrator sets up email with the MAIL_ settings in Swage\'s .env file.') }}</flux:callout.text>
                </flux:callout>
            @endunless

            <div class="flex flex-col divide-y divide-zinc-200 dark:divide-white/8">
                @foreach (App\Enums\NotificationType::for(auth()->user()) as $type)
                    <div wire:key="type-{{ $type->value }}" class="flex items-start justify-between gap-6 py-3 first:pt-0">
                        <div class="min-w-0">
                            <flux:heading>{{ $type->label() }}</flux:heading>
                            <flux:text class="mt-0.5">{{ $type->description() }}</flux:text>
                        </div>
                        <flux:switch :checked="auth()->user()->wantsEmail($type)" wire:click="toggle('{{ $type->value }}')" :label="__('Email')" align="left" class="shrink-0" />
                    </div>
                @endforeach
            </div>

            <div>
                <flux:button icon="paper-airplane" wire:click="sendTest">{{ __('Send a test notification') }}</flux:button>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
