<?php

return [
    /*
    |--------------------------------------------------------------------------
    | User Provider (test fixture)
    |--------------------------------------------------------------------------
    | Use the in-memory FixtureUserProvider so integration tests can exercise
    | the user-resolution path without a real database or Sentinel.
    |
    | Known IDs: 'user-99', 'user-7'
    | Unknown IDs: anything else → retrieveById returns null → 401 (D5-B)
    */
    'provider' => \IonsFixture\Auth\FixtureUserProvider::class,
];
