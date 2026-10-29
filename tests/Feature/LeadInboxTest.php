<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Support\Leads\LeadIntake;
use App\Support\Leads\Sources\TikTok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 28 Oct-2, Phases 15–16: leads from Meta / TikTok / Snapchat, assignment rules, shared forms. Platforms mocked. */
class LeadInboxTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function source(string $key, array $secrets, array $config = []): Integration
    {
        $i = Integration::for($key);
        $i->fill(['enabled' => true, 'mode' => 'live', 'config' => $config, 'status' => 'configured']);
        $i->putSecrets($secrets, 'live');
        $i->save();

        return $i;
    }

    protected function metaHook(string $leadId): string
    {
        return json_encode(['object' => 'page', 'entry' => [['id' => '111', 'time' => time(), 'changes' => [['field' => 'leadgen', 'value' => [
            'leadgen_id' => $leadId, 'page_id' => '111', 'form_id' => '222', 'ad_id' => '333', 'created_time' => time(),
        ]]]]]]);
    }

    protected function postSigned(string $url, string $raw, string $header, string $secret, bool $prefix = true)
    {
        $sig = hash_hmac('sha256', $raw, $secret);

        return $this->call('POST', $url, [], [], [], ['HTTP_'.strtoupper(str_replace('-', '_', $header)) => ($prefix ? 'sha256=' : '').$sig, 'CONTENT_TYPE' => 'application/json'], $raw);
    }

    public function test_meta_webhook_verify_signature_fetch_and_no_duplicates(): void
    {
        $this->source('meta', ['app_secret' => 'appsecret', 'page_access_token' => 'EAAtoken', 'verify_token' => 'verify-me'], ['default_service' => 'car', 'page_id' => '111']);

        $this->get('/api/webhooks/leads/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123')->assertForbidden();
        $this->get('/api/webhooks/leads/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=123')->assertOk()->assertSeeText('123');

        Http::fake(['graph.facebook.com/v21.0/9001*' => Http::response(['id' => '9001', 'created_time' => '2026-11-02T08:59:00+0000', 'platform' => 'ig',
            'campaign_id' => 'c1', 'campaign_name' => 'G63 November', 'ad_id' => '333', 'ad_name' => 'Reel 1', 'form_id' => '222',
            'field_data' => [['name' => 'full_name', 'values' => ['Omar Khalid']], ['name' => 'phone_number', 'values' => ['+971501112233']],
                ['name' => 'email', 'values' => ['omar@example.com']], ['name' => 'which_car_do_you_want?', 'values' => ['G63']]]])]);

        $raw = $this->metaHook('9001');
        $this->postSigned('/api/webhooks/leads/meta', $raw, 'X-Hub-Signature-256', 'wrong')->assertStatus(400);
        $this->assertSame(0, Lead::count());

        $this->postSigned('/api/webhooks/leads/meta', $raw, 'X-Hub-Signature-256', 'appsecret')->assertOk()->assertJson(['leads' => ['created']]);
        $this->postSigned('/api/webhooks/leads/meta', $raw, 'X-Hub-Signature-256', 'appsecret')->assertOk()->assertJson(['leads' => ['duplicate']]);

        $lead = Lead::sole();
        $this->assertSame('Omar Khalid', $lead->name);
        $this->assertSame('meta', $lead->channel);
        $this->assertSame(ServiceType::Car, $lead->service_type);
        $this->assertSame('G63 November', $lead->platform['campaign_name']);
        $this->assertSame('G63 November', $lead->utm_campaign);
        $this->assertSame('G63', $lead->answers['Which car do you want?']);
        $this->assertSame('meta:9001', $lead->external_id);
        Http::assertSentCount(1); // the lead is read once
    }

    public function test_same_person_with_an_open_lead_is_added_to_it(): void
    {
        [$first] = LeadIntake::receive(['name' => 'Sara', 'phone' => '050 111 2233', 'channel' => 'form']);
        [$again, $what] = LeadIntake::receive(['name' => 'Sara M', 'phone' => '+971 50 111 2233', 'channel' => 'tiktok', 'answers' => ['Car' => 'Range Rover']]);

        $this->assertSame('merged', $what);
        $this->assertTrue($first->is($again));
        $this->assertSame(1, Lead::count());
        $this->assertStringContainsString('Range Rover', $first->activities()->latest('id')->value('body'));
    }

    public function test_round_robin_and_by_service_assignment(): void
    {
        $a = $this->user('Sales', ['name' => 'Agent A']);
        $b = $this->user('Sales', ['name' => 'Agent B']);
        $c = $this->user('Sales', ['name' => 'Yacht Desk']);
        $this->settings(['lead_assignment' => 'round_robin', 'lead_assignment_people' => [$a->id, $b->id]]);

        $who = [];
        foreach (['0501000001', '0501000002', '0501000003'] as $i => $phone) {
            Carbon::setTestNow(now()->addMinute());
            [$lead] = LeadIntake::receive(['name' => 'L'.$i, 'phone' => $phone, 'channel' => 'meta']);
            $who[] = $lead->fresh()->assigned_to;
        }
        $this->assertSame([$a->id, $b->id, $a->id], $who);
        $this->assertSame('pending', Lead::where('assigned_to', $a->id)->first()->assignment_status);

        $this->settings(['lead_assignment' => 'by_service', 'lead_assignment_by_service' => [['service' => 'yacht', 'user_id' => $c->id]]]);
        [$yacht] = LeadIntake::receive(['name' => 'Y', 'phone' => '0501000009', 'channel' => 'meta', 'service_type' => 'yacht']);
        $this->assertSame($c->id, $yacht->fresh()->assigned_to);

        $this->settings(['lead_assignment' => 'manual']);
        [$none] = LeadIntake::receive(['name' => 'M', 'phone' => '0501000010', 'channel' => 'meta']);
        $this->assertNull($none->fresh()->assigned_to);
    }

    public function test_tiktok_signed_webhook_and_connection_test(): void
    {
        $i = $this->source('tiktok', ['webhook_secret' => 'tt-secret']);
        $this->assertFalse(TikTok::test($i)[0]); // no test lead yet

        $raw = json_encode(['lead_id' => 'tt-1', 'form_id' => 'f9', 'form_name' => 'Yacht form', 'campaign_name' => 'Marina', 'ad_id' => 'a1',
            'fields' => [['name' => 'name', 'value' => 'Lina'], ['name' => 'phone_number', 'value' => '0559990000'], ['name' => 'Guests', 'value' => '8']]]);
        $this->postSigned('/api/webhooks/leads/tiktok', $raw, 'X-TikTok-Signature', 'nope', false)->assertStatus(400);
        $this->postSigned('/api/webhooks/leads/tiktok', $raw, 'X-TikTok-Signature', 'tt-secret', false)->assertOk();

        $lead = Lead::sole();
        $this->assertSame('tiktok', $lead->channel);
        $this->assertSame('Lina', $lead->name);
        $this->assertSame('8', $lead->answers['Guests']);
        $this->assertSame('Yacht form', $lead->platform['form_name']);
        $this->assertTrue(TikTok::test($i->fresh())[0]);
        $this->postJson('/api/webhooks/leads/snapchat', [])->assertNotFound(); // not set up
    }

    public function test_shared_form_makes_leads_and_closes(): void
    {
        $owner = $this->user('Sales');
        $form = LeadForm::create(['name' => 'Ramadan yacht offer', 'service_type' => 'yacht', 'assign_to' => $owner->id, 'max_submissions' => 1,
            'fields' => [['label' => 'How many guests?', 'type' => 'number', 'required' => true], ['label' => 'Occasion', 'type' => 'select', 'options' => ['Birthday', 'Corporate']]]]);

        $this->get($form->url())->assertOk()->assertSee('How many guests?')->assertSee('Corporate');
        $this->post(route('forms.store', $form->token), ['name' => 'Hassan', 'phone' => '0507770000'])->assertSessionHasErrors('q.how_many_guests');
        $this->post(route('forms.store', $form->token), ['name' => 'Bot', 'phone' => '0507770001', 'website' => 'spam', 'q' => ['how_many_guests' => 3]]);
        $this->assertSame(0, Lead::count());

        $this->post(route('forms.store', $form->token), ['name' => 'Hassan', 'phone' => '0507770000', 'q' => ['how_many_guests' => 12, 'occasion' => 'Birthday']])
            ->assertRedirect($form->url());
        $lead = Lead::sole();
        $this->assertSame('form', $lead->channel);
        $this->assertSame(ServiceType::Yacht, $lead->service_type);
        $this->assertSame($owner->id, $lead->assigned_to);
        $this->assertSame(['How many guests?' => '12', 'Occasion' => 'Birthday'], $lead->answers);
        $this->assertSame('Ramadan yacht offer', $lead->platform['form_name']);

        $this->get($form->url())->assertSee('Thank you');
        $this->get($form->url())->assertSee('This form is full.');
        $this->post(route('forms.store', $form->token), ['name' => 'Late', 'phone' => '0507770002', 'q' => ['how_many_guests' => 2]])->assertSessionHasErrors('form');
        $this->assertSame(1, Lead::count());

        $form->update(['max_submissions' => null, 'expires_at' => now()->subMinute()]);
        $this->get($form->url())->assertSee('This form has expired.');
        $this->get('/f/'.str_repeat('a', 40))->assertNotFound();
    }
}
