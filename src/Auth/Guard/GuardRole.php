<?php

namespace Ions\Auth\Guard;

use Carbon\Carbon;
use Ions\Bundles\Path;
use Ions\Foundation\Singleton;
use Ions\Support\Arr;
use Ions\Support\DB;
use Throwable;
use Cartalyst\Sentinel\Native\ConfigRepository;
use Cartalyst\Sentinel\Native\SentinelBootstrapper;
use Cartalyst\Sentinel\Native\Facades\Sentinel;

class GuardRole extends Singleton
{
    /**
     * @var string
     */
    protected static string $connection_name = 'default';

    /**
     * @var array|string[]
     */
    private static array $tables_names = [
        'roles' => 'roles',
        'roles_languages' => 'roles_languages',
        'roles_controls' => 'roles_controls',
        'roles_actions' => 'roles_actions',
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
     * static call
     * @return void
     */
    public static function constructStatic(): void
    {
        $config = new ConfigRepository(Path::auth('Sentinel/config.php'));
        $bootstrapper = new SentinelBootstrapper($config);
        Sentinel::instance($bootstrapper);
    }

    /**
     * @param $params
     * @return mixed
     * @throws Throwable
     */
    public static function add($params): mixed
    {
        return DB::connection(self::$connection_name)->transaction(function () use ($params) {
            $role = Sentinel::getRoleRepository()->createModel()->create([
                'name' => $params->slug,
                'slug' => $params->slug,
            ]);

            self::roleLanguages($role->id, $params->languages);

            return $role->id;
        });
    }

    /**
     * @param $role_id
     * @param $languages
     * @return void
     */
    public static function roleLanguages($role_id, $languages): void
    {
        foreach ($languages as $language) {
            $language_id = $language->language_id ?? null;
            $role_language = [
                'language' => $language->language_name,
                'role_id' => $role_id,
                'name' => $language->name,
                'updated_at' => Carbon::now()
            ];

            if ($language_id) {
                DB::connection(self::$connection_name)
                    ->table(self::$tables_names['roles_languages'])
                    ->where('id', $language_id)->update($role_language);
            } else {
                $role_language['created_at'] = Carbon::now();
                DB::connection(self::$connection_name)
                    ->table(self::$tables_names['roles_languages'])->insert($role_language);
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
            $role_id = $params->id;
            $role = [
                'name' => $params->slug,
                'slug' => $params->slug,
                'updated_at' => Carbon::now(),
            ];

            DB::connection(self::$connection_name)->table(self::$tables_names['roles'])->where('id', $role_id)->update($role);

            self::roleLanguages($role_id, $params->languages);
        });
    }

    /**
     * @param int $id
     * @param string|null $language
     * @return object|null
     */
    public static function single(int $id, string $language = null): object|null
    {
        $role = DB::connection(self::$connection_name)
            ->table(self::$tables_names['roles'])
            ->where('id', $id)->first();
        if ($role) {
            if ($language) {
                $role->language = DB::connection(self::$connection_name)
                    ->table(self::$tables_names['roles_languages'])
                    ->where('language', $language)
                    ->where('role_id', $id)->first();
            } else {
                $role->languages = DB::connection(self::$connection_name)
                    ->table(self::$tables_names['roles_languages'])
                    ->whereIn('language', self::$languages)
                    ->where('role_id', $id)->get();
            }
        }
        return $role;
    }

    /**
     * @param string $language
     * @return object|null
     */
    public static function all(string $language = 'en'): object|null
    {
        $collection = DB::connection(self::$connection_name)
            ->table(self::$tables_names['roles'])->get();
        $collection->map(function ($single) use ($language) {
            $single->language = DB::connection(self::$connection_name)
                ->table(self::$tables_names['roles_languages'])
                ->where('language', $language)
                ->where('role_id', $single->id)->first();
            return $single;
        });

        return $collection;
    }

    /**
     * @param int $role_id
     * @return int
     */
    public static function delete(int $role_id): int
    {
        return DB::connection(self::$connection_name)
            ->table(self::$tables_names['roles'])->where('id', $role_id)->delete();
    }

    /**
     * @param array $ids
     * @return int
     */
    public static function deleteMulti(array $ids): int
    {
        return DB::connection(self::$connection_name)
            ->table(self::$tables_names['roles'])->whereIn('id', $ids)->delete();

    }

    /**
     * @param int $role_id
     * @param string $language
     * @param null $level
     * @return object|null
     */
    public static function hierarchy(int $role_id, string $language = 'en', $level = null): object|null
    {
        $tree = GuardControl::hierarchy($language, [1], $level);

        $controls_ids = $tree?->pluck('id')->unique();
        $controls_actions = DB::connection(self::$connection_name)
            ->table(self::$tables_names['actions'])
            ->whereIn('control_id', $controls_ids)->get();

        $controls_roles = DB::connection(self::$connection_name)
            ->table(self::$tables_names['roles_controls'])
            ->where('role_id', $role_id)->get();

        $tree?->map(function ($row) use ($role_id, $language, $controls_actions, $controls_roles) {
            $row->checked = $controls_roles->contains('control_id', $row->id);

            $actions = collect($controls_actions->where('control_id', $row->id)->toArray());

            $action_ids = $actions->pluck('id')->unique();
            $actions_language = DB::connection(self::$connection_name)
                ->table(self::$tables_names['actions_languages'])
                ->where('language', $language)
                ->whereIn('action_id', $action_ids)->get();
            $actions_roles = DB::connection(self::$connection_name)
                ->table(self::$tables_names['roles_actions'])
                ->where('role_id', $role_id)
                ->get();

            $actions->map(function ($action) use ($actions_language, $actions_roles) {
                $action->language = $actions_language->where('action_id', $action->id)->first();
                $action->checked = $actions_roles->contains('action_id', $action->id);
                return $action;
            });
            $row->actions = $actions;

            if ($row->count > 0) {
                $row->children = self::hierarchy($role_id, $language, $row->id);
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
    public static function permissions($params): mixed
    {
        return DB::connection(self::$connection_name)->transaction(function () use ($params) {
            $ids_controls = $params->ids_controls;
            $ids_actions = $params->ids_actions;
            $ids_controls_arr = explode(',', $ids_controls);
            $ids_actions_arr = explode(',', $ids_actions);

            // update as json array
            $controls_actions = [];
            foreach ($ids_controls_arr as $control_id) {
                $actions = DB::connection(self::$connection_name)->table(self::$tables_names['actions'])->where('control_id', $control_id)->get();
                $actions_ids = Arr::pluck($actions, 'id');
                $intersect_actions = array_intersect($ids_actions_arr, $actions_ids);
                $controls_actions[$control_id] = true;
                foreach ($intersect_actions as $intersect_action) {
                    $controls_actions[$control_id . '.' . $intersect_action] = true;
                }
            }

            $role = Sentinel::findRoleById($params->id);
            $role->permissions = $controls_actions;
            $role->save();

            // update as tables
            self::rolePermissionTbl($params, $ids_controls_arr, $ids_actions_arr);
        });
    }

    /**
     * @param $params
     * @param $ids_controls_arr
     * @param $ids_actions_arr
     * @return void
     */
    public static function rolePermissionTbl($params, $ids_controls_arr, $ids_actions_arr): void
    {

        $role_controls = DB::connection(self::$connection_name)->table(self::$tables_names['roles_controls'])->where('role_id', $params->id)->select('control_id')->get()->toArray();
        $control_ids = Arr::pluck($role_controls, 'control_id');
        $difference_controls = array_diff($control_ids, $ids_controls_arr);

        foreach ($ids_controls_arr as $control_id) {
            if (!$control_id) {
                continue;
            }
            $role_control = [
                'role_id' => $params->id,
                'control_id' => $control_id,
                'is_allow' => 1
            ];
            DB::connection(self::$connection_name)->table(self::$tables_names['roles_controls'])->updateOrInsert($role_control);
        }
        [, $values] = Arr::divide($difference_controls);
        DB::connection(self::$connection_name)->table(self::$tables_names['roles_controls'])->whereIn('control_id', $values)->where('role_id', $params->id)->delete();

        $role_actions = DB::connection(self::$connection_name)->table(self::$tables_names['roles_actions'])->where('role_id', $params->id)->select('action_id')->get()->toArray();
        $action_ids = Arr::pluck($role_actions, 'action_id');
        $difference_actions = array_diff($action_ids, $ids_actions_arr);
        foreach ($ids_actions_arr as $action_id) {
            if (!$action_id) {
                continue;
            }
            $role_action = [
                'role_id' => $params->id,
                'action_id' => $action_id,
                'is_allow' => 1
            ];
            DB::connection(self::$connection_name)->table(self::$tables_names['roles_actions'])->updateOrInsert($role_action);
        }
        [, $action_values] = Arr::divide($difference_actions);
        DB::connection(self::$connection_name)->table(self::$tables_names['roles_actions'])->whereIn('action_id', $action_values)->where('role_id', $params->id)->delete();
    }

}

/**
 * trigger construct for static class
 */
GuardRole::constructStatic();
