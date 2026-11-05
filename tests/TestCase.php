<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }
}
