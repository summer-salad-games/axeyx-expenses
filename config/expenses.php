<?php

return [
    'reporting_timezone' => env('REPORTING_TIMEZONE', 'Europe/Luxembourg'),
    'max_amount_millimes' => 999999999999,
    'attachments' => ['count' => 10, 'file_bytes' => 10 * 1024 * 1024, 'total_bytes' => 50 * 1024 * 1024],
];
