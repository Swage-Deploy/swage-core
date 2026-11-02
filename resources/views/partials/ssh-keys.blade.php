<flux:table>
    <flux:table.columns>
        <flux:table.column>{{ __('Name') }}</flux:table.column>
        <flux:table.column>{{ __('Fingerprint') }}</flux:table.column>
        <flux:table.column></flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @forelse ($this->sshAccess->keysFor($sshUser) as $index => $key)
            @php($isSwage = $key->is($server->ssh_public_key))
            <flux:table.row :key="$sshUser.'-'.$key->data">
                <flux:table.cell>
                    <div class="flex items-center gap-2">
                        @if ($isSwage)
                            <flux:badge size="sm" color="orange" icon="lock-closed">{{ __('Swage') }}</flux:badge>
                        @endif
                        <span>{{ $key->comment ?: $key->type }}</span>
                        @if ($key->options !== '')
                            <flux:badge size="sm" :title="$key->options">{{ __('Restricted') }}</flux:badge>
                        @endif
                    </div>
                </flux:table.cell>
                <flux:table.cell class="max-w-xs truncate font-mono text-xs" :title="$key->type.' '.$key->data">{{ $key->fingerprint() }}</flux:table.cell>
                <flux:table.cell align="end">
                    @if ($isSwage)
                        <flux:tooltip :content="__('Swage manages the server with this key, so it can\'t be removed here.')">
                            <flux:icon.lock-closed variant="micro" class="inline text-zinc-400" />
                        </flux:tooltip>
                    @elseif ($canManage)
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove('{{ $sshUser }}', {{ $index }})"
                            wire:confirm.danger="{{ __('Remove this key from :user? Whoever holds it will no longer be able to sign in as :user.', ['user' => $sshUser]) }}" />
                    @endif
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="3">{{ __('No SSH keys. Nobody can sign in as this user.') }}</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
