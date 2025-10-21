<?php

namespace Davealex\LaravelServiceCaching\Tests;

use Davealex\LaravelServiceCaching\Contracts\CacheableServiceInterface;
use Davealex\LaravelServiceCaching\Exceptions\InvalidDataRetrievalMethodException;
use Davealex\LaravelServiceCaching\LaravelServiceCaching;
use Davealex\LaravelServiceCaching\LaravelServiceCachingServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Orchestra\Testbench\TestCase;
use Psr\SimpleCache\InvalidArgumentException;

class LaravelServiceCachingTest extends TestCase
{
    use DatabaseTransactions;

    protected $cachingService;

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // 1. Configure the Database Connection
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 2. Configure Cache to use the Database driver
        $app['config']->set('cache.default', 'database');
        $app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => 'testbench',
        ]);

        // 3. Set the package's cache driver to 'database'
        $app['config']->set('laravel-service-caching.driver', 'database');
    }

    /**
     * Setup the database schema for the tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations for the cache table
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->unique();
            $table->text('value');
            $table->integer('expiration');
        });

        // Run migrations for the users table (required for actingAs)
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
        });

        $this->cachingService = $this->app->make(LaravelServiceCaching::class);

        $this->app['config']->set('auth.providers.users.model', User::class);
    }

    protected function getPackageProviders($app): array
    {
        return [LaravelServiceCachingServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_caches_a_service_method_result()
    {
        $mockService = Mockery::mock(TestService::class);
        $mockService->shouldReceive('getUsers')->once()->andReturn(['id' => 1, 'name' => 'David']);

        $result1 = $this->cachingService->get($mockService, 'getUsers');
        $result2 = $this->cachingService->get($mockService, 'getUsers');

        $this->assertEquals(['id' => 1, 'name' => 'David'], $result1);
        $this->assertEquals($result1, $result2);
    }

    /** @test */
    public function it_clears_the_cache_for_a_service()
    {
        $mockService = Mockery::mock(TestService::class);
        $mockService->shouldReceive('getUsers')->twice()->andReturnValues([
            ['key' => 'initial'],
            ['key' => 'cleared'],
        ]);

        $this->cachingService->get($mockService, 'getUsers');
        $this->cachingService->get($mockService, 'getUsers');

        // Clear the cache
        $this->cachingService->clear($mockService);

        $result = $this->cachingService->get($mockService, 'getUsers');

        $this->assertEquals(['key' => 'cleared'], $result);
    }

    /** @test
     * @throws InvalidDataRetrievalMethodException
     * @throws BindingResolutionException|InvalidArgumentException
     */
    public function it_creates_a_unique_cache_for_each_user()
    {
        $mockService = Mockery::mock(TestService::class);
        $mockService->shouldReceive('getUserDashboard')->twice()->andReturnValues([
            ['data' => 'User 1 data'],
            ['data' => 'User 2 data']
        ]);

        $user1 = new User(['id' => 3]);
        $user2 = new User(['id' => 4]);

        $this->actingAs($user1);
        $result1 = $this->cachingService->get($mockService, 'getUserDashboard', [], ['unique_to_user' => true]);

        $this->cachingService = $this->app->make(LaravelServiceCaching::class);

        $this->actingAs($user2);
        $result2 = $this->cachingService->get($mockService, 'getUserDashboard', [], ['unique_to_user' => true]);

        $this->assertEquals(['data' => 'User 1 data'], $result1);
        $this->assertEquals(['data' => 'User 2 data'], $result2);
    }

    /** @test */
    public function it_differentiates_cache_based_on_url_parameters()
    {
        $mockService = Mockery::mock(TestService::class);
        $mockService->shouldReceive('getUsers')->twice()->andReturnValues([
            ['page' => 1],
            ['page' => 2]
        ]);

        $this->get('/?page=1');
        $this->cachingService = $this->app->make(LaravelServiceCaching::class);
        $result1 = $this->cachingService->get($mockService, 'getUsers');

        $this->get('/?page=2');
        $this->cachingService = $this->app->make(LaravelServiceCaching::class);
        $result2 = $this->cachingService->get($mockService, 'getUsers');

        $this->assertEquals(['page' => 1], $result1);
        $this->assertEquals(['page' => 2], $result2);
    }

    /** @test */
    public function it_works_correctly_with_non_taggable_cache_driver()
    {
        $this->app['config']->set('cache.default', 'database');
        $this->app['config']->set('laravel-service-caching.driver', 'database');

        Cache::store('database')->flush();

        $cachingService = $this->app->make(LaravelServiceCaching::class);

        $mockService = Mockery::mock(TestService::class);
        $mockService->shouldReceive('getUsers')->twice()->andReturnValues([
            ['key' => 'initial_data'],
            ['key' => 'cleared_data'],
        ]);

        $result1 = $cachingService->get($mockService, 'getUsers');
        $cachingService->get($mockService, 'getUsers');
        $cachingService->clear($mockService);

        $result2 = $cachingService->get($mockService, 'getUsers');

        $this->assertEquals(['key' => 'initial_data'], $result1);
        $this->assertEquals(['key' => 'cleared_data'], $result2);
    }
}

class TestService implements CacheableServiceInterface
{
    public function getUsers()
    {
        return [];
    }

    public function getUserDashboard()
    {
        return [];
    }
}

class User extends \Illuminate\Foundation\Auth\User implements Authenticatable
{
    protected $guarded = [];
}

