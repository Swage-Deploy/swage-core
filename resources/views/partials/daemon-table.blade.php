<flux:table>
    <flux:table.columns>
        <flux:table.column>{{ __('Command') }}</flux:table.column>
        <flux:table.column>{{ __('User') }}</flux:table.column>
        <flux:table.column>{{ __('Processes') }}</flux:table.column>
        <flux:table.column>{{ __('Status') }}</flux:table.column>
        <flux:table.column></flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @forelse ($daemons as $daemon)
            <flux:table.row :key="'daemon-'.$daemon->id">
                <flux:table.cell class="max-w-md truncate font-mono text-xs" :title="$daemon->command">{{ $daemon->command }}</flux:table.cell>
                <flux:table.cell>{{ $daemon->user }}</flux:table.cell>
                <flux:table.cell>{{ $daemon->processes }}</flux:table.cell>
                <flux:table.cell><x-status :color="$daemon->status->color()" :label="$daemon->status->label()" /></flux:table.cell>
                <flux:table.cell align="end">
                    <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="restart({{ $daemon->id }})" :tooltip="__('Reinstall and restart')" />
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove({{ $daemon->id }})" wire:confirm.danger="{{ __('Stop and remove this process?') }}" />
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="5">{{ __('Nothing running yet.') }}</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
