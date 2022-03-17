<?php

namespace Ions\Auth\Guard;

use Carbon\Carbon;
use Exception;
use Ions\Support\DB;
use Throwable;

class GuardControl
{
    /**
     * @var string
     */
    protected static string $connection_name = 'default';

    /**
     * @var array|string[]
     */
    private static array $tables_names = [
        'controls' => 'controls',
        'controls_languages' => 'controls_languages',
        'actions' => 'actions',
        'actions_languages' => 'actions_languages',
    ];

    /**
     * @var array|string[]
     */
    protected static array $languages = ['ar', 'en'];

    /**
     * @param string $connection
     * @return void
     */
    public static function setConnection(string $connection): void
    {
        self::$connection_name = $connection;
    }

    /**
     * @param array $languages
     * @return void
     */
    public static function setLanguages(array $languages): void
    {
        self::$languages = $languages;
    }

    /**
     * @param array $tables_names
     * @return void
     */
    public static function setTables(array $tables_names): void
    {
        self::$tables_names = $tables_names;
    }

    /**
     * @param int $id
     * @return object|null
     */
    public static function single(int $id): object|null
    {
        $control = DB::connection(self::$connection_name)
            ->table(self::$tables_names['controls'])
            ->where('id', $id)->first();
        if ($control) {
            $control->languages = DB::connection(self::$connection_name)
                ->table(self::$tables_names['controls_languages'])
                ->whereIn('language', self::$languages)
                ->where('control_id', $id)->get();
            $control->actions = self::actions($id);
        }
        return $control;
    }

    /**
     * @param string $slug
     * @param string $language
     * @return object|null
     */
    public static function singleBySlug(string $slug, string $language = 'en'): object|null
    {
        $control = DB::connection(self::$connection_name)
            ->table(self::$tables_names['controls'])
            ->where('slug', $slug)->first();
        if ($control) {
            $control->actives = self::getParents($control->id, []);
            $control->language = DB::connection(self::$connection_name)
                ->table(self::$tables_names['controls_languages'])
                ->where('language', $language)
                ->where('control_id', $control->id)->first();
        }
        return $control;
    }

    /**
     * @param $control_id
     * @param array $activities
     * @return array
     */
    private static function getParents($control_id, array $activities): array
    {
        $control = DB::connection(self::$connection_name)
            ->table(self::$tables_names['controls'])
            ->where('id', $control_id)->first(['active_name', 'parent_id']);
        if ($control) {
            $activities[] = $control->active_name;
            if ($control->parent_id) {
                $activities = self::getParents($control->parent_id, $activities);
            }
        }
        return $activities;
    }

    /**
     * @param string $slug
     * @param int $control_id
     * @return object|null
     */
    public static function actionBySlug(string $slug, int $control_id): object|null
    {
        return DB::connection(self::$connection_name)
            ->table(self::$tables_names['actions'])
            ->where('slug', $slug)
            ->where('control_id', $control_id)
            ->first();
    }

    /**
     * @param int $control_id
     * @return object|null
     */
    public static function actions(int $control_id): object|null
    {
        $actions = DB::connection(self::$connection_name)
            ->table(self::$tables_names['actions'])
            ->where('control_id', $control_id)->get();
        $actions->map(function ($single) {
            $single->languages = DB::connection(self::$connection_name)
                ->table(self::$tables_names['actions_languages'])
                ->whereIn('language', self::$languages)
                ->where('action_id', $single->id)->get();
            return $single;
        });

        return $actions;
    }

    /**
     * @param string $language
     * @param null $not_id
     * @return object|null
     */
    public static function all(string $language = 'en', $not_id = null): object|null
    {
        $controls = DB::connection(self::$connection_name)
            ->table(self::$tables_names['controls']);
        if ($not_id) {
            $controls->where('id', '!=', $not_id);
        }
        $controls = $controls->get();
        $controls->map(function ($single) use ($language) {
            $single->language = DB::connection(self::$connection_name)
                ->table(self::$tables_names['controls_languages'])
                ->where('language', $language)
                ->where('control_id', $single->id)->first();
            return $single;
        });

        return $controls;
    }

    /**
     * @param string $language
     * @param null $level
     * @param int[] $status
     * @return object|null
     */
    public static function hierarchy(string $language = 'en', array $status = [0, 1], $level = null): object|null
    {
        $tree = DB::connection(self::$connection_name)
            ->table(self::$tables_names['controls'])
            ->select(self::$tables_names['controls'] . '.*', 'c2.count')
            ->leftJoin(DB::connection(self::$connection_name)
                ->raw('(SELECT parent_id, COUNT(*) AS count FROM `' . self::$tables_names['controls'] . '` GROUP BY parent_id) c2'),
                function ($join) {
                    $join->on(self::$tables_names['controls'] . '.id', 'c2.parent_id');
                })
            ->where(self::$tables_names['controls'] . '.parent_id', $level)
            ->whereIn('status', $status)
            ->orderBy('order_no')->get();

        $controls_ids = $tree->pluck('id')->unique();
        $controls_language = DB::connection(self::$connection_name)
            ->table(self::$tables_names['controls_languages'])
            ->where('language', $language)
            ->whereIn('control_id', $controls_ids)->get();

        $tree->map(function ($row) use ($status, $language,$controls_language) {
            $row->language = $controls_language->where('control_id',$row->id)->first();
            if ($row->count > 0) {
                $row->children = self::hierarchy($language, $status, $row->id);
            }
            return $row;
        });
        return $tree;
    }

    /**
     * @param $params
     * @return mixed
     * @throws Throwable
     */
    public static function add($params): mixed
    {
        return DB::connection(self::$connection_name)->transaction(function () use ($params) {
            $control = [
                'slug' => $params->slug,
                'parent_id' => $params->parent_id ?: null,
                'status' => $params->status,
                'icon' => $params->icon,
                'link' => $params->link,
                'active_name' => $params->active_name,
                'is_tag' => $params->is_tag ?? 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ];
            $control_id = DB::connection(self::$connection_name)
                ->table(self::$tables_names['controls'])->insertGetId($control);

            self::controlLanguages($control_id, $params->languages);

            foreach ($params->actions as $action) {
                $item = [
                    'slug' => $action->slug,
                    'status' => $action->status,
                    'control_id' => $control_id,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ];

                $action_id = DB::connection(self::$connection_name)
                    ->table(self::$tables_names['actions'])->insertGetId($item);

                self::actionLanguages($action_id, $action->languages);
            }
        });
    }

    /**
     * @param $control_id
     * @param $languages
     * @return void
     */
    public static function controlLanguages($control_id, $languages): void
    {
        foreach ($languages as $language) {
            $language_id = $language->language_id ?? null;
            $control_language = [
                'language' => $language->language_name,
                'control_id' => $control_id,
                'name' => $language->name,
                'updated_at' => Carbon::now()
            ];

            if ($language_id) {
                DB::connection(self::$connection_name)
                    ->table(self::$tables_names['controls_languages'])
                    ->where('id', $language_id)->update($control_language);
            } else {
                $control_language['created_at'] = Carbon::now();
                DB::connection(self::$connection_name)
                    ->table(self::$tables_names['controls_languages'])->insert($control_language);
            }
        }
    }

    /**
     * @param $action_id
     * @param $languages
     * @return void
     */
    public static function actionLanguages($action_id, $languages): void
    {
        foreach ($languages as $language) {
            $action_language_id = $language->action_language_id ?? null;
            $action_language = [
                'language' => $language->language_name,
                'action_id' => $action_id,
                'name' => $language->action_name,
                'updated_at' => Carbon::now()
            ];

            if ($action_language_id) {
                DB::connection(self::$connection_name)
                    ->table(self::$tables_names['actions_languages'])
                    ->where('id', $action_language_id)->update($action_language);
            } else {
                $action_language['created_at'] = Carbon::now();
                DB::connection(self::$connection_name)
                    ->table(self::$tables_names['actions_languages'])->insert($action_language);
            }
        }
    }

    /**
     * @param $params
     * @return mixed
     * @throws Throwable
     */
    public static function update($params): mixed
    {
        return DB::connection(self::$connection_name)->transaction(function () use ($params) {
            $control_id = $params->id;

            $control = [
                'slug' => $params->slug,
                'parent_id' => $params->parent_id ?: null,
                'status' => $params->status,
                'icon' => $params->icon,
                'link' => $params->link,
                'is_tag' => $params->is_tag ?? 0,
                'active_name' => $params->active_name,
                'updated_at' => Carbon::now()
            ];
            DB::connection(self::$connection_name)
                ->table(self::$tables_names['controls'])->where('id', $control_id)->update($control);

            self::controlLanguages($control_id, $params->languages);

            foreach ($params->actions as $action) {
                $action_id = $action->action_id ?? null;
                $item = [
                    'slug' => $action->slug,
                    'status' => $action->status,
                    'control_id' => $control_id,
                    'updated_at' => Carbon::now()
                ];
                if ($action_id) {
                    DB::connection(self::$connection_name)
                        ->table(self::$tables_names['actions'])->where('id', $action_id)->update($item);
                } else {
                    $item['created_at'] = Carbon::now();
                    $action_id = DB::connection(self::$connection_name)->table(self::$tables_names['actions'])
                        ->insertGetId($item);
                }
                self::actionLanguages($action_id, $action->languages);
            }
        });
    }

    /**
     * @param $control_id
     * @return int
     */
    public static function delete($control_id): int
    {
        return DB::connection(self::$connection_name)
            ->table(self::$tables_names['controls'])->where('id', $control_id)->delete();
    }

    /**
     * @param $orderJson
     * @throws Exception
     */
    public static function order($orderJson): void
    {
        $order_arr = json_decode($orderJson, false, 512, JSON_THROW_ON_ERROR);
        $order = 1;
        foreach ($order_arr as $elem) {
            DB::connection(self::$connection_name)
                ->table(self::$tables_names['controls'])
                ->where('id', $elem->id)->update(['order_no' => $order, 'parent_id' => null]);
            $order++;
            self::orderChild($elem);
        }
    }

    /**
     * @param $array
     * @return void
     */
    private static function orderChild($array): void
    {
        $order = 1;
        if (isset($array->children) && count($array->children) > 0) {
            foreach ($array->children as $child) {
                DB::connection(self::$connection_name)
                    ->table(self::$tables_names['controls'])
                    ->where('id', $child->id)->update(['order_no' => $order, 'parent_id' => $array->id]);
                $order++;
                if (isset($child->children)) {
                    self::orderChild($child);
                }
            }
        }
    }

    /**
     * @param $action_id
     * @return int
     */
    public static function deleteAction($action_id): int
    {
        return DB::connection(self::$connection_name)
            ->table(self::$tables_names['actions'])->where('id', $action_id)->delete();

    }

}
