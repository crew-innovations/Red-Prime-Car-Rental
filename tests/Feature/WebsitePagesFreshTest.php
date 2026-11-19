<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 19 Nov (Bilal): a car added in the CRM shows on "All cars" straight away, not after the browser's 5 minutes. */
class WebsitePagesFreshTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    public function test_all_cars_page_is_checked_every_time_and_shows_a_new_car_at_once(): void
    {
        $this->car(['name' => 'Range Rover Sport', 'slug' => 'range-rover-sport', 'is_published' => true]);

        $first = $this->get(route('site.cars'));
        $first->assertOk()->assertSee('Range Rover Sport');
        $this->assertStringContainsString('no-cache', $first->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age=300', $first->headers->get('Cache-Control'));
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        // Nothing changed: a short "not changed" answer.
        $this->withHeaders(['If-None-Match' => $etag])->get(route('site.cars'))->assertStatus(304);

        // A car added in the CRM: the same browser gets the new page.
        $this->car(['name' => 'Audi A3', 'slug' => 'audi-a3', 'is_published' => true]);
        $this->withHeaders(['If-None-Match' => $etag])->get(route('site.cars'))->assertOk()->assertSee('Audi A3')->assertSee('Range Rover Sport');
    }
}
