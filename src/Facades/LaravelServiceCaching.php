<?php

namespace Davealex\LaravelServiceCaching\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see LaravelServiceCaching
 */
class LaravelServiceCaching extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-service-caching';
    }
}
