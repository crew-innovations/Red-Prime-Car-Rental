<?php

namespace Tests\Feature;

use App\Models\ChatThread;
use App\Support\Accounts;
use App\Support\Chat\Chat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 28 Oct-2, Phase 17: customer ↔ team and driver ↔ team messages, internal notes kept apart. */
class ChatTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_customer_and_team_talk_and_internal_notes_stay_internal(): void
    {
        $agent = $this->user('Agent');
        $this->actingAs($agent);
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', ['assigned_to' => $agent->id]);
        $other = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00');
        $customer = Accounts::customerAccount($b->customer, 'cust@example.com');
        $stranger = Accounts::customerAccount($other->customer, 'other@example.com');

        $this->actingAs($customer);
        $thread = Chat::threadForCustomer($b);
        Chat::post($thread, 'Can you deliver to Marina instead?');
        $this->assertSame(1, $agent->notifications()->count()); // the booking's agent hears about it

        $this->actingAs($agent);
        $this->assertSame(1, Chat::unread());
        Chat::markRead($thread);
        $this->assertSame(0, Chat::unread());
        Chat::post($thread, 'Customer is VIP, waive delivery fee?', internal: true);
        Chat::post($thread, 'Yes, Marina is fine.');

        $this->actingAs($customer);
        $this->assertSame(1, Chat::unread()); // the internal note does not count
        $this->assertSame(['Can you deliver to Marina instead?', 'Yes, Marina is fine.'], Chat::messages($thread)->pluck('body')->all());
        $this->get('/account/messages?thread='.$thread->id)->assertOk()
            ->assertSee('Yes, Marina is fine.')->assertDontSee('waive delivery fee');

        // A customer can never write an internal note, nor reach someone else's thread.
        $m = Chat::post($thread, 'Thanks', internal: true);
        $this->assertFalse($m->internal);
        $this->actingAs($stranger);
        $this->assertSame(0, Chat::visible()->count());
        try {
            Chat::post($thread, 'hello');
            $this->fail('Stranger must not post');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            Chat::threadForCustomer($b);
            $this->fail('Stranger must not open the booking');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // Another agent (own records only) doesn't see it either.
        $this->actingAs($this->user('Agent'));
        $this->assertSame(0, Chat::visible()->count());
    }

    /** Separate test: in one test run the app keeps the panel of the previous request. */
    public function test_team_page_shows_messages_and_notes_and_marks_read(): void
    {
        $agent = $this->user('Agent');
        $this->actingAs($agent);
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', ['assigned_to' => $agent->id]);
        $customer = Accounts::customerAccount($b->customer, 'cust@example.com');
        $thread = Chat::customerThread($b);
        Chat::post($thread, 'Customer is VIP, waive delivery fee?', internal: true);
        Chat::post($thread, 'Can you deliver to Marina instead?', user: $customer);
        $this->assertSame(1, Chat::unread());

        $this->get(\App\Filament\Pages\Messages::getUrl(['thread' => $thread->id]))->assertOk()
            ->assertSee('Can you deliver to Marina instead?')->assertSee('waive delivery fee')->assertSee('Team only');
        $this->assertSame(0, Chat::unread());
    }

    public function test_driver_sees_only_own_thread_without_notes(): void
    {
        $manager = $this->user('Manager');
        $driver = $this->user('Driver');
        $driver2 = $this->user('Driver');
        $this->actingAs($manager);
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00');
        $dThread = Chat::driverThread($b, $driver);
        $cThread = Chat::customerThread($b);
        Chat::post($dThread, 'Pick up the keys at 9.');
        Chat::post($dThread, 'Driver was late last time', internal: true);
        Chat::post($cThread, 'Customer thread');
        $this->assertSame(1, $driver->notifications()->count());

        $this->actingAs($driver);
        $this->assertSame([$dThread->id], Chat::visible()->pluck('id')->all());
        $this->get(\App\Filament\Pages\Messages::getUrl(['thread' => $dThread->id]))->assertOk()
            ->assertSee('Pick up the keys at 9.')->assertDontSee('late last time')->assertDontSee('Customer thread');
        Chat::post($dThread, 'OK, on my way');

        $this->actingAs($driver2);
        $this->assertSame(0, Chat::visible()->count());

        $this->actingAs($manager);
        $this->assertSame(1, Chat::unread());
        $this->assertSame(3, ChatThread::find($dThread->id)->messages()->count());
    }
}
