<?php

namespace Apiato\Core\Tests\Unit\Repositories;

use Apiato\Core\Abstracts\Repositories\Repository;
use Apiato\Core\Providers\ApiatoServiceProvider;
use Apiato\Core\Traits\CacheableRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheStore;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Prettus\Repository\Contracts\CacheableInterface;
use Prettus\Repository\Contracts\CriteriaInterface;
use Prettus\Repository\Contracts\RepositoryInterface;
use Prettus\Repository\Helpers\CacheKeys;
use Prettus\Repository\Listeners\CleanCacheRepository;

abstract class RepositoryTestCase extends TestCase
{
    protected Application $app;
    protected Capsule $database;
    protected CountingArrayStore $store;
    protected string $storage;

    private Container $previousContainer;
    private $previousFacadeApplication;
    private $previousConnectionResolver;
    private $previousDispatcher;
    private array $previousStatics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousConnectionResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousStatics = [];
        foreach ([[CacheKeys::class, 'keys'], [AbstractPaginator::class, 'currentPageResolver'], [AbstractPaginator::class, 'currentPathResolver']] as [$class, $property]) {
            $reflection = new \ReflectionProperty($class, $property);
            $this->previousStatics[] = [$reflection, $reflection->getValue()];
        }
        (new \ReflectionProperty(CacheKeys::class, 'keys'))->setValue(null, null);

        $this->app = new Application(dirname(__DIR__, 3));
        $this->app->instance('config', new Config([
            'apiato' => ['hash-id' => false],
            'repository' => [
                'pagination' => ['limit' => 2, 'skip' => false],
                'cache' => [
                    'enabled' => true, 'minutes' => 30, 'repository' => 'cache',
                    'allowed' => ['only' => null, 'except' => null],
                    'clean' => ['enabled' => true, 'on' => ['create' => true, 'update' => true, 'delete' => true]],
                    'params' => ['skipCache' => 'skipCache'],
                ],
            ],
        ]));
        $this->request();
        $this->storage = sys_get_temp_dir() . '/apiato-repository-' . bin2hex(random_bytes(8));
        mkdir($this->storage . '/framework/cache', 0777, true);
        $this->app->useStoragePath($this->storage);
        $this->store = new CountingArrayStore(true);
        $this->app->instance('cache', new CacheStore($this->store));
        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);
        (new RepositoryTestServiceProvider($this->app))->register();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        foreach (['Created', 'Updated', 'Deleted'] as $action) {
            $events->listen('Prettus\\Repository\\Events\\RepositoryEntity' . $action, fn ($event) => $this->app->make(CleanCacheRepository::class)->handle($event));
        }

        $this->database = new Capsule($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setEventDispatcher($events);
        $this->database->bootEloquent();
        $connection = $this->database->getConnection();
        $connection->statement('CREATE TABLE repository_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $connection->statement('CREATE TABLE repository_records (id INTEGER PRIMARY KEY, name TEXT NOT NULL, group_id INTEGER NOT NULL)');
        $connection->table('repository_groups')->insert([['id' => 1, 'name' => 'First group'], ['id' => 2, 'name' => 'Second group']]);
        for ($id = 1; $id <= 5; ++$id) {
            $connection->table('repository_records')->insert(['id' => $id, 'name' => 'Record ' . $id, 'group_id' => $id <= 3 ? 1 : 2]);
        }
        $connection->enableQueryLog();
        Paginator::currentPageResolver(fn () => (int) $this->app['request']->input('page', 1));
        Paginator::currentPathResolver(fn () => 'http://localhost/records');
    }

    protected function tearDown(): void
    {
        try {
            $this->database->getConnection()->disconnect();
            if (null === $this->previousConnectionResolver) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($this->previousConnectionResolver);
            }
            if (null === $this->previousDispatcher) {
                Model::unsetEventDispatcher();
            } else {
                Model::setEventDispatcher($this->previousDispatcher);
            }
            foreach ($this->previousStatics as [$reflection, $value]) {
                $reflection->setValue(null, $value);
            }
            $registry = $this->storage . '/framework/cache/repository-cache-keys.json';
            if (is_file($registry)) {
                unlink($registry);
            }
            rmdir($this->storage . '/framework/cache');
            rmdir($this->storage . '/framework');
            rmdir($this->storage);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacadeApplication);
            Container::setInstance($this->previousContainer);
            parent::tearDown();
        }
    }

    protected function request(array $query = []): void
    {
        $this->app->instance('request', Request::create('http://localhost/records', 'GET', $query));
    }

    protected function repository(bool $cacheable = false): Repository
    {
        return $cacheable ? new CachedRecordRepository($this->app) : new RecordRepository($this->app);
    }

    protected function property(Repository $repository, string $name, mixed $value): void
    {
        (new \ReflectionProperty($repository, $name))->setValue($repository, $value);
    }

    protected function selectCount(): int
    {
        return count(array_filter($this->database->getConnection()->getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'select')));
    }
}

class RepositoryTestServiceProvider extends ApiatoServiceProvider
{
    public array $serviceProviders = [];
    public \Closure|null $registerApplicationProviders = null;

    public function runLoaderRegister(): void
    {
        if (null !== $this->registerApplicationProviders) {
            ($this->registerApplicationProviders)($this->app);
        }
    }
}

class RecordRepository extends Repository
{
    protected $fieldSearchable = ['name' => 'like'];

    public function model(): string
    {
        return RepositoryRecord::class;
    }
}

class CachedRecordRepository extends RecordRepository implements CacheableInterface
{
    use CacheableRepository;
}

class TraitOnlyRecordRepository extends RecordRepository
{
    use CacheableRepository;
}

class RepositoryRecord extends Model
{
    public $timestamps = false;
    protected $table = 'repository_records';
    protected $guarded = [];

    public function group(): BelongsTo
    {
        return $this->belongsTo(RepositoryGroup::class, 'group_id');
    }
}

class RepositoryGroup extends Model
{
    public $timestamps = false;
    protected $table = 'repository_groups';
    protected $guarded = [];
}

class FirstGroupCriteria implements CriteriaInterface
{
    public function apply($model, RepositoryInterface $repository)
    {
        return $model->where('group_id', 1);
    }
}

class CountingArrayStore extends ArrayStore
{
    public int $reads = 0;
    public int $writes = 0;
    public int $deletes = 0;

    public function get($key)
    {
        ++$this->reads;

        return parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        ++$this->writes;

        return parent::put($key, $value, $seconds);
    }

    public function forget($key)
    {
        ++$this->deletes;

        return parent::forget($key);
    }
}
