@props([
    'url',
    'label' => null,
])

{{--
    Shows a read-only, stable public URL with a one-click copy button (Alpine
    + navigator.clipboard, same pattern already used by the two-factor setup
    modal's manual setup key) plus a plain "Abrir" link that opens it in a
    new tab. Used on the admin tournament page to share its public portal
    link (see routes/public.php) -- reusable anywhere else a shareable link
    needs the same "show it / copy it / open it" trio.
--}}
<div {{ $attributes->class('rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel') }}>
    @if ($label || isset($actions))
        <div class="mb-1 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <flux:icon.link variant="micro" class="size-4 text-accent-content" />
                <flux:heading size="sm">{{ $label }}</flux:heading>
            </div>

            @isset($actions)
                <div class="shrink-0">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    @isset($description)
        <flux:text class="mb-3 text-sm text-zinc-500">{{ $description }}</flux:text>
    @endisset

    <div
        class="flex flex-col gap-2 sm:flex-row sm:items-stretch"
        x-data="{
            copied: false,
            async copy() {
                try {
                    await navigator.clipboard.writeText('{{ $url }}');
                    this.copied = true;
                    setTimeout(() => this.copied = false, 1500);
                } catch (e) {
                    console.warn('Could not copy to clipboard');
                }
            },
        }"
    >
        <div class="flex min-w-0 flex-1 items-stretch overflow-hidden rounded-xl border border-zinc-200 dark:border-white/15">
            <input
                type="text"
                readonly
                value="{{ $url }}"
                onclick="this.select()"
                class="w-full min-w-0 truncate bg-transparent p-2.5 text-sm text-zinc-700 outline-none dark:text-white/80"
            />

            <button
                type="button"
                @click="copy()"
                class="flex shrink-0 items-center justify-center border-l border-zinc-200 px-3 transition-colors hover:bg-zinc-100 dark:border-white/15 dark:hover:bg-white/10"
            >
                <flux:icon.document-duplicate x-show="!copied" variant="outline" class="size-4" />
                <flux:icon.check x-show="copied" variant="solid" class="size-4 text-green-500" x-cloak />
            </button>
        </div>

        <flux:button :href="$url" variant="ghost" icon="arrow-top-right-on-square" target="_blank" class="shrink-0">
            {{ __('Abrir') }}
        </flux:button>
    </div>
</div>
