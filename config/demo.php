<?php

return [

    /*
    | Public demo account (portfolio visitors). When enabled, the login page
    | shows an "Entrar como demo" button and the demo data is reset on a
    | schedule -- see App\Services\DemoResetService.
    */
    'enabled' => (bool) env('DEMO_ENABLED', false),

];
