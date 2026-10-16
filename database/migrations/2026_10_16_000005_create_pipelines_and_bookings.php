<?php

use App\Enums\Pipeline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Sales pipelines on leads (Schedule A3), self-drive / with-driver cars,
 * bookings with delivery and return (A7, A10) and the Driver role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('pipeline')->nullable()->index()->after('status');
            $table->string('stage')->nullable()->index()->after('pipeline');
            $table->string('company_name')->nullable()->after('name');
        });

        // Put existing leads into the matching pipeline stage.
        $map = [
            'car' => ['new' => 'new', 'contacted' => 'contacted', 'qualified' => 'requirements_confirmed', 'converted' => 'confirmed', 'lost' => 'lost'],
            'yacht' => ['new' => 'new', 'contacted' => 'new', 'qualified' => 'requirements_confirmed', 'converted' => 'confirmed', 'lost' => 'lost'],
        ];
        foreach (DB::table('leads')->select('id', 'service_type', 'status')->get() as $lead) {
            $pipeline = Pipeline::forService($lead->service_type)->value;
            DB::table('leads')->where('id', $lead->id)->update([
                'pipeline' => $pipeline,
                'stage' => $map[$pipeline][$lead->status] ?? 'new',
            ]);
        }

        Schema::table('cars', function (Blueprint $table) {
            $table->boolean('self_drive')->default(true)->after('deposit');
            $table->boolean('with_driver')->default(false)->after('self_drive');
            $table->decimal('chauffeur_hourly_price', 10, 2)->nullable()->after('chauffeur_daily_price');
            $table->unsignedTinyInteger('driver_hours_per_day')->nullable()->after('chauffeur_hourly_price');
        });
        DB::table('cars')->whereNotNull('chauffeur_daily_price')->update(['with_driver' => true]);

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();
            $table->string('status')->default('confirmed')->index();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type')->index();             // car / yacht / trip
            $table->nullableMorphs('bookable');                  // the car / yacht / trip
            $table->foreignId('trip_date_id')->nullable()->constrained()->nullOnDelete();
            $table->string('drive_mode')->nullable();            // self_drive / with_driver
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->index();
            $table->string('pickup_location')->nullable();
            $table->string('return_location')->nullable();
            $table->unsignedSmallInteger('guests')->nullable();

            // Price
            $table->string('rate_type')->nullable();             // day / week / month / hour / half_day / full_day / guest
            $table->decimal('quantity', 8, 2)->nullable();
            $table->decimal('rate', 10, 2)->nullable();
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('extras_amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('security_deposit', 12, 2)->default(0);
            $table->decimal('extra_charges', 12, 2)->default(0);  // added on return
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('payment_status')->nullable()->index();

            // Delivery / handover
            $table->dateTime('delivered_at')->nullable();
            $table->unsignedInteger('delivery_km')->nullable();
            $table->string('delivery_fuel')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();

            // Return
            $table->dateTime('returned_at')->nullable();
            $table->unsignedInteger('return_km')->nullable();
            $table->string('return_fuel')->nullable();
            $table->string('return_condition')->nullable();       // good / minor / damaged
            $table->json('charges')->nullable();                  // breakdown of return charges
            $table->text('return_notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['bookable_type', 'bookable_id', 'start_at']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->boolean('is_balance')->default(false)->after('booking_id'); // system row: balance still to collect
        });

        Role::firstOrCreate(['name' => 'Driver', 'guard_name' => 'web']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
            $table->dropColumn('is_balance');
        });

        Schema::dropIfExists('bookings');

        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn(['self_drive', 'with_driver', 'chauffeur_hourly_price', 'driver_hours_per_day']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['pipeline']);
            $table->dropIndex(['stage']);
            $table->dropColumn(['pipeline', 'stage', 'company_name']);
        });
    }
};
