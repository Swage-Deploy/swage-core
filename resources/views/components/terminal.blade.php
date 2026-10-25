@props([
    'title' => null,
])

@php
    /**
     * Commands run over SSH still colour their output and redraw progress lines, so drop the escape codes and keep
     * only what each line last showed.
     */
    $output = preg_replace('/\e\[[0-?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)|\e[@-Z\\\\-_]/', '', str_replace("\r\n", "\n", (string) $slot));
    $output = implode("\n", array_map(
        fn (string $line): string => str_contains($line, "\r") ? (string) collect(explode("\r", $line))->filter(fn (string $part): bool => $part !== '')->last() : $line,
        explode("\n", $output),
    ));
@endphp

<div {{ $attributes->class('overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 shadow-lg') }}>
    <div class="flex items-center gap-2 border-b border-white/8 bg-zinc-900 px-4 py-2.5">
        <span class="size-2.5 rounded-full bg-red-500/80"></span>
        <span class="size-2.5 rounded-full bg-amber-400/80"></span>
        <span class="size-2.5 rounded-full bg-emerald-500/80"></span>
        @if ($title)
            <span class="ms-2 truncate font-mono text-xs text-zinc-400">{{ $title }}</span>
        @endif
    </div>
    {{-- Follows new output while scrolled to the bottom, and stays put once the reader scrolls up. --}}
    <pre
        x-data="{ following: true }"
        x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => { if (following) { $el.scrollTop = $el.scrollHeight } }).observe($el, { childList: true, characterData: true, subtree: true })"
        x-on:scroll="following = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 24"
        class="max-h-[65vh] overflow-auto p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-200">{!! $output !!}</pre>
</div>
