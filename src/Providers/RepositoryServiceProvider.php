<?php

namespace Apiato\Core\Providers;

use Apiato\Core\Listeners\CleanRepositoryCache;
use Illuminate\Support\ServiceProvider;
use Prettus\Repository\Listeners\CleanCacheRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CleanCacheRepository::class, CleanRepositoryCache::class);
    }
}
