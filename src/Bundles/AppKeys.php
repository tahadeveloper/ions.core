<?php

namespace Ions\Bundles;

use DateTimeImmutable;
use Ions\Foundation\Singleton;
use Ions\Support\Storage;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IdentifiedBy;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;

class AppKeys extends Singleton
{
    public static function createKey(): void
    {
        if (!file_exists(Path::var('key.pem'))) {
            $config = array(
                "digest_alg" => "sha512",
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            );
            $private_key = openssl_pkey_new($config);
            $public_key_pem = openssl_pkey_get_details($private_key)['key'];

            Storage::put(Path::var('key.pem'), $public_key_pem);
        }
    }

    public static function configJWT(): Configuration|null
    {
        if (Storage::exists(Path::var('key.pem'))) {
            $key = InMemory::file(Path::var('key.pem'));
            return Configuration::forSymmetricSigner(new Sha256(), $key);
        }
        return null;
    }

    public static function createJWT(): Plain|null
    {
        $config_jwt = self::configJWT();
        if ($config_jwt) {
            $now = new DateTimeImmutable();

            return $config_jwt->builder()
                ->issuedBy(env('APP_NAME'))
                ->permittedFor(env('APP_NAME'))
                ->identifiedBy(config('app.app_id'))
                ->issuedAt($now)
                ->getToken($config_jwt->signer(), $config_jwt->signingKey());
        }
        return null;
    }

    public static function validateJWT($token): array
    {
        $config_jwt = self::configJWT();
        if ($config_jwt) {
            $parse_token = $config_jwt->parser()->parse($token);

            $issue_by = new IssuedBy(env('APP_NAME'));
            $permitted_for = new PermittedFor(env('APP_NAME'));
            $identified_by = new IdentifiedBy(config('app.app_id'));
            $config_jwt->setValidationConstraints($issue_by, $permitted_for, $identified_by);

            $constraints = $config_jwt->validationConstraints();

            try {
                $config_jwt->validator()->assert($parse_token, ...$constraints);
                return ['success' => true];
            } catch (RequiredConstraintsViolated $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }
        return ['success' => false, 'error' => 'No key'];
    }
}
