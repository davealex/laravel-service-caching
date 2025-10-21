<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Driver
    |--------------------------------------------------------------------------
    |
    | Specify the default cache driver to be used by the service. If set to
    | null, Laravel's default cache driver will be used. This package works
    | best with drivers that support cache tags (e.g., redis, memcached).
    |
    */
    'driver' => env('SERVICE_CACHE_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | Default Cache Duration
    |--------------------------------------------------------------------------
    |
    | The default duration (in seconds) for which service data will be cached.
    | This can be overridden on a per-call basis via the 'duration' option.
    |
    | Pass `duration` as **null or 0** in the options array to cache the result forever.
    | Defaults to 10 minutes (600 seconds).
    |
    */
    'cache_duration_in_seconds' => 600,

    /*
    |--------------------------------------------------------------------------
    | User Identifier
    |--------------------------------------------------------------------------
    |
    | The attribute on the authenticated user model that should be used to
    | uniquely identify them for user-specific caching. This is typically
    | the primary key, like 'id'.
    |
    */
    'user_identifier_key' => 'id',
];
