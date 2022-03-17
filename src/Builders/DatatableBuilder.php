<?php

namespace Ions\Builders;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Ions\Foundation\Kernel;
use Ions\Support\Request;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;

#=> listing with datatable v1.0.0
class DatatableBuilder
{
    protected Builder $query;
    protected Request $request;
    protected array $fields;
    public string $table = '';
    public string $count = '100'; // default value in mysql
    public int $offset = 0;
    public string $defaultField = 'id';


    #[Pure] public function __construct(Builder $query, $fields)
    {
        $this->request = Kernel::request();
        $this->query = $query;
        $this->fields = $fields;
    }

    /**
     * Get the response from this builder.
     */
    #[ArrayShape(['recordsTotal' => "int", 'recordsFiltered' => "int", 'data' => Collection::class])]
    public function toResponse(): array
    {
        $filteredQuery = $this->filterQuery($this->query);

        $total = $filteredQuery->count();
        $data = $this->fetchData($filteredQuery);

        return [
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $data,
        ];
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
     * Apply any filtering operations found in the request.
     */
    protected function filterQuery(Builder $query): Builder
    {
        $query = clone $query;
        $requestFilters = $this->request->post('search');

        if(!empty($requestFilters['value'])) {
            $query->where(function ($s_query) use ($requestFilters) {
                foreach ($this->fields as $field) {
                    $s_query->orWhere($field, 'LIKE', "%" . $requestFilters['value'] . "%");
                }
            });
        }

        return $query;
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
        $sorts = $this->request->post('order', []);

        foreach ($sorts as $sort) {
            $query = $query->orderBy($this->fields[$sort['column']] ?? $defaultSortName, $sort['dir'] ?? $direction);
        }
        return $query;
    }

    /**
     * Apply count and offset for paging, based on params from the request while falling
     * back to system defined default, taking the max limit into account.
     */
    protected function countAndOffsetQuery(Builder $query): Builder
    {
        $query = clone $query;
        $offset_result = max(0, $this->request->post('start', 0));
        $maxCount = $this->count;
        $count_result = $this->request->post('length', $maxCount);
        $count_result = max(min($maxCount, $count_result), 1);
        $this->count = $count_result;
        $this->offset = $offset_result;

        return $query->skip($offset_result)->take($count_result);
    }


}
