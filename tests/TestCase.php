<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\CreatesTenantUsers;

abstract class TestCase extends BaseTestCase
{
    use CreatesTenantUsers;
}
