<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
    }

    protected function tearDown(): void
    {
        // Livewire keeps redirect state between tests in one process.
        \Livewire\Livewire::flushState();
        parent::tearDown();
    }

    /** Change business settings for one test. */
    protected function settings(array $values): void
    {
        Setting::write($values);
    }
}
