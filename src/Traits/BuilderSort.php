<?php

namespace Ions\Traits;

use Illuminate\Support\Collection;
use Ions\Support\Str;
use Ions\Exceptions\InvalidSortQuery;

trait BuilderSort
{
    protected ?Collection $allowSorts;

    /**
     * @param $sorts
     * @return $this
     */
    public function allowedSorts($sorts): self
    {
        if ($this->request->sorts()->isEmpty()) {
            return $this;
        }

        $sorts = is_array($sorts) ? $sorts : func_get_args();

        $this->allowSorts = collect($sorts)->map(function ($sort) {
            return ltrim($sort, '-');
        });

        $this->allowSorts->add('rand');

        $this->ensureAllSortsExist();

        $this->addRequestedSortsToQuery(); // allowed is known & request is known, add what we can, if there is no request, -wait

        return $this;
    }

    /**
     * @return void
     */
    protected function addRequestedSortsToQuery(): void
    {
        $this->request->sorts()
            ->each(function (string $property) {
                $descending = $property[0] === '-';
                $key = ltrim($property, '-');

                $sort = $this->findSort($key);

                $table = $this->query->from;
                $cut_sort = explode(separator: '.', string: $sort);
                if(Str::contains($sort, '.') && $cut_sort[0] !== $table){
                    return;
                }

                if ($sort === 'rand') {
                    $this->query->inRandomOrder();
                    return;
                }

                $this->query->orderBy($sort, $descending ? 'desc' : 'asc');

            });
    }

    /**
     * @param string $property
     * @return mixed
     */
    protected function findSort(string $property): mixed
    {
        return $this->allowSorts
            ->first(function ($sort) use ($property) {
                return $sort === $property;
            });
    }

    /**
     * @return void
     */
    protected function ensureAllSortsExist(): void
    {
        $requestedSortNames = $this->request->sorts()->map(function (string $sort) {
            return ltrim($sort, '-');
        });

        $allowedSortNames = $this->allowSorts->map(function ($sort) {
            return $sort;
        });

        $unknownSorts = $requestedSortNames->diff($allowedSortNames);


        if ($unknownSorts->isNotEmpty()) {
            throw InvalidSortQuery::sortsNotAllowed($unknownSorts, $allowedSortNames);
        }
    }
}