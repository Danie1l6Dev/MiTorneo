{{-- Cualquier error 5xx sin página propia (502, 504...). --}}
<x-errors.page
    :code="$exception->getStatusCode()"
    :title="__('Error del servidor')"
    :description="__('Ocurrió un error inesperado de nuestro lado. Ya quedó registrado.')"
    icon="server"
    tone="red"
/>
