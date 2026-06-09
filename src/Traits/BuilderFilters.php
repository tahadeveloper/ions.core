<?php

namespace Ions\Traits;

use Illuminate\Support\Collection;
use Ions\Builders\QueryBuilder;
use Ions\Exceptions\InvalidFilterQuery;
use Ions\Support\Str;

trait BuilderFilters
{
    protected ?Collection $allowedFilters;
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

    /**
     * Allow specific request filters to be applied to the query.
     *
     * By default ($allow_all = false) the allow-list is ENFORCED: any requested
     * filter column not present in $filters throws InvalidFilterQuery.
     *
     * Pass $allow_all = true (or call allowAllFilters()) only when you deliberately
     * want pass-through behaviour — every filter in the request will be applied
     * without validation.
     *
     * $filters MUST be an array. The old single-string/variadic forms
     * (allowFilters('col_a', 'col_b')) have been removed because they could
     * silently bypass allow-list enforcement via PHP's boolean coercion.
     * Passing a non-array will now throw a TypeError — fails closed/loud.
     *
     * To opt out of allow-listing explicitly: allowFilters([], true) or allowAllFilters().
     *
     * @param array $filters   Column names to allow, e.g. ['name', 'email'].
     * @param bool  $allow_all Set to true to disable allow-list enforcement.
     * @return BuilderFilters|QueryBuilder
     */
    public function allowFilters(array $filters = [], bool $allow_all = false): self
    {
        $this->allowedFilters = collect($filters);

        $this->ensureAllFiltersExist($allow_all);

        $this->addFiltersToQuery();

        return $this;
    }

    /**
     * Convenience method: apply every request filter without allow-list validation.
     *
     * Equivalent to allowFilters([], true).  Use this only when you genuinely
     * want pass-through behaviour.
     */
    public function allowAllFilters(): self
    {
        return $this->allowFilters([], true);
    }

    /**
     * @param $allow
     * @return void
     */
    protected function ensureAllFiltersExist($allow): void
    {
        if ($allow) {
            return;
        }

        $filterNames = $this->request->filters()->keys();
        $filterNames = $filterNames->map(function ($key) {
            $no_operations = explode(':', $key);
            $no_expiration = explode('|', $no_operations[0]);
            return $no_expiration[0];
        });

        $allowedFilterNames = $this->allowedFilters->map(function ($allowedFilter) {
            return $allowedFilter;
        });

        $diff = $filterNames->diff($allowedFilterNames);

        if ($diff->count()) {
            throw InvalidFilterQuery::filtersNotAllowed($diff, $allowedFilterNames);
        }
    }

    /**
     * @return Collection
     */
    protected function getWheres(): Collection
    {
        return $this->request->filters()->keys()->map(function ($filter) {
            $splitExp = explode('|', $filter);
            $splitKey = explode(':', $splitExp[0]);

            $field = $splitKey[0];
            $operation = $splitKey[1] ?? 'eq';
            $exp = $splitExp[1] ?? 'and';

            if (!array_key_exists($operation, $this->filterOperators)) {
                $operation = 'eq';
            }

            $queryOperator = $this->filterOperators[$operation];
            $value = $this->request->filters()->get($filter);

            return ['field' => $field, 'operator' => $queryOperator, 'value' => $value, 'exp' => $exp];
        });
    }

    /**
     * @param $allowedFilter
     * @return bool
     */
    protected function isFilterRequested($allowedFilter): bool
    {
        return $this->request->filters()->has($allowedFilter);
    }

    /**
     * @return void
     */
    protected function addFiltersToQuery(): void
    {
        $wheres = $this->getWheres();

        $filterWheres = $wheres->filter(function ($item){
            if (Str::contains($item['field'], '.') && !Str::startsWith($item['field'], $this->query->from)) {
                return null;
            }
            return $item;
        });

        $this->getWhere($this->query,$filterWheres);

        $this->getOrWhere($this->query,$filterWheres);
    }

    /**
     * @param $theQuery
     * @param Collection $filterWheres
     * @return void
     */
    protected function getWhere($theQuery,Collection $filterWheres): void
    {
        $theQuery->where(function ($query) use ($filterWheres) {
            $filterWheres->map(function ($single) use ($query) {
                if ($single['exp'] === 'and') {
                    if ($single['operator'] === 'in') {
                        return $query->whereIn($single['field'], $single['value']);
                    }
                    return $query->where($single['field'], $single['operator'], $single['value']);
                }
                return null;
            });
            return $filterWheres;
        });
    }

    /**
     * @param $theQuery
     * @param Collection $filterWheres
     * @return void
     */
    protected function getOrWhere($theQuery,Collection $filterWheres): void
    {
        $theQuery->orWhere(function ($query) use ($filterWheres) {
            $filterWheres->map(function ($single) use ($query) {
                if ($single['exp'] === 'or') {
                    if ($single['operator'] === 'in') {
                        return $query->orWhereIn($single['field'], $single['value']);
                    }
                    return $query->orWhere($single['field'], $single['operator'], $single['value']);
                }
                return null;
            });
            return $filterWheres;
        });
    }
}