@props(['server'])

@php($steps = App\Provisioning\ProvisioningProgress::for($server))
@php($done = collect($steps)->where('state', 'done')->count())

<div {{ $attributes->class('flex flex-col gap-3') }}>
    <div class="flex items-center justify-between gap-4 text-xs font-medium tracking-wider text-zinc-500 uppercase">
        <span>{{ __('Steps') }}</span>
        <span class="font-mono normal-case tracking-normal">{{ __(':done of :total done', ['done' => $done, 'total' => count($steps)]) }}</span>
    </div>

    <ol class="flex flex-col gap-1.5">
        @foreach ($steps as $index => $step)
            <li wire:key="provisioning-step-{{ $index }}" @class([
                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm',
                'bg-ember-500/10 text-zinc-900 ring-1 ring-ember-500/30 dark:text-white' => $step['state'] === 'current',
                'bg-red-500/10 text-red-700 ring-1 ring-red-500/30 dark:text-red-400' => $step['state'] === 'failed',
                'text-zinc-700 dark:text-zinc-300' => $step['state'] === 'done',
                'text-zinc-400 dark:text-zinc-500' => $step['state'] === 'pending',
            ])>
                <span class="flex size-5 shrink-0 items-center justify-center">
                    @if ($step['state'] === 'done')
                        <flux:icon.check-circle variant="mini" class="text-emerald-500" />
                    @elseif ($step['state'] === 'current')
                        <flux:icon.arrow-path variant="mini" class="animate-spin text-ember-500" />
                    @elseif ($step['state'] === 'failed')
                        <flux:icon.x-circle variant="mini" class="text-red-500" />
                    @else
                        <span class="flex size-5 items-center justify-center rounded-full font-mono text-[0.65rem] ring-1 ring-zinc-300 dark:ring-white/15">{{ $index + 1 }}</span>
                    @endif
                </span>
                <span>{{ $step['label'] }}</span>
            </li>
        @endforeach
    </ol>
</div>
