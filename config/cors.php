<?php

return [
    'paths' => ['api/v1/preview/*'],
    'allowed_methods' => ['GET', 'OPTIONS'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 900,
    'supports_credentials' => false,
];
