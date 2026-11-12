<?php

return [
    'driver' => 'argon2id',
    'bcrypt' => ['rounds' => 12, 'verify' => true],
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 3),
        'verify' => true,
    ],
    'rehash_on_login' => true,
];
