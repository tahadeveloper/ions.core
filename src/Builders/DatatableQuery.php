<?php

namespace Ions\Builders;

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use JetBrains\PhpStorm\ArrayShape;

class DatatableQuery
{
    protected array $query = ['paging' => ['limit' => 100, 'offset' => 0], 'sort' => ['dir' => '', 'direction' => 'asc', 'column' => 'id'], 'filter' => []];
    protected Request $request;
    protected array $fields;
    public string $table = '';
    public string $count = '1000'; // default value in mysql
    public int $offset = 0;
    public string $defaultField = 'id';

    public function __construct($fields = [])
    {
        $this->request = Kernel::request();
        $this->fields = $this->request->get('columns', $fields);
    }

    public function toArray(): array
    {
        $this->paging();
        $this->sortQuery();
        $this->filterQuery();
        return $this->query;
    }


    #[ArrayShape(['limit' => "mixed", 'offset' => "mixed", 'sort' => "string", 'filter_type' => "mixed|string", 'filter' => "mixed|string"])]
    public function toQuery(): array
    {
        $this->paging();
        $this->sortQuery();
        $this->filterQuery();

        return [
            'limit' => $this->query['paging']['limit'] ,
            'offset' => $this->query['paging']['offset'] ,
            'sort' => $this->query['sort']['dir'] . $this->query['sort']['column'] ?? '',
            'filter' => $this->query['filter'] ?? ''
        ];
    }

    /**
     * Apply count and offset for paging, based on params from the request while falling
     * back to system defined default, taking the max limit into account.
     */
    protected function paging(): void
    {
        $offset_result = max(0, $this->request->post('start', 0));
        $maxCount = $this->count;
        $count_result = $this->request->post('length', $maxCount);
        $count_result = max(min($maxCount, $count_result), 1);

        $this->query['paging'] = ['limit' => $count_result, 'offset' => $offset_result];
    }

    /**
     * Apply sorting operations to the query from given parameters
     * otherwise falling back to the first given field, ascending.
     */
    protected function sortQuery(): void
    {
        $defaultSortName = $this->defaultField;
        $direction = 'asc';
        $sorts = $this->request->post('order', []);

        foreach ($sorts as $sort) {
            $dir = $sort['dir'] ?? $direction;
            $this->query['sort'] = ['column' => $this->fields[$sort['column']]['data'] ?? $defaultSortName,
                'direction' => $dir, 'dir' => $dir === 'desc' ? '-' : ''];
        }
    }

    /**
     * Apply any filtering operations found in the request.
     */
    protected function filterQuery(): void
    {
        $requestFilters = $this->request->post('search');

        if (!empty($requestFilters['value'])) {
            $this->query['filter'] = [];
            foreach ($this->fields as $field) {
                if ($field['searchable'] === 'true') {
                    $this->query['filter'][$field['data'] . ':like|or'] = "%" . $requestFilters['value'] . "%";
                }
            }
        }
    }
}