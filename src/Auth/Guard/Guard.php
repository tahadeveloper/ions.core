<?php

namespace Ions\Auth\Guard;

use Cartalyst\Sentinel\Native\ConfigRepository;
use Cartalyst\Sentinel\Native\Facades\Sentinel;
use Cartalyst\Sentinel\Native\SentinelBootstrapper;
use Ions\Bundles\Path;
use Ions\Foundation\Singleton;
use Ions\Support\DB;

/**
 * @deprecated Inject Ions\Auth\Contracts\UserProvider instead; the static Guard facade is retained for BC.
 *             Sentinel-specific features (activation, reminders, throttling) remain routed through Sentinel.
 *             New code should resolve the UserProvider from the container and call its methods directly.
 */
class Guard extends Singleton
{
    /**
     * static call
     * @return void
     */
    public static function constructStatic(): void
    {
        $config = new ConfigRepository(Path::auth('Sentinel/config.php'));
        // SentinelBootstrapper's constructor declares `array $config` but its
        // body explicitly accepts a ConfigRepository too — vendor docblock bug.
        /** @phpstan-ignore argument.type */
        $bootstrapper = new SentinelBootstrapper($config);
        Sentinel::instance($bootstrapper);
    }

    /**
     * @return mixed
     */
    public static function check(): mixed
    {
        /** @phpstan-ignore staticMethod.notFound */
        return Sentinel::check();
    }

    /**
     * @param array $credentials
     * @param bool $is_remember
     * @return bool|array
     */
    public static function login(array $credentials, bool $is_remember = false): bool|array
    {

        /** @phpstan-ignore staticMethod.notFound */
        $user = Sentinel::findByCredentials($credentials);
        if (!$user) {
            return ['error' => 'wrong_data', 'error_no' => 1];
        }

        /** @phpstan-ignore staticMethod.notFound */
        $Activation = Sentinel::getActivationRepository();
        if (!$Activation->completed($user)) {
            return ['error' => 'not_active', 'error_no' => 2];
        }

        /** @phpstan-ignore staticMethod.notFound */
        $result = Sentinel::authenticate($credentials, $is_remember, true);
        if (!$result) {
            return ['error' => 'wrong_credentials', 'error_no' => 3];
        }

        return true;
    }

    /**
     * @return mixed
     */
    public static function logout(): mixed
    {
        /** @phpstan-ignore staticMethod.notFound */
        return Sentinel::logout(null, true);
    }

    /**
     * @param $credentials
     * @return null
     */
    public static function forgetCode($credentials)
    {

        $reset_obj = null;
        /** @phpstan-ignore staticMethod.notFound */
        $user = Sentinel::findByCredentials($credentials);
        if ($user) {
            /** @phpstan-ignore staticMethod.notFound */
            $Reminder = Sentinel::getReminderRepository();
            $reset_obj = ($Reminder->create($user))->code;
        }

        return $reset_obj;
    }

    /**
     * @param $code
     * @return object|null
     */
    public static function checkResetCode($code): object|null
    {
        return DB::table('reminders')->where('code', $code)->first();
    }

    /**
     * @param $user_id
     * @param $key
     * @param $password
     * @return mixed
     */
    public static function completeReset($user_id, $key, $password): mixed
    {
        /** @phpstan-ignore staticMethod.notFound */
        $user = Sentinel::findById($user_id);
        /** @phpstan-ignore staticMethod.notFound */
        return Sentinel::getReminderRepository()->complete($user, $key, $password);
    }


}

/**
 * trigger construct for static class
 */
Guard::constructStatic();
