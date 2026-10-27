<?php

namespace Apiato\Core\Traits;

use Prettus\Repository\Contracts\CacheableInterface;
use Prettus\Repository\Traits\CacheableRepository as PrettusCacheableRepository;

/**
 * Explicit repository caching that retains Apiato's pagination rules.
 * Repositories using this trait must also implement CacheableInterface.
 */
trait CacheableRepository
{
    use PrettusCacheableRepository {
        paginate as private paginateFromCache;
        allowedCache as private cacheAllowedByConfiguration;
    }

    protected $cacheSkip = false;

    protected function allowedCache($method)
    {
        if (!$this instanceof CacheableInterface) {
            throw new \LogicException('Repositories using the cache trait must implement CacheableInterface.');
        }

        return $this->cacheAllowedByConfiguration($method);
    }

    public function paginate($limit = null, $columns = ['*'], $method = 'paginate'): mixed
    {
        return $this->paginateWithOptions(
            $limit,
            $columns,
            fn ($limit) => $this->paginateFromCache($limit, $columns, $method),
        );
    }
}
