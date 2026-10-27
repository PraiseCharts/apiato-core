<?php

namespace Apiato\Core\Tests\Unit\Repositories;

use Apiato\Core\Listeners\CleanRepositoryCache;
use Apiato\Core\Providers\ApiatoServiceProvider;
use Apiato\Core\Providers\RepositoryServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Prettus\Repository\Contracts\CacheableInterface;
use Prettus\Repository\Listeners\CleanCacheRepository;

class RepositoryCachingTest extends RepositoryTestCase
{
    public function testDefaultRepositoryDoesNotInheritCaching(): void
    {
        $this->assertNotInstanceOf(CacheableInterface::class, $this->repository());
        $this->assertInstanceOf(CacheableInterface::class, $this->repository(true));
    }

    public function testCacheTraitRequiresTheExplicitInterface(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must implement CacheableInterface');

        (new TraitOnlyRecordRepository($this->app))->find(1);
    }

    public static function readMethods(): array
    {
        return [
            'find' => ['find', [1]],
            'all' => ['all', []],
            'find by field' => ['findByField', ['group_id', 1]],
            'find where' => ['findWhere', [['group_id' => 1]]],
            'paginate' => ['paginate', [2]],
        ];
    }

    #[DataProvider('readMethods')]
    public function testDefaultReadAlwaysQueriesWithoutCacheOrRegistry(string $method, array $arguments): void
    {
        $repository = $this->repository();
        $repository->$method(...$arguments);
        $firstCount = $this->selectCount();
        $repository->$method(...$arguments);

        $this->assertGreaterThan(0, $firstCount);
        $this->assertSame($firstCount * 2, $this->selectCount());
        $this->assertSame(0, $this->store->reads);
        $this->assertSame(0, $this->store->writes);
        $this->assertFileDoesNotExist($this->storage . '/framework/cache/repository-cache-keys.json');
    }

    #[DataProvider('readMethods')]
    public function testOptedInReadReusesTheConfiguredCache(string $method, array $arguments): void
    {
        $repository = $this->repository(true);
        $repository->$method(...$arguments);
        $firstCount = $this->selectCount();
        $repository->$method(...$arguments);

        $this->assertGreaterThan(0, $firstCount);
        $this->assertSame($firstCount, $this->selectCount());
        $this->assertGreaterThan(0, $this->store->reads);
        $this->assertGreaterThan(0, $this->store->writes);
    }

    public function testOptedInReadReusesCacheAcrossRepositoryInstances(): void
    {
        $this->repository(true)->find(1);
        $this->repository(true)->find(1);

        $this->assertSame(1, $this->selectCount());
    }

    public static function modes(): array
    {
        return ['uncached' => [false], 'explicitly cached' => [true]];
    }

    #[DataProvider('modes')]
    public function testGetByCriteriaFollowsTheClassOptIn(bool $cacheable): void
    {
        $first = $this->repository($cacheable)->getByCriteria(new FirstGroupCriteria());
        $second = $this->repository($cacheable)->getByCriteria(new FirstGroupCriteria());

        $this->assertSame([1, 2, 3], $first->pluck('id')->all());
        $this->assertSame([1, 2, 3], $second->pluck('id')->all());
        $this->assertSame($cacheable ? 1 : 2, $this->selectCount());
    }

    public function testMasterSwitchStillDisablesExplicitCaching(): void
    {
        $this->app['config']->set('repository.cache.enabled', false);
        $repository = $this->repository(true);
        $repository->find(1);
        $repository->find(1);

        $this->assertSame(2, $this->selectCount());
        $this->assertSame(0, $this->store->reads);
        $this->assertSame(0, $this->store->writes);
    }

    public function testLegacySkipCacheCallsCannotEnableDefaultCaching(): void
    {
        $repository = $this->repository();
        $this->assertSame($repository, $repository->skipCache()->skipCache(false));
        $repository->find(1);
        $repository->find(1);

        $this->assertSame(2, $this->selectCount());
        $this->assertSame(0, $this->store->reads);
    }

    public function testExplicitCachingStillSupportsSkipAndResume(): void
    {
        $repository = $this->repository(true);
        $repository->skipCache()->find(1);
        $repository->find(1);
        $this->assertSame(2, $this->selectCount());
        $this->assertSame(0, $this->store->reads);

        $repository->skipCache(false)->find(1);
        $repository->find(1);
        $this->assertSame(3, $this->selectCount());
        $this->assertGreaterThan(0, $this->store->writes);
    }

    public function testRequestCanStillSkipExplicitCaching(): void
    {
        $this->request(['skipCache' => true]);
        $repository = $this->repository(true);
        $repository->find(1);
        $repository->find(1);

        $this->assertSame(2, $this->selectCount());
        $this->assertSame(0, $this->store->reads);
    }

    public function testDefaultWritesDoNotCreateOrCleanARepositoryCacheRegistry(): void
    {
        $repository = $this->repository();
        $repository->create(['id' => 6, 'name' => 'New record', 'group_id' => 1]);
        $repository->update(['name' => 'Updated record'], 6);
        $repository->delete(6);

        $this->assertNull($this->database->getConnection()->table('repository_records')->find(6));
        $this->assertSame(0, $this->store->reads);
        $this->assertSame(0, $this->store->writes);
        $this->assertSame(0, $this->store->deletes);
        $this->assertFileDoesNotExist($this->storage . '/framework/cache/repository-cache-keys.json');
    }

    public function testDefaultWritesStillPublishModelAndRepositoryEvents(): void
    {
        $counts = [];
        foreach (['created', 'updated', 'deleted'] as $action) {
            $this->app['events']->listen('eloquent.' . $action . ': ' . RepositoryRecord::class, function () use (&$counts, $action): void {
                $counts['model:' . $action] = ($counts['model:' . $action] ?? 0) + 1;
            });
            $this->app['events']->listen('Prettus\\Repository\\Events\\RepositoryEntity' . ucfirst($action), function () use (&$counts, $action): void {
                $counts['repository:' . $action] = ($counts['repository:' . $action] ?? 0) + 1;
            });
        }
        $repository = $this->repository();
        $repository->create(['id' => 6, 'name' => 'New record', 'group_id' => 1]);
        $repository->update(['name' => 'Updated record'], 6);
        $repository->delete(6);

        foreach (['created', 'updated', 'deleted'] as $action) {
            $this->assertSame(1, $counts['model:' . $action]);
            $this->assertSame(1, $counts['repository:' . $action]);
        }
    }

    public function testExplicitCacheStillInvalidatesOnCreateUpdateAndDelete(): void
    {
        $repository = $this->repository(true);
        $this->assertCount(5, $repository->all());
        $repository->create(['id' => 6, 'name' => 'New record', 'group_id' => 1]);
        $this->assertCount(6, $repository->all());

        $this->assertSame('Record 1', $repository->find(1)->name);
        $repository->update(['name' => 'Updated record'], 1);
        $this->assertSame('Updated record', $repository->find(1)->name);

        $repository->delete(6);
        $this->assertCount(5, $repository->all());
        $this->assertGreaterThan(0, $this->store->deletes);
    }

    public function testUncachedWritesDoNotEvictAnotherRepositoryCache(): void
    {
        $this->repository(true)->find(2);
        $deletes = $this->store->deletes;
        $this->repository()->update(['name' => 'Uncached write'], 1);
        $this->database->getConnection()->flushQueryLog();

        $this->assertSame('Record 2', $this->repository(true)->find(2)->name);
        $this->assertSame(0, $this->selectCount());
        $this->assertSame($deletes, $this->store->deletes);
    }

    public function testCacheCleanupProviderIsRegisteredAndBound(): void
    {
        $provider = new ApiatoServiceProvider($this->app);
        $this->assertContains(RepositoryServiceProvider::class, $provider->serviceProviders);
        $this->assertInstanceOf(CleanRepositoryCache::class, $this->app->make(CleanCacheRepository::class));
    }
}
