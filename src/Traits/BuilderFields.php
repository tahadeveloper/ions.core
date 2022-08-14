<?php

namespace Ions\Traits;

use Illuminate\Support\Collection;
use Ions\Exceptions\InvalidFieldQuery;
use Ions\Support\Arr;
use Ions\Support\Str;

trait BuilderFields
{
    protected ?Collection $allowedFields = null;

    /**
     * @param $fields
     * @param bool $all
     * @return $this
     */
    public function allowedFields($fields, bool $all = false): static
    {
        $fields = is_array($fields) ? $fields : func_get_args();

        $this->allowedFields = collect($fields)
            ->map(function (string $fieldName) {
                return $this->prependField($fieldName);
            });

        $this->ensureAllFieldsExist();

        $this->addRequestedModelFieldsToQuery($all);

        return $this;
    }

    /**
     * @return void
     */
    protected function ensureAllFieldsExist(): void
    {
        $requestedFields = $this->request->fields()
            ->map(function ($fields, $model) {
                $tableName = $model;
                return $this->prependFieldsWithTableName($fields, $tableName);
            })
            ->flatten()
            ->unique();

        $unknownFields = $requestedFields->diff($this->allowedFields);

        if ($unknownFields->isNotEmpty()) {
            throw InvalidFieldQuery::fieldsNotAllowed($unknownFields, $this->allowedFields);
        }
    }

    /**
     * @return void
     */
    protected function addRequestedModelFieldsToQuery($all): void
    {
        $modelTableName = $this->query->from;
        $modelFields = $this->request->fields()->get($modelTableName);
        if (empty($modelFields)) {
            $modelFields = $this->allowedFields->toArray();
        }
        $prependedFields = $this->prependFieldsWithTableName($modelFields, $modelTableName);
        $prependedFieldsTable = collect($prependedFields)->filter(function ($item) use ($modelTableName) {
            if (Str::contains($item, $modelTableName)) {
                return $item;
            }
            return null;
        })->toArray();
        $this->query->select($all ? $prependedFields : $prependedFieldsTable);
    }

    /**
     * @param array $fields
     * @param string $tableName
     * @return array
     */
    protected function prependFieldsWithTableName(array $fields, string $tableName): array
    {
        return array_map(function ($field) use ($tableName) {
            return $this->prependField($field, $tableName);
        }, $fields);
    }

    /**
     * @param string $field
     * @param string|null $table
     * @return string
     */
    protected function prependField(string $field, ?string $table = null): string
    {
        if (!$table) {
            $table = $this->query->from;
        }

        if (Str::contains($field, '.')) {
            return $field;
        }

        return "{$table}.{$field}";
    }
}