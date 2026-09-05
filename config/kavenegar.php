<?php
return [
    'apikey' => env('KAVENEGAR_API_KEY'),
    // The template must contain the tractor name token and the requested
    // Persian message. Keeping the template name configurable avoids putting
    // SMS provider credentials or template-specific assumptions in code.
    'tractor_not_started_template' => env('KAVENEGAR_TRACTOR_NOT_STARTED_TEMPLATE', 'tractor-not-started-today'),
];
