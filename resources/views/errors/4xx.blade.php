{{-- Cualquier error 4xx sin página propia (401, 400, 405, 408, 409, 410...). --}}
<x-errors.page
    :code="$exception->getStatusCode()"
    :title="__('No se pudo procesar la solicitud')"
    :description="__('Ocurrió un problema con tu solicitud. Intenta de nuevo.')"
    icon="question-mark-circle"
/>
