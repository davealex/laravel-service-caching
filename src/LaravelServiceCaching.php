<?php

namespace Davealex\LaravelServiceCaching;

use Davealex\LaravelServiceCaching\Contracts\CacheableServiceInterface;
use Davealex\LaravelServiceCaching\Exceptions\InvalidDataRetrievalMethodException;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Psr\SimpleCache\InvalidArgumentException;

class LaravelServiceCaching
{
    /**
     * The cache repository instance.
     * @var \Illuminate\Cache\Repository|Repository
     */
    protected \Illuminate\Cache\Repository|Repository $cache;

    /**
     * Flag indicating if the current cache driver supports tagging.
     * @var bool
     */
    protected bool $taggingSupported = false;

    /**
     * @param ConfigRepository $config
     * @param Request $request The application's current Request instance.
     * @param CacheManager $cacheManager
     */
    public function __construct(
        protected ConfigRepository $config,
        protected Request          $request,
        CacheManager               $cacheManager
    )
    {
        $this->cache = $cacheManager->driver(
            $this->config->get('laravel-service-caching.driver')
        );

        // Check if the store supports tags.
        // The default 'file' and 'database' drivers do not.
        $this->taggingSupported = method_exists($this->cache->getStore(), 'tags');
    }

    /**
     * Retrieve data, from the cache if available, or execute the service method and cache the result.
     *
     * @param CacheableServiceInterface $service The service object containing the method to execute.
     * @param string $methodName The name of the method to call on the service.
     * @param array $methodArguments Arguments to pass to the service method.
     * @param array $options Caching options.
     * - 'unique_to_user' (bool): Make the cache unique to the authenticated user. Default: false.
     * - 'duration' (int|\DateInterval|null): Cache duration in seconds. Uses config default if null, or caches forever if set to 0.
     * @return mixed
     * @throws InvalidDataRetrievalMethodException|InvalidArgumentException
     */
    public function get(
        CacheableServiceInterface $service,
        string                    $methodName,
        array                     $methodArguments = [],
        array                     $options = []
    ): mixed
    {
        $this->validateMethod($service, $methodName);

        $options = $this->prepareOptions($options);
        $cacheKey = $this->generateCacheKey($service, $methodName, $options);
        $closure = fn() => $service->{$methodName}(...$methodArguments);
        $duration = $options['duration'];
        $shouldCacheForever = is_null($duration) || $duration === 0;

        if ($this->taggingSupported) {
            $serviceTag = $this->getServiceCacheTag($service);
            $taggedCache = $this->cache->tags($serviceTag);

            if ($shouldCacheForever) {
                $data = $taggedCache->rememberForever($cacheKey, $closure);
            } else {
                $data = $taggedCache->remember($cacheKey, $duration, $closure);
            }
        } else {
            // Fallback for drivers that don't support tagging.
            if ($shouldCacheForever) {
                if (!$this->cache->has($cacheKey)) {
                    $value = $closure->bindTo($service)();
                    $this->cache->forever($cacheKey, $value);
                    $data = $value;
                } else {
                    $data = $this->cache->get($cacheKey);
                }
            } else {
                $data = $this->cache->remember(
                    $cacheKey,
                    $duration,
                    $closure->bindTo($service)
                );
            }

            // Track the key only when using non-tagged drivers.
            $this->trackCacheKey($service, $cacheKey);
        }

        return $data;
    }

    /**
     * Remove all cached data for a given service.
     *
     * @param CacheableServiceInterface $service
     * @return bool
     * @throws InvalidArgumentException
     */
    public function clear(CacheableServiceInterface $service): bool
    {
        $serviceTag = $this->getServiceCacheTag($service);

        if ($this->taggingSupported) {
            return $this->cache->tags($serviceTag)->flush();
        }

        // Fallback for non-tagged drivers.
        $trackingKey = $this->getServiceTrackingKey($service);
        $trackedKeys = $this->cache->get($trackingKey, []);

        if (!empty($trackedKeys)) {
            $this->cache->deleteMultiple(array_values($trackedKeys));
        }

        $this->cache->forget($trackingKey);

        return true;
    }

    /**
     * Prepare the final options array by merging defaults.
     */
    private function prepareOptions(array $options): array
    {
        return array_merge([
            'unique_to_user' => false,
            // If the user explicitly passes 'duration' => null, it overrides this default, enabling forever caching.
            'duration' => $this->config->get('laravel-service-caching.cache_duration_in_seconds'),
            'params' => [],
        ], $options);
    }

    /**
     * Generate a unique cache key based on the request, service, and options.
     */
    private function generateCacheKey(CacheableServiceInterface $service, string $methodName, array $options): string
    {
        $request = $this->request;

        $params = array_merge($request->query(), $options['params']);

        if ($options['unique_to_user'] && ($user = $request->user() ?? auth()->user())) {
            $key = $this->config->get('laravel-service-caching.user_identifier_key', 'id');
            $userId = $user->getAuthIdentifier() ?? $user->{$key};
            $params['user_id'] = $userId;
        }

        ksort($params);

        $keyParts = [
            'service' => get_class($service),
            'method' => $methodName,
            'url' => $request->path(),
            'params' => http_build_query($params),
        ];

        return 'service-cache:' . sha1(implode('|', $keyParts));
    }

    /**
     * Get the tag used for a specific service's cache entries.
     */
    private function getServiceCacheTag(CacheableServiceInterface $service): string
    {
        return sha1(get_class($service));
    }

    /**
     * Get the key used for tracking all cache keys for a service.
     * (Used for non-tagged cache drivers)
     */
    private function getServiceTrackingKey(CacheableServiceInterface $service): string
    {
        return 'service-cache-tracking:' . $this->getServiceCacheTag($service);
    }

    /**
     * Associates a generated cache key with its service for later clearing.
     * (Used for non-tagged cache drivers)
     */
    private function trackCacheKey(CacheableServiceInterface $service, string $cacheKey): void
    {
        $trackingKey = $this->getServiceTrackingKey($service);
        $trackedKeys = $this->cache->get($trackingKey, []);

        // map cacheKey => cacheKey for easy deletion later
        $trackedKeys[$cacheKey] = $cacheKey;

        $this->cache->forever($trackingKey, $trackedKeys);
    }

    /**
     * Validate that the method exists and is callable on the service object.
     *
     * @throws InvalidDataRetrievalMethodException
     */
    private function validateMethod(CacheableServiceInterface $service, string $methodName): void
    {
        if (!method_exists($service, $methodName) || !is_callable([$service, $methodName])) {
            throw new InvalidDataRetrievalMethodException("The method [$methodName] is not callable on service [" . get_class($service) . "].");
        }
    }
}
