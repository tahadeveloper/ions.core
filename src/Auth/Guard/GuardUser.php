<?php

namespace Ions\Auth\Guard;

use Cartalyst\Sentinel\Native\ConfigRepository;
use Cartalyst\Sentinel\Native\Facades\Sentinel;
use Cartalyst\Sentinel\Native\SentinelBootstrapper;
use Ions\Bundles\Path;
use Ions\Foundation\Singleton;
use Ions\Support\DB;
use Throwable;

class GuardUser extends Singleton
{
    /**
     * @var string
     */
    protected static string $connection_name = 'default';

    /**
     * @var array|string[]
     */
    private static array $tables_names = [
        'users' => 'users',
        'role_users' => 'role_users',
        'roles' => 'roles',
        'roles_languages' => 'roles_languages',
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
     * @param bool $is_admin
     * @return mixed
     * @throws Throwable
     */
    public static function add($params, bool $is_admin = false): mixed
    {
        return DB::connection(self::$connection_name)->transaction(function () use ($is_admin, $params) {
            $user_data = [
                'email' => $params->email,
                'first_name' => $params->first_name,
                'last_name' => $params->last_name,
                'status' => $params->status,
                'mobile' => $params->mobile,
                'mobile_2' => $params->mobile_2 ?? null,
                'password' => $params->password,
                'address' => $params->address ?? null,
                'notes' => $params->notes ?? null,
                'image' => $params->image ?? null,
                'image_name' => $params->image_name ?? null,
            ];

            $role_id = $params->role_id;
            if ((int)$params->status === 1) {
                $register = Sentinel::registerAndActivate($user_data);
            } else {
                $register = Sentinel::register($user_data);
            }
            $role = Sentinel::findRoleById($role_id);
            $role->users()->attach($register);

            if ($is_admin) {
                $register->permissions = ['full_control' => true];
                $register->save();
            }

        });

    }

    /**
     * @param int $id
     * @param string $language
     * @return object|null
     */
    public static function single(int $id, string $language = 'en'): object|null
    {
        $obj = DB::connection(self::$connection_name)
            ->table(self::$tables_names['users'])
            ->where('id', $id)->first();
        if ($obj) {
            $roles = DB::connection(self::$connection_name)
                ->table(self::$tables_names['role_users'])
                ->where('user_id', $id)->get();
            $roleClass = new GuardRole();
            $roles->map(function ($role) use ($language, $roleClass) {
                $role->data = $roleClass->single($role->role_id, $language);
                return $role;
            });
            $obj->roles = $roles;
        }
        return $obj;
    }

    /**
     * @param string $language
     * @param int $limit
     * @return object|null
     */
    public static function all(string $language = 'en'): object|null
    {
        $collection = DB::connection(self::$connection_name)
            ->table(self::$tables_names['users'])
            ->where('id', '!=', 1)
            ->get();
        $collection->map(function ($single) use ($language) {
            $roles = DB::connection(self::$connection_name)
                ->table(self::$tables_names['role_users'])
                ->where('user_id', $single->id)->get();
            $roleClass = new GuardRole();
            $roles->map(function ($role) use ($language, $roleClass) {
                $role->data = $roleClass->single($role->role_id, $language);
                return $role;
            });
            $single->roles = $roles;
            return $single;
        });

        return $collection;
    }

    /**
     * @param int $id
     * @return mixed
     */
    public static function delete(int $id): mixed
    {
        return (Sentinel::findById($id))->delete();
    }

    /**
     * @param string $ids
     * @return array
     */
    public static function deleteMulti(string $ids): array
    {
        $result = [];
        foreach (explode(',', $ids) as $id) {
            $del = Sentinel::findById($id)->delete();
            if (!$del) {
                $result[] = ['ids' => $id];
            }
        }
        return $result;
    }

    /**
     * @param $params
     * @return mixed
     * @throws Throwable
     */
    public static function update($params): mixed
    {
        return DB::connection(self::$connection_name)->transaction(function () use ($params) {
            $user = Sentinel::findById($params->id);

            $user_data = [
                'email' => $params->email,
                'first_name' => $params->first_name,
                'last_name' => $params->last_name,
                'mobile' => $params->mobile,
                'mobile_2' => $params->mobile_2,
                'address' => $params->address,
                'notes' => $params->notes,
                'image' => $params->image,
                'image_name' => $params->image_name,
            ];

            $new_password = $params->password ?? null;
            if ($new_password) {
                $user_data['password'] = $new_password;
            }

            if (isset($params->status)) {
                $status = $params->status;
                $Activation = Sentinel::getActivationRepository();
                $user_data['status'] = $status;

                if ((int)$status === 1) {
                    if (!$Activation->completed($user)) {
                        $create_activation = $Activation->create($user);
                        $Activation->complete($user, $create_activation->code);
                    }
                } else {
                    $Activation->remove($user);
                }
            }

            Sentinel::update($user, $user_data);

            if (isset($params->role_id)) {
                $role_id = $params->role_id;
                $user_role = DB::connection(self::$connection_name)->table(self::$tables_names['role_users'])->where('user_id', $params->id)->latest()->first();
                if ($user_role && (int)$user_role->role_id !== (int)$role_id) {
                    $old_role = Sentinel::findRoleById($user_role->role_id);
                    $old_role->users()->detach($user);
                    $role = Sentinel::findRoleById($role_id);
                    $role->users()->attach($user);
                }
            }
        });

    }

}

/**
 * trigger construct for static class
 */
GuardUser::constructStatic();
