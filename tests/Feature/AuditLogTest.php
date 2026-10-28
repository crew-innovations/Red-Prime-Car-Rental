<?php

namespace Tests\Feature;

use App\Filament\Resources\HistoryResource\Pages\ListHistory;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\CustomerDocument;
use App\Models\User;
use Filament\Pages\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 28 Oct-2, Phase 8: audit log of sign-ins, changes, permissions, exports and document access. */
class AuditLogTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_sign_in_and_failed_sign_in_are_logged_without_the_password(): void
    {
        $user = User::create(['name' => 'Sara', 'email' => 'sara@test.local', 'password' => 'Right-Pass-123', 'is_active' => true]);
        $user->assignRole('Sales');

        Livewire::test(Login::class)->fillForm(['email' => 'sara@test.local', 'password' => 'Wrong-Pass-999'])->call('authenticate');
        $failed = AuditLog::where('event', 'login_failed')->firstOrFail();
        $this->assertSame('failed', $failed->outcome);
        $this->assertSame('sara@test.local', $failed->new_values['email']);
        $this->assertStringNotContainsString('Wrong-Pass-999', json_encode($failed->toArray()));

        Livewire::test(Login::class)->fillForm(['email' => 'sara@test.local', 'password' => 'Right-Pass-123'])->call('authenticate');
        $login = AuditLog::where('event', 'login')->firstOrFail();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame('Sales', $login->role);
        $this->assertNotNull($login->request_id);
    }

    public function test_lines_cannot_be_changed_or_deleted_and_secrets_are_masked(): void
    {
        $this->actingAs($this->user('Manager'));
        $log = AuditLog::event('integration', 'Stripe keys saved', ['secret_key' => 'sk_live_123', 'webhook_secret' => 'whsec_9', 'mode' => 'live', 'nested' => ['api_key' => 'k']]);
        $this->assertSame('••••••', $log->new_values['secret_key']);
        $this->assertSame('••••••', $log->new_values['webhook_secret']);
        $this->assertSame('••••••', $log->new_values['nested']['api_key']);
        $this->assertSame('live', $log->new_values['mode']);
        $this->assertSame('Manager', $log->role);

        try {
            $log->update(['note' => 'changed']);
            $this->fail('audit lines are append-only');
        } catch (LogicException) {
            $this->assertSame('Stripe keys saved', $log->fresh()->note);
        }
        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_role_changes_are_logged_with_before_and_after(): void
    {
        $this->actingAs($this->user('Super Admin'));
        $member = $this->user('Sales');
        Livewire::test(EditUser::class, ['record' => $member->id])
            ->fillForm(['roles' => [\Spatie\Permission\Models\Role::findByName('Manager')->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $line = AuditLog::where('event', 'permissions')->firstOrFail();
        $this->assertSame(['roles' => ['Sales']], $line->old_values);
        $this->assertSame(['roles' => ['Manager']], $line->new_values);
    }

    public function test_opening_and_refusing_identity_documents_is_logged(): void
    {
        Storage::disk('private')->put('customers/1/p.pdf', 'secret');
        $doc = CustomerDocument::create(['customer_id' => $this->customer()->id, 'type' => 'passport', 'path' => 'customers/1/p.pdf']);

        $this->actingAs($this->user('Sales'));
        $this->get($doc->viewUrl())->assertOk();
        $opened = AuditLog::where('event', 'download')->firstOrFail();
        $this->assertNull($opened->outcome);
        $this->assertSame($doc->id, $opened->auditable_id);
    }

    public function test_driver_refused_a_customer_document_is_logged(): void
    {
        Storage::disk('private')->put('customers/1/p.pdf', 'secret');
        $doc = CustomerDocument::create(['customer_id' => $this->customer()->id, 'type' => 'passport', 'path' => 'customers/1/p.pdf']);
        $this->actingAs($this->user('Driver'));
        $this->get($doc->viewUrl())->assertForbidden();
        $this->assertSame('denied', AuditLog::where('event', 'download')->firstOrFail()->outcome);
    }

    public function test_audit_screen_filters_and_is_closed_to_agents(): void
    {
        $this->actingAs($this->user('Manager'));
        AuditLog::event('login_failed', 'Failed sign-in for x@y.z', ['email' => 'x@y.z'], 'failed', 'user');
        AuditLog::event('export', 'Exported 3 row(s) of Leads as CSV', ['rows' => 3], null, 'lead');

        Livewire::test(ListHistory::class)
            ->filterTable('event', ['login_failed'])
            ->assertSee('Failed sign-in for x@y.z')
            ->assertDontSee('Exported 3 row(s)');
        Livewire::test(ListHistory::class)
            ->filterTable('outcome', 'failed')
            ->assertSee('x@y.z')
            ->assertDontSee('Exported 3');
        Livewire::test(ListHistory::class)->searchTable('Exported')->assertSee('Exported 3 row(s)')->assertDontSee('x@y.z');
    }

    public function test_agents_cannot_open_the_audit_log(): void
    {
        $this->actingAs($this->user('Agent'));
        $this->get('/crm/history')->assertForbidden();
    }

    public function test_retention_command_keeps_everything_unless_set(): void
    {
        $this->actingAs($this->user('Manager'));
        AuditLog::event('settings', 'old line');
        \Illuminate\Support\Facades\DB::table('audit_logs')->update(['created_at' => now()->subDays(400)]);

        $this->artisan('crm:prune-audit-log')->assertSuccessful();
        $this->assertSame(1, AuditLog::where('note', 'old line')->count(), 'kept: no retention set');

        $this->settings(['audit_retention_days' => 365]);
        $this->artisan('crm:prune-audit-log')->assertSuccessful();
        $this->assertSame(0, AuditLog::where('note', 'old line')->count());
        $this->assertSame(1, AuditLog::where('note', 'like', 'Audit log lines older than 365 days removed:%')->count(), 'the removal is logged');
    }
}
