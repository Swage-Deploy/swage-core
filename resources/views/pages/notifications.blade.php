<?php

use App\Plugins\Attention;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notifications')] class extends Component {
    use WithPagination;

    #[Computed]
    public function notifications(): mixed
    {
        return Auth::user()->notifications()->latest()->paginate(25);
    }

    /**
     * Mark the notification read and open the page it links to.
     */
    public function open(string $id): mixed
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $this->redirect($notification->data['url'] ?? route('dashboard'), navigate: true);
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);

        unset($this->notifications);
    }

    public function severityColor(DatabaseNotification $notification): string
    {
        return match ($notification->data['severity'] ?? null) {
            Attention::HIGH => 'red',
            Attention::MEDIUM => 'amber',
            default => 'zinc',
        };
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Notifications') }}</flux:heading>
            <flux:subheading>{{ __('Failures and warnings from your servers, sites and plugins. Choose which also reach you by email in your settings.') }}</flux:subheading>
        </div>

        <div class="flex gap-2">
            <flux:button size="sm" icon="cog-6-tooth" :href="route('notifications.edit')" wire:navigate>{{ __('Settings') }}</flux:button>
            @if (auth()->user()->unreadNotifications()->exists())
                <flux:button size="sm" icon="check" wire:click="markAllRead">{{ __('Mark all read') }}</flux:button>
            @endif
        </div>
    </div>

    <x-panel flush>
        @if ($this->notifications->isEmpty())
            <x-empty-state icon="bell" :heading="__('No notifications')">
                {{ __('When a deployment fails, a certificate stops renewing or a plugin raises an alert, it shows here.') }}
            </x-empty-state>
        @else
            <ul class="divide-y divide-zinc-200 dark:divide-white/8">
                @foreach ($this->notifications as $notification)
                    <li wire:key="notification-{{ $notification->id }}">
                        <button type="button" wire:click="open('{{ $notification->id }}')" class="flex w-full cursor-pointer items-start gap-3 px-5 py-4 text-start hover:bg-zinc-50 dark:hover:bg-white/3">
                            <span class="mt-1.5 flex size-4 shrink-0 items-center justify-center">
                                <x-status :color="$this->severityColor($notification)" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:heading class="{{ $notification->read_at ? 'font-normal! text-zinc-500!' : '' }}">{{ $notification->data['title'] ?? '' }}</flux:heading>
                                    @unless ($notification->read_at)
                                        <flux:badge size="sm" color="ember">{{ __('New') }}</flux:badge>
                                    @endunless
                                </div>
                                <flux:text class="mt-0.5">{{ $notification->data['message'] ?? '' }}</flux:text>
                                <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-zinc-500">
                                    @if ($notification->data['server'] ?? null)
                                        <span class="flex items-center gap-1"><flux:icon.server variant="micro" />{{ $notification->data['server'] }}</span>
                                    @endif
                                    <span title="{{ $notification->created_at->toDayDateTimeString() }}">{{ $notification->created_at->diffForHumans() }}</span>
                                </div>
                            </div>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>

    <flux:pagination :paginator="$this->notifications" />
</div>
