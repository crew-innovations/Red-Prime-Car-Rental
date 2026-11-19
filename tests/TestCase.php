<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests written before 18 Nov make confirmed car bookings for customers with only a name and a
     * phone. The 18 Nov rule (confirm only with complete details) is switched off for them here and
     * tested on its own in RentalContractsTest.
     */
    protected bool $confirmNeedsDetails = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Settings are kept in memory between tests (static); start each test from the database.
        (fn () => static::$memo = null)->bindTo(null, \App\Models\Setting::class)();
        if (! $this->confirmNeedsDetails && \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            \App\Models\Setting::write(['confirm_requires_details' => false]);
        }
    }
}
