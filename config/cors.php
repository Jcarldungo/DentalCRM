<?php

return [

    /*
     * Pinned to the app's own origin instead of inheriting the framework
     * default of ['*'].
     *
     * Not exploitable today — the only CORS-enabled path is the unused
     * sanctum/csrf-cookie route and supports_credentials is false — but
     * that stops being true the moment an API route is added, and the
     * default would then be a permissive one nobody chose.
     */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('APP_URL', 'http://localhost')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
