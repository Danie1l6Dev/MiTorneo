@props([
    'href',
    'filename' => 'tabla-posiciones.pdf',
])

{{--
    A plain `<a href>` download link can't tell us when the browser is done
    (no JS event fires for it), so a loading state built on click+timeout
    either resets too early or stays stuck if the real download took longer
    than the guessed delay -- see the feedback that led to this component.
    Fetching the PDF ourselves and saving it from the resolved blob ties the
    loading state to the actual response instead of a guess. $filename is
    just what the browser's save dialog suggests -- deliberately not read
    off the response's Content-Disposition header, since matching it needs a
    double-quote character that would close this x-data="..." HTML attribute
    early and corrupt the rest of the tag.
--}}
<div
    x-data="{
        exporting: false,
        async download() {
            this.exporting = true

            try {
                const response = await fetch(@js($href))

                if (! response.ok) {
                    throw new Error('export failed')
                }

                const blob = await response.blob()
                const url = URL.createObjectURL(blob)

                {{-- The explicit semicolon matters: @js() compiles to a
                     PHP echo ending in "?>", and PHP swallows the newline
                     that immediately follows a closing "?>" tag -- without
                     it, this line would run straight into link.click() with
                     no separator and break the whole x-data expression. --}}
                const link = document.createElement('a')
                link.href = url
                link.download = @js($filename);
                link.click()

                URL.revokeObjectURL(url)
            } catch (error) {
                alert('{{ __('No se pudo generar el PDF. Intenta de nuevo.') }}')
            } finally {
                this.exporting = false
            }
        },
    }"
    class="inline-flex"
>
    <flux:button @click="download()" :loading="true" x-bind:data-loading="exporting" {{ $attributes }}>
        {{ $slot }}
    </flux:button>
</div>
