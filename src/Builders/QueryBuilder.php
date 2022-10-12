<?php

namespace Ions\Builders;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Ions\Exceptions\InvalidSubject;
use Ions\Foundation\Kernel;
use Ions\Foundation\Singleton;
use Ions\Support\DB;
use Ions\Support\Request;
use Ions\Support\Str;
use Ions\Traits\BuilderFields;
use Ions\Traits\BuilderFilters;
use Ions\Traits\BuilderSort;
use Throwable;

class QueryBuilder extends Singleton
{
    use BuilderFields;
    use BuilderFilters;
    use BuilderSort;

    public ?string $connection = null;
    protected Builder $query;
    private QueryBuilderRequest|Request|null $request;
    private int $count = 25;
    private int $offset = 0;

    protected array $withMany = [];
    protected array $with = [];
    protected array $withSole = [];

    protected int $tableTotal;
    protected int $queryLimit;
    protected int $queryOffset;

    /**
     * @param string|Builder $subject
     * @param Request|null $request
     * @return static
     * @throws Throwable
     */
    public static function for(string|Builder $subject, ?Request $request = null): self
    {
        return new static($subject, $request);
    }

    /**
     * @throws Throwable
     */
    public function __construct($subject, $request)
    {
        parent::__construct();
        $this->initializeSubject($subject)
            ->initializeRequest($request ?? Kernel::request());
    }

    /**
     * @param $table
     * @return $this
     * @throws Throwable
     */
    protected function initializeSubject($table): static
    {
        ($table instanceof Builder) ? $subject = $table : $subject = DB::connection($this->connection)->table($table);

        throw_unless($subject instanceof Builder, InvalidSubject::make($subject));

        $this->query = $subject;

        return $this;
    }

    /**
     * @param Request|null $request
     * @return $this
     */
    protected function initializeRequest(?Request $request = null): self
    {
        $this->request = $request ? QueryBuilderRequest::fromRequest($request) : $request;
        return $this;
    }

    protected function tableInfo(): array
    {
        return [
            'total' => $this->tableTotal,
            'limit' => $this->queryLimit,
            'offset' => $this->queryOffset
        ];
    }

    /**
     * @return void
     */
    protected function paging(): void
    {
        $offset_result = max(0, $this->request->get('offset'));
        $maxCount = $this->count;

        if ($this->request->limits()->get($this->query->from)) {
            $limit_field = (int)$this->request->limits()->get($this->query->from);
        } elseif ($this->request->get('limit') === 'all') {
            $limit_field = (int)$this->query->count();
        } elseif ($this->request->get('limit')) {
            $limit_field = (int)$this->request->get('limit');
        } else {
            $limit_field = $maxCount;
        }

        $limit = $limit_field;
        $limit = max($limit, 1);
        $this->count = $limit;
        $this->offset = $offset_result;

        $this->queryLimit = $limit;
        $this->queryOffset = $offset_result;

        $this->query->skip($offset_result)->take($limit);
    }

    /**
     * @param string $pageName
     * @return LengthAwarePaginator
     */
    protected function pagination(string $pageName = 'page'): LengthAwarePaginator
    {
        $limit = $this->request->get('limit', 15);
        $page = $this->request->get('page', 1);
        return $this->query->paginate($limit, ['*'], $pageName, $page);
    }

    /**
     * @param $table
     * @param $first
     * @param string $operator
     * @param string $second
     * @param $wheres
     * @return $this
     */
    public function with($table, $first, $wheres = null, string $operator = '=', string $second = 'id'): static
    {
        $this->with[] = ['table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second, 'wheres' => $wheres];
        return $this;
    }

    /**
     * @param $collection
     * @param $ids
     * @return void
     */
    protected function withRelation($collection, $ids, $is_sole = false): void
    {
        if ($this->with) {
            foreach ($this->with as $item) {
                $includes = $this->request->includes();
                if (!in_array($item['table'], $includes->all(), true)) {
                    continue;
                }

                $columns = $this->getColumns($item);
                $sorts = $this->request->sorts();

                $relation_many = $this->getRelation_many($item, $columns, $ids, $sorts);

                if ($is_sole) {
                    $collection->{$item['table']} = $relation_many->where($item['first'], $item['operator'], $collection->{$item['second']})->toArray();
                } else {
                    $collection->map(static function ($row) use ($item, $relation_many) {
                        if (!isset($row->{$item['second']})) {
                            return null;
                        }
                        $row->{$item['table']} = $relation_many->where($item['first'], $item['operator'], $row->{$item['second']})->toArray();
                        return $row;
                    });
                }
            }
        }
    }

    /**
     * @param mixed $item
     * @param mixed $columns
     * @param $ids
     * @param Collection $sorts
     * @return Collection
     */
    protected function getRelation_many(mixed $item, mixed $columns, $ids, Collection $sorts): Collection
    {
        $relation_query_many = DB::table($item['table'])
            ->select($columns)
            ->whereIn($item['first'], $ids)
            ->where(function ($query) use ($item) {
                if ($item['wheres']) {
                    $item['wheres']($query);
                }
            });
        return $this->handleRelations($sorts, $relation_query_many, $item);
    }

    /**
     * @param $table
     * @param $first
     * @param $wheres
     * @param string $operator
     * @param string $second
     * @return $this
     */
    public function withSole($table, $first, $wheres = null, string $operator = '=', string $second = 'id'): static
    {
        $this->withSole[] = ['table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second, 'wheres' => $wheres];
        return $this;
    }

    /**
     * @param $collection
     * @return void
     */
    protected function withSoleRelation($collection, $is_sole = false): void
    {
        if ($this->withSole) {
            foreach ($this->withSole as $item) {
                $includes = $this->request->includes();
                if (!in_array($item['table'], $includes->all(), true)) {
                    continue;
                }

                $columns = $this->getColumns($item, false);

                if ($is_sole) {
                    $relation_ids = [$collection->{$item['first']}];
                } else {
                    $relation_ids = $collection->pluck($item['first'])->unique();
                }

                $relation_sole = DB::table($item['table'])
                    ->select($columns)
                    ->whereIn($item['second'], $relation_ids)
                    ->where(function ($query) use ($item) {
                        if ($item['wheres']) {
                            $item['wheres']($query);
                        }
                    })
                    ->get();

                if ($is_sole) {
                    $collection->{$item['table']} = $relation_sole->where($item['second'], $item['operator'], $collection->{$item['first']})->first();
                } else {
                    $collection->map(static function ($row) use ($item, $relation_sole) {
                        if (!isset($row->{$item['first']})) {
                            return null;
                        }
                        $row->{$item['table']} = $relation_sole->where($item['second'], $item['operator'], $row->{$item['first']})->first();
                        return $row;
                    });
                }
            }
        }
    }

    /**
     * @param mixed $item
     * @param bool $preAppend
     * @return mixed|array|string
     */
    protected function getColumns(mixed $item, bool $preAppend = true): mixed
    {
        $allowedFields = $this->allowedFields->filter(function ($field) use ($item) {
            if (Str::startsWith($field, $item['table'] . '.')) {
                return $field;
            }
            return null;
        })->toArray();
        $fields = $this->request->fields()->get($item['table']);

        if ($fields) {
            !$preAppend ?: $fields[] = $item['table'] . '.' . $item['first'];
            $columns = $fields;
        } elseif ($allowedFields) {
            !$preAppend ?: $allowedFields[] = $item['table'] . '.' . $item['first'];
            $columns = $allowedFields;
        } else {
            $columns = '*';
        }
        return $columns;
    }

    /**
     * @param $table
     * @param $relateTable
     * @param $first
     * @param $second
     * @param $wheres
     * @return $this
     */
    public function withMany($table, $relateTable, $first, $second, $wheres = null): static
    {
        $this->withMany[] = ['table' => $table, 'relateTable' => $relateTable, 'first' => $first, 'second' => $second, 'wheres' => $wheres];
        return $this;
    }

    /**
     * @param $collection
     * @param $ids
     * @return void
     */
    protected function withManyRelation($collection, $ids, $is_sole = false): void
    {
        if ($this->withMany) {
            foreach ($this->withMany as $item) {
                $includes = $this->request->includes();
                if (!in_array($item['table'], $includes->all(), true)) {
                    continue;
                }

                $sorts = $this->request->sorts();
                $relation_many = $this->getManyRelation_many($item, $ids, $sorts);

                if ($is_sole) {
                    $collection->{$item['table']} = $relation_many->where($item['first'], '=', $collection->id)->toArray();
                } else {
                    $collection->map(static function ($row) use ($item, $relation_many) {
                        $row->{$item['table']} = $relation_many->where($item['first'], '=', $row->id)->toArray();
                        return $row;
                    });
                }
            }
        }
    }

    /**
     * @param mixed $item
     * @param $ids
     * @param Collection $sorts
     * @return Collection
     */
    protected function getManyRelation_many(mixed $item, $ids, Collection $sorts): Collection
    {
        $relation_query_many = DB::table($item['table'])
            ->join($item['relateTable'], $item['table'] . '.id', '=', $item['second'])
            ->select($item['table'] . '.*', $item['relateTable'] . '.' . $item['first'], $item['relateTable'] . '.id as ' . $item['relateTable'] . '_id')
            ->whereIn($item['first'], $ids)
            ->where(function ($query) use ($item) {
                if ($item['wheres']) {
                    $item['wheres']($query);
                }
            });
        return $this->handleRelations($sorts, $relation_query_many, $item);
    }

    /**
     * @param $wheres
     * @return $this
     */
    public function where($wheres): static
    {
        $this->query->where($wheres);
        return $this;
    }

    /**
     * @param string $pageName
     * @return array
     */
    public function get(string $pageName = 'page'): array
    {
        $paging = $this->request->get('paging', false);
        if ($paging) {
            $limit = $this->request->get('limit', 15);
            $page = $this->request->get('page', 1);
            $collection = $this->query->paginate($limit, ['*'], $pageName, $page);
            $this->tableTotal = $collection->total();
            $this->queryLimit = $limit;
            $this->queryOffset = $page;
            $ids = $collection->pluck('id')->unique();
            $this->withManyRelation($collection, $ids);
            $this->withRelation($collection, $ids);
            $this->withSoleRelation($collection);
            return $this->tableInfo() + ['items' => $collection];
        }

        $theQuery = $this->query;
        $this->tableTotal = $theQuery->count();
        $this->paging();
        $collection = $theQuery->get();
        $ids = $collection->pluck('id')->unique();
        $this->withManyRelation($collection, $ids);
        $this->withRelation($collection, $ids);
        $this->withSoleRelation($collection);
        return $this->tableInfo() + ['items' => $collection];
    }

    public function sole(int $id): object
    {
        $theQuery = $this->query;
        $theQuery->where('id', $id);
        $collection = $theQuery->first();
        $ids = [$id];
        $this->withManyRelation($collection, $ids, true);
        $this->withRelation($collection, $ids, true);
        $this->withSoleRelation($collection, true);
        return $collection;
    }

    public function count(): int
    {
        $theQuery = $this->query;
        $this->tableTotal = $theQuery->count();
        $collection = $theQuery->get();
        $ids = $collection->pluck('id')->unique();
        $this->withManyRelation($collection, $ids);
        $this->withRelation($collection, $ids);
        $this->withSoleRelation($collection);
        return $this->tableTotal;
    }

    /**
     * @param Collection $sorts
     * @param Builder $relation_query_many
     * @param mixed $item
     * @return Collection
     */
    protected function handleRelations(Collection $sorts, Builder $relation_query_many, mixed $item): Collection
    {
        $sorts->map(function ($property) use ($relation_query_many, $item) {
            $descending = $property[0] === '-';
            $key = ltrim($property, '-');

            $sort = $this->findSort($key);

            $cut_sort = explode(separator: '.', string: $sort);
            if ($cut_sort[0] === $item['table'] && Str::contains($sort, '.')) {
                return $relation_query_many->orderBy($sort, $descending ? 'desc' : 'asc');
            }
            return null;
        });

        $wheres = $this->getWheres();

        $filterWheres = $wheres->filter(function ($where_item) use ($item) {
            if (Str::contains($where_item['field'], '.') && Str::startsWith($where_item['field'], $item['table'])) {
                return $where_item;
            }
            return null;
        });

        $this->getWhere($relation_query_many, $filterWheres);

        $this->getOrWhere($relation_query_many, $filterWheres);

        if ($this->request->limits()->get($item['table'])) {
            $limit_field = (int)$this->request->limits()->get($item['table']);
            $relation_query_many->skip(0)->take($limit_field);
        }

        return $relation_query_many->get();
    }
}
