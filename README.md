[![Latest Version on Packagist](https://img.shields.io/packagist/v/davealex/laravel-service-caching.svg?style=flat-square)](https://packagist.org/packages/davealex/laravel-service-caching)
[![Total Downloads](https://img.shields.io/packagist/dt/davealex/laravel-service-caching.svg?style=flat-square)](https://packagist.org/packages/davealex/laravel-service-caching)
![GitHub Actions](https://github.com/davealex/laravel-service-caching/actions/workflows/main.yml/badge.svg)

# **Laravel Service Caching**

A simple, fluent, and driver-agnostic caching layer for your **Laravel service classes**. This package provides an easy way to cache the results of your service methods, automatically handling cache key generation based on request parameters and user context.

It intelligently detects whether your configured cache driver supports tags and falls back to a manual key-tracking system if it doesn't, making it safe to use with drivers like file or database.

## **Installation**

You can install the package via composer:

`composer require davealex/laravel-service-caching`

## **Configuration**

Publish the configuration file with this command:

`php artisan vendor:publish --provider="Davealex\LaravelServiceCaching\LaravelServiceCachingServiceProvider" --tag="config"`

This will create a `config/laravel-service-caching.php` file in your application, allowing you to configure the default behavior of the package.

````
// config/laravel-service-caching.php

return [  
    // The default cache driver to use. 'null' will use Laravel's default.  
    'driver' => env('SERVICE_CACHE_DRIVER'),
    
    // The default cache duration in seconds. (Default: 600 seconds / 10 minutes). Pass `duration` as **null or 0** in the options array to cache the result forever
    'cache_duration_in_seconds' => 600,

    // The attribute on the User model to use for unique user-based caching.  
    'user_identifier_key' => 'id',  
];
````

## **Usage**

### **1. Implement the Contract**

First, your service class must implement the `Davealex\LaravelServiceCaching\Contracts\CacheableServiceInterface`. This is a simple **marker interface** that doesn't require you to implement any methods.

````
<?php

namespace App\Services;

use Davealex\LaravelServiceCaching\Contracts\CacheableServiceInterface;

class UserService implements CacheableServiceInterface  
{  
    /**  
    * A method that retrieves data you want to cache.  
    */  
    public function getActiveUsers(string $role = 'editor'): array  
    {  
        // This is a potentially slow database query or API call.  
        // It will only be executed if the data is not in the cache.  
        return User::where('status', 'active')
            ->where('role', $role)
            ->get()
            ->toArray();  
    }  
}
````

### **2. Caching Service Data**

Now, you can use the `LaravelServiceCaching` service (either through dependency injection or the Facade) to cache the results of your service methods.

The `get()` method is the primary method you will use.

````
use Davealex\LaravelServiceCaching\LaravelServiceCaching; // or use the Facade  
use App\Services\UserService;

class UserController extends Controller  
{  
    public function __construct(  
        private LaravelServiceCaching $cachingService,  
        private UserService $userService  
    ) {}

    public function index()  
    {  
        // The 'getActiveUsers' method will only be called the first time.  
        // Subsequent requests with the same URL query parameters will  
        // return the cached data.
        $users = $this->cachingService->get(  
            $this->userService,  
            'getActiveUsers'  
        );

        return response()->json($users);  
    }  
}
````

### **3. Using Options**

The third argument of the `get()` method is an `$options array`, which allows you to customize the caching behavior per call.

#### **Caching Per User**

To make the cache unique to the currently authenticated user, `set unique_to_user` to `true`.
````
$userData = $this->cachingService->get(  
    $this->reportService,  
    'generateUserReport',  
    [], // Method arguments  
    ['unique_to_user' => true] // Options  
);
````
#### **Custom Duration**

Set a custom cache duration (in seconds).
````
$data = $this->cachingService->get(  
    $this->someService,  
    'getInfrequentData',  
    [],  
    ['duration' => 3600] // Cache for 1 hour  
);
````
#### **Additional Parameters**

If your caching logic depends on factors outside the URL query string, you can add them to the cache key generation using the params key.
````
$data = $this->cachingService->get(  
    $this->productService,  
    'getProducts',  
    [],  
    ['params' => ['region' => 'us-east-1']]  
);
````

### **4. Clearing the Cache**

You can clear all cached data associated with a specific service using the `clear()` method.
````
// Clear all cached results for UserService  
$this->cachingService->clear($this->userService);
````
This will flush all cache entries for the service, whether you are using a taggable driver or not.

## **Testing**

`composer test`

### **Security**

If you discover any security related issues, please email [daveabiola@gmail.com](mailto:daveabiola@gmail.com) instead of using the issue tracker.

### **Credits**

* [David Olaleye](https://github.com/davealex)
* All Contributors

## **License**

The MIT License (MIT). Please see [License File](https://www.google.com/search?q=LICENSE) for more information.
