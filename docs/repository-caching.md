# Explicit repository caching in the PraiseCharts fork

## Default: no repository-result caching

`Apiato\Core\Abstracts\Repositories\Repository` no longer implements
`CacheableInterface` or uses Prettus's cache trait. Its reads use the database,
even when `repository.cache.enabled` is true.

The parent still provides model lookup, request criteria, eager-loading support,
and Apiato's pagination rules. Existing `skipCache()` calls return the uncached
repository unchanged; `skipCache(false)` does not opt it into caching.

Generated repositories remain uncached. Do not add caching to the application's
common repository parent: that would restore implicit caching for every child.

## Opt in on a concrete repository

Use the **Apiato adapter trait**, not Prettus's trait directly, to preserve
pagination limits and `limit=0` handling:

```php
use Apiato\Core\Traits\CacheableRepository;
use App\Ship\Parents\Repositories\Repository as ParentRepository;
use Prettus\Repository\Contracts\CacheableInterface;

class CountryRepository extends ParentRepository implements CacheableInterface
{
    use CacheableRepository;

    // Existing model selection and other repository settings stay here.
}
```

This is a class-level opt-in. The interface marks the repository for cache
cleanup; the trait supplies the cached read methods. Both are required. The adapter
rejects cached reads if the interface is missing, rather than storing values that
would escape the cleanup listener.

The global master switch remains independent:

| Class | Master switch | Result |
| --- | --- | --- |
| Ordinary repository | Off or on | No automatic repository-result caching |
| Explicitly cacheable repository | Off | Database reads |
| Explicitly cacheable repository | On | Eligible methods use the configured cache |

Prettus's existing method policies, TTL settings, `skipCache()`, and the request
`skipCache` parameter still apply to opted-in classes. They are not how a class
opts in. Explicit `Cache::remember()` calls in Tasks are another option when a
particular read needs its own result key and lifetime.

The adapter declares `paginate()` with Apiato's compatible return type and applies
the maximum limit, request-limit precedence, pagination-disable policy, and alternate
pagination method. Using Prettus's trait directly can fail PHP's method-signature
check; adding only a return-type wrapper would still check those rules too late.
The adapter applies them before choosing a cache key, so a hit cannot bypass a
reduced maximum or a newly disabled unpaginated read. A loader may pass through the same pagination policy again
on a cache miss; that does not execute the database query twice.

## Cache cleanup

Apiato registers a wrapper for Prettus's `CleanCacheRepository` listener. It skips
ordinary repositories, so their writes do not enumerate the cache-key registry
or delete cached results. Explicitly cacheable repositories retain Prettus's
normal create/update/delete cleanup behavior and cleanup configuration.

This does **not** make Prettus invalidation account-surgical or transaction-safe.
Its repository-class key registry, URL/criteria key construction, dependency
coverage, and write-time cleanup semantics remain unchanged for opted-in classes.
Review related Models, direct/bulk writes, transaction timing, and stale fills
before choosing automatic caching for a repository.

Applications that replace the cleanup listener must add the same explicit
`CacheableInterface` guard. Apiato's wrapper cannot override an application's
later binding or its direct invalidation calls.

## Consumer migration is required

This changes the core repository contract. Do not update a live application's
Composer lock without checking its integration first:

1. Find repositories that declare only `implements CacheableInterface` and relied
   on the old parent for the methods. Add the adapter trait or remove the explicit
   interface. An interface-only declaration will otherwise fail when loaded.
   The API's `ArrangementRepository` is one such caller at the reviewed revision.
2. Find repositories with cache settings but no explicit interface/trait. Decide
   which should opt in; their old cache settings alone no longer enable caching.
3. Audit custom cleanup listeners, repository-generation/key overrides, explicit
   invalidation Tasks, and direct uses of cache-only repository methods. A trait
   on the concrete class can override inherited methods such as `getCacheKey()`;
   do not assume an application's former generation policy still applies.
4. Preserve mixed-version read/write consistency during deployment. Existing
   cached readers need invalidation until those readers are retired. Do not flush
   shared sessions, challenges, locks, or other unrelated cache state.
5. Test pagination, criteria, Model events, transactions and intended cache reuse.
   Measure query counts and load before expanding the rollout. No production
   cache enablement or performance gain is implied by the source change.

The global cache switch does not need to be removed. Its enabled state permits
explicitly cacheable classes to cache; it cannot turn ordinary repositories into
cacheable classes.

## Focused regression tests

`tests/Unit/Repositories` uses a private in-memory SQLite database and an instrumented
array cache store. It does not boot a consuming application or use its databases,
Redis, workers, or runtime settings. Coverage includes uncached defaults with the
master switch on, class-level opt-in, cleanup, cache bypass, pagination, criteria,
and eager loading. A real shared-store/multi-worker rollout needs separate tests.
