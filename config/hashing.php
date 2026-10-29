<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default hash driver that will be used to hash
    | passwords for your application. By default, the argon2id algorithm
    | is used according to modern AppSec guidelines (OWASP / NIST SP 800-63B).
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Legacy fallback hashing configuration.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon2i and Argon2id Options
    |--------------------------------------------------------------------------
    |
    | Argon2id is the memory-hard password hashing algorithm chosen by the
    | Password Hashing Competition. It provides superior defense against
    | GPU/ASIC brute-force attacks and cache-timing side-channel attacks.
    |
    */

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536), // 64 MB RAM cost per hash
        'times' => env('ARGON_TIMES', 4),       // 4 iterations time cost
        'threads' => env('ARGON_THREADS', 2),   // 2 parallel threads
        'verify' => true,
    ],

];
