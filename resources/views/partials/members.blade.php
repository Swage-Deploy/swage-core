{{--
    The members panel shared by servers and sites. Expects $owner, the server's owner, $canManage, and optionally
    $inherited, people with access through the site's server.
--}}
<x-panel icon="user-group" :heading="__('Members')" :description="$description" flush>
    <flux:table>
        <flux:table.rows>
            <flux:table.row>
                <flux:table.cell class="font-medium">
                    {{ $owner->name }}
                    <div class="text-xs font-normal text-zinc-500">{{ $owner->email }}</div>
                </flux:table.cell>
                <flux:table.cell><flux:badge size="sm" color="orange">{{ __('Owner') }}</flux:badge></flux:table.cell>
                <flux:table.cell></flux:table.cell>
            </flux:table.row>

            @foreach ($inherited ?? [] as $member)
                <flux:table.row :key="'inherited-'.$member->id">
                    <flux:table.cell class="font-medium">
                        {{ $member->name }}
                        <div class="text-xs font-normal text-zinc-500">{{ $member->email }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="zinc">{{ App\Enums\MemberRole::from($member->pivot->role)->label() }}</flux:badge>
                        <span class="ms-1 text-xs text-zinc-500">{{ __('through the server') }}</span>
                    </flux:table.cell>
                    <flux:table.cell></flux:table.cell>
                </flux:table.row>
            @endforeach

            @foreach ($this->members as $member)
                <flux:table.row :key="'member-'.$member->id">
                    <flux:table.cell class="font-medium">
                        {{ $member->name }}
                        <div class="text-xs font-normal text-zinc-500">{{ $member->email }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($canManage)
                            <flux:select size="sm" class="max-w-36" wire:change="changeRole({{ $member->id }}, $event.target.value)">
                                @foreach (App\Enums\MemberRole::cases() as $memberRole)
                                    <flux:select.option :value="$memberRole->value" :selected="$member->pivot->role === $memberRole->value">{{ $memberRole->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:badge size="sm" color="zinc">{{ App\Enums\MemberRole::from($member->pivot->role)->label() }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($canManage)
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeMember({{ $member->id }})"
                                wire:confirm.danger="{{ __('Remove :name? They lose access straight away.', ['name' => $member->name]) }}">{{ __('Remove') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($canManage)
        <form wire:submit="addMember" class="flex flex-col gap-4 border-t border-zinc-100 px-5 py-4 sm:flex-row sm:items-start dark:border-white/5">
            <div class="flex-1">
                <flux:input wire:model="email" type="email" :placeholder="__('Email of their Swage account')" :aria-label="__('Email')" />
                <flux:error name="email" />
            </div>
            <flux:select wire:model="role" class="sm:max-w-40" :aria-label="__('Role')">
                @foreach (App\Enums\MemberRole::cases() as $memberRole)
                    <flux:select.option :value="$memberRole->value">{{ $memberRole->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" icon="user-plus">{{ __('Add member') }}</flux:button>
        </form>
    @endif
</x-panel>

<div class="mt-4 grid gap-2 text-sm text-zinc-500">
    @foreach (App\Enums\MemberRole::cases() as $memberRole)
        <div><span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $memberRole->label() }}:</span> {{ $memberRole->description() }}</div>
    @endforeach
</div>
