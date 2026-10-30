<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A persistent, email-verified user (the default state for everyone who's signed in). */
    protected function mkUser(array $attrs = []): User
    {
        return User::create(array_merge(['email_verified_at' => now()], $attrs));
    }
}
