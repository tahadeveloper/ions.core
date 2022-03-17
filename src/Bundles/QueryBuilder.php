<?php

namespace Ions\Bundles;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Ions\Foundation\Kernel;
use Ions\Support\Request;
use JetBrains\PhpStorm\Pure;

class QueryBuilder
{
    protected Builder $query;
    protected Request $request;
    protected array $fields;
    public string $count = '18446744073709551610'; // default value in mysql
    public int $offset = 0;
    public string $defaultField = 'id';

    protected array $filterOperators = [
        'eq' => '=',
        'ne' => '!=',
        'gt' => '>',
        'lt' => '<',
        'gte' => '>=',
        'lte' => '<=',
        'like' => 'like',
        'in' => 'in'
    ];

    #[Pure] public function __construct(Builder $query, $fields)
    {
        $this->request = Kernel::request();
        $this->query = $query;
        $this->fields = $fields;
    }

    /**
     * Get the response from this builder.
     */
    public function toResponse(): array
    {
        $filteredQuery = $this->filterQuery($this->query);

        $total = $filteredQuery->count();
        $data = $this->fetchData($filteredQuery);

        // clear filtering in request
        Kernel::request()->query->remove('limit');

        return [$data, $total];
    }

    /**
     * Fetch the data to return to the response.
     */
    protected function fetchData(Builder $query): Collection
    {
        $query = $this->countAndOffsetQuery($query);
        $query = $this->sortQuery($query);
        return $query->get($this->fields);
    }

    /**
     * Apply sorting operations to the query from given parameters
     * otherwise falling back to the first given field, ascending.
     */
    protected function sortQuery(Builder $query): Builder
    {
        $query = clone $query;
        $defaultSortName = $this->defaultField;
        $direction = 'asc';
        $sort = $this->request->get('sort', '');
        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
        }
        $sortName = ltrim($sort, '+- ');
        if ($sortName !== 'random' & !in_array($sortName, $this->fields, true)) {
            $sortName = $defaultSortName;
        }
        if($sortName === 'random'){
            return $query->inRandomOrder();
        }
        return $query->orderBy($sortName, $direction);

    }

    /**
     * Apply count and offset for paging, based on params from the request while falling
     * back to system defined default, taking the max limit into account.
     */
    protected function countAndOffsetQuery(Builder $query): Builder
    {
        $query = clone $query;
        $offset_result = max(0, $this->request->get('offset', 0));
        $maxCount = $this->count;
        $count_result = $this->request->get('limit', $maxCount);
        $count_result = max(min($maxCount, $count_result), 1);
        $this->count = $count_result;
        $this->offset = $offset_result;

        return $query->skip($offset_result)->take($count_result);
    }

    /**
     * Apply any filtering operations found in the request.
     */
    protected function filterQuery(Builder $query): Builder
    {
        $query = clone $query;
        $type = $this->request->get('filter_type', 'and');
        $requestFilters = $this->request->get('filter', []);
        if (!is_array($requestFilters)) {
            return $this->query;
        }

        $queryFilters = collect($requestFilters)->map(function ($value, $key) {
            return $this->requestFilterToQueryFilter($key, $value);
        })->filter(function ($value) {
            return !is_null($value);
        })->values()->toArray();

        if ($type === 'or') {
            foreach ($queryFilters as $field) {
                $query->orWhere($field[0], $field[1], $field[2]);
            }
            return $query;
        }
        return $query->where($queryFilters);
    }

    /**
     * Convert a request filter query key/value pair into a [field, op, value] where condition.
     */
    protected function requestFilterToQueryFilter($fieldKey, $value): ?array
    {
        $splitKey = explode(':', $fieldKey);
        $field = $splitKey[0];
        $filterOperator = $splitKey[1] ?? 'eq';

        if (!in_array($field, $this->fields, true)) {
            return null;
        }

        if (!array_key_exists($filterOperator, $this->filterOperators)) {
            $filterOperator = 'eq';
        }

        $queryOperator = $this->filterOperators[$filterOperator];
        return [$field, $queryOperator, $value];
    }


}
