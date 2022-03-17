<?php

namespace Ions\Auth\Sentinel;

use Cartalyst\Sentinel\Users\EloquentUser ;

class User extends EloquentUser {
    protected $fillable = [
        'email',
        'mobile',
        'mobile_2',
        'password',
        'address',
        'last_name',
        'first_name',
        'permissions',
        'notes',
        'status',
        'image',
        'image_name',
    ];

    protected $table = 'users';
    protected $loginNames = ['email','mobile'];
}
