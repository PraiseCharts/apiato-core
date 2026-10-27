<?php

namespace Apiato\Core\Tests\Unit\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;

class RepositoryPaginationTest extends RepositoryTestCase
{
    public static function modes(): array
    {
        return ['uncached' => [false], 'explicitly cached' => [true]];
    }

    #[DataProvider('modes')]
    public function testConfiguredPaginationDefaultIsPreserved(bool $cacheable): void
    {
        $result = $this->repository($cacheable)->paginate();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(2, $result->perPage());
        $this->assertSame(5, $result->total());
        $this->assertCount(2, $result->items());
    }

    #[DataProvider('modes')]
    public function testRequestLimitAndPageArePreserved(bool $cacheable): void
    {
        $this->request(['limit' => 2, 'page' => 2]);
        $result = $this->repository($cacheable)->paginate();

        $this->assertSame(2, $result->currentPage());
        $this->assertSame(2, $result->perPage());
        $this->assertSame([3, 4], $result->getCollection()->pluck('id')->all());
    }

    #[DataProvider('modes')]
    public function testExplicitLimitTakesPriorityOverRequestLimit(bool $cacheable): void
    {
        $this->request(['limit' => 4]);

        $this->assertSame(1, $this->repository($cacheable)->paginate(1)->perPage());
    }

    #[DataProvider('modes')]
    public function testMaximumAppliesToExplicitAndRequestLimits(bool $cacheable): void
    {
        $repository = $this->repository($cacheable);
        $this->property($repository, 'maxPaginationLimit', 2);
        $this->request(['limit' => 5]);

        $this->assertSame(2, $repository->paginate(4)->perPage());
        $this->assertSame(2, $repository->paginate()->perPage());
    }

    #[DataProvider('modes')]
    public function testZeroLimitCanReturnAllWhenAllowed(bool $cacheable): void
    {
        $repository = $this->repository($cacheable);
        $this->property($repository, 'maxPaginationLimit', 2);
        $this->app['config']->set('repository.pagination.skip', true);
        $this->request(['limit' => '0']);
        $result = $repository->paginate();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(5, $result);
    }

    #[DataProvider('modes')]
    public function testLocalSkipPolicyOverridesGlobalPolicy(bool $cacheable): void
    {
        $repository = $this->repository($cacheable);
        $this->app['config']->set('repository.pagination.skip', true);
        $this->property($repository, 'allowDisablePagination', false);
        $this->assertInstanceOf(LengthAwarePaginator::class, $repository->paginate(0));

        $this->app['config']->set('repository.pagination.skip', false);
        $this->property($repository, 'allowDisablePagination', true);
        $this->assertInstanceOf(Collection::class, $repository->paginate(0));
    }

    #[DataProvider('modes')]
    public function testSimplePaginationStillUsesTheLimitPolicy(bool $cacheable): void
    {
        $repository = $this->repository($cacheable);
        $this->property($repository, 'maxPaginationLimit', 2);
        $result = $repository->simplePaginate(5);

        $this->assertInstanceOf(Paginator::class, $result);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(2, $result->perPage());
        $this->assertCount(2, $result->items());
    }

    #[DataProvider('modes')]
    public function testRequestCriteriaArePreserved(bool $cacheable): void
    {
        $this->request(['search' => 'name:Record 2', 'searchJoin' => 'and']);
        $repository = $this->repository($cacheable);
        $result = $repository->addRequestCriteria()->all();

        $this->assertSame([2], $result->pluck('id')->all());
        $repository->removeRequestCriteria();
        $this->assertCount(5, $repository->all());
    }

    #[DataProvider('modes')]
    public function testScopeAndEagerLoadingArePreserved(bool $cacheable): void
    {
        $result = $this->repository($cacheable)
            ->scopeQuery(fn ($query) => $query->where('group_id', 2))
            ->with(['group'])
            ->paginate(2);

        $this->assertSame(2, $result->total());
        $this->assertSame([4, 5], $result->getCollection()->pluck('id')->all());
        $this->assertTrue($result->items()[0]->relationLoaded('group'));
        $this->assertSame('Second group', $result->items()[0]->group->name);
    }

    public function testCachedPaginationKeysUseTheEffectiveMaximum(): void
    {
        $repository = $this->repository(true);
        $this->property($repository, 'maxPaginationLimit', 2);
        $repository->paginate(4);
        $firstCount = $this->selectCount();
        $result = $repository->paginate(5);

        $this->assertSame(2, $result->perPage());
        $this->assertSame($firstCount, $this->selectCount());
    }

    public function testCachedResultsCannotBypassAReducedMaximum(): void
    {
        $repository = $this->repository(true);
        $this->property($repository, 'maxPaginationLimit', 2);
        $this->assertSame(2, $repository->paginate(5)->perPage());
        $this->property($repository, 'maxPaginationLimit', 1);

        $this->assertSame(1, $repository->paginate(5)->perPage());
    }

    public function testCachedResultsCannotBypassADisabledUnpaginatedRead(): void
    {
        $repository = $this->repository(true);
        $this->app['config']->set('repository.pagination.skip', true);
        $this->assertInstanceOf(Collection::class, $repository->paginate(0));
        $this->app['config']->set('repository.pagination.skip', false);

        $this->assertInstanceOf(LengthAwarePaginator::class, $repository->paginate(0));
    }

    public function testCachedPaginationKeepsDifferentPagesSeparate(): void
    {
        $repository = $this->repository(true);
        $this->request(['page' => 1]);
        $first = $repository->paginate(2);
        $this->request(['page' => 2]);
        $second = $repository->paginate(2);

        $this->assertSame([1, 2], $first->getCollection()->pluck('id')->all());
        $this->assertSame([3, 4], $second->getCollection()->pluck('id')->all());
    }
}
