<?php

namespace Apiato\Core\Listeners;

use Prettus\Repository\Contracts\CacheableInterface;
use Prettus\Repository\Events\RepositoryEventBase;
use Prettus\Repository\Listeners\CleanCacheRepository;

class CleanRepositoryCache extends CleanCacheRepository
{
    public function __construct()
    {
        // Do not resolve a cache store for an uncached repository's write.
    }

    public function handle(RepositoryEventBase $event): void
    {
        if (!$event->getRepository() instanceof CacheableInterface) {
            return;
        }

        parent::__construct();
        parent::handle($event);
    }
}
