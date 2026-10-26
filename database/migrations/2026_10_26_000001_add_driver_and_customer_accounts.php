<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * One shared CRM for staff, agents, drivers and customers (26 Oct brief).
 * Additive only: new tables and nullable columns, nothing is renamed or dropped.
 *
 * - users: link to a customer record, a separate "can log in" switch, invite / last login times.
 * - driver_profiles: personal, contact and employment details of a driver (one per driver user).
 * - driver_documents: passport, ID, visa, licence and other files with numbers and expiry dates.
 * - booking_jobs: the delivery and collection of a car booking for its driver, with status.
 * - damage_reports: damage a driver reports; staff review it (drivers never set charges).
 * - bookings.driver_instructions: what the driver should know.
 * - notifications: the standard Laravel table for in-app notifications.
 * - roles Agent and Customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->unique()->after('id')->constrained('customers')->nullOnDelete();
            $t->boolean('login_enabled')->default(true)->after('is_active');
            $t->timestamp('invited_at')->nullable()->after('login_enabled');
            $t->timestamp('last_login_at')->nullable()->after('invited_at');
        });

        Schema::create('driver_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('legal_name')->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('nationality', 80)->nullable();
            $t->string('gender', 20)->nullable();
            $t->text('address')->nullable();
            $t->string('emergency_name')->nullable();
            $t->string('emergency_phone', 30)->nullable();
            $t->string('employee_ref', 50)->nullable();
            $t->date('joined_on')->nullable();
            $t->timestamps();
        });

        Schema::create('driver_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40)->index();               // passport / emirates_id / visa / licence / permit / other
            $t->string('label')->nullable();               // name for "other"
            $t->text('number')->nullable();                // stored encrypted
            $t->string('issued_by')->nullable();           // country or authority
            $t->json('classes')->nullable();               // licence categories
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable()->index();
            $t->string('path')->nullable();                // private disk
            $t->string('original_name')->nullable();
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size')->nullable();       // KB
            $t->text('notes')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('replaced_at')->nullable();      // a newer copy of the same type was added
            $t->timestamp('reminded_soon_at')->nullable();
            $t->timestamp('reminded_expired_at')->nullable();
            $t->timestamps();
        });

        Schema::create('booking_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('type', 20);                        // delivery / collection
            $t->dateTime('scheduled_at')->index();
            $t->string('address')->nullable();
            $t->string('status', 20)->default('assigned')->index();
            $t->timestamp('status_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('inspection')->nullable();            // km, fuel, condition, checklist, notes
            $t->timestamps();
            $t->unique(['booking_id', 'type']);
            $t->index(['driver_id', 'scheduled_at']);
        });

        Schema::create('damage_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_job_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('stage', 20)->nullable();           // delivery / collection / other
            $t->text('description');
            $t->string('status', 20)->default('open')->index(); // open / no_charge / charged
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
        });

        Schema::table('bookings', function (Blueprint $t) {
            $t->text('driver_instructions')->nullable()->after('notes');
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('type');
                $t->morphs('notifiable');
                $t->text('data');
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }

        foreach (['Agent', 'Customer', 'Driver'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Saved role permissions replace the defaults, so give the new Agent role its
        // starting abilities there too (Admin can change them under Business settings).
        $row = DB::table('settings')->where('key', 'role_abilities')->first();
        if ($row) {
            $perms = json_decode($row->value, true) ?: [];
            foreach (['manage-leads', 'manage-bookings', 'record-payments', 'review-documents', 'manage-quotations'] as $ability) {
                if (isset($perms[$ability]) && is_array($perms[$ability]) && ! in_array('Agent', $perms[$ability], true)) {
                    $perms[$ability][] = 'Agent';
                }
            }
            DB::table('settings')->where('key', 'role_abilities')->update(['value' => json_encode($perms)]);
        }
        \Illuminate\Support\Facades\Cache::forget('crm.settings');
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('driver_instructions'));
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('booking_jobs');
        Schema::dropIfExists('driver_documents');
        Schema::dropIfExists('driver_profiles');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['login_enabled', 'invited_at', 'last_login_at']);
        });
        // The notifications table and the roles are left in place (harmless, may hold data).
    }
};
