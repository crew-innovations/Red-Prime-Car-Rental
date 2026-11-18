<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\BookingJob;
use App\Models\DriverLocation;
use App\Models\Setting;
use App\Support\Drivers\DriverFlow;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Small JSON endpoints for the driver's phone (4 Nov, 15). Steps and "running late" are sent here
 * with the phone's own action id, so they can wait on the phone without signal and be sent again
 * later without being done twice. Location only while a job is active and only when switched on.
 */
class DriverApiController extends Controller
{
    public function action(Request $request, int $job)
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('Driver') && $user->is_active, 403);
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', DriverFlow::ACTIONS)],
            'client_action_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $record = BookingJob::find($job);
        abort_unless($record && (int) $record->driver_id === (int) $user->id, 404);

        try {
            $result = DriverFlow::run($record, $user, $data['action'], array_filter(['minutes' => $data['minutes'] ?? null, 'reason' => $data['reason'] ?? null]), $data['client_action_id'] ?? null);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->implode(' ')], 422);
        }

        return response()->json(['ok' => true, 'result' => $result, 'status' => $record->fresh()->status]);
    }

    public function location(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('Driver') && $user->is_active, 403);
        if (! Setting::read('driver_location_enabled', false) && ! Setting::read('limo_location_during_trips', true)) {
            return response()->json(['ok' => false, 'off' => true]);
        }
        $data = $request->validate([
            'job' => ['required', 'integer'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:100'], // m/s from the browser
            'at' => ['nullable', 'integer'],
        ]);
        $job = BookingJob::with('booking')->find($data['job']);
        if (! $job || (int) $job->driver_id !== (int) $user->id || ! \App\Support\Transfers\Tracking::sharesLocation($job)) {
            return response()->json(['ok' => false, 'active' => false]); // the phone stops sharing (6 Nov: only during a trip unless the general setting is on)
        }
        $at = isset($data['at']) ? \Illuminate\Support\Carbon::createFromTimestampMs($data['at']) : now();
        if ($at->gt(now()->addMinutes(2)) || $at->lt(now()->subHours(6))) {
            $at = now();
        }
        $last = DriverLocation::where('user_id', $user->id)->latest('recorded_at')->first();
        $min = max(15, (int) Setting::read('location_interval_seconds', 60)) - 5;
        if ($last && $last->recorded_at->gt($at->copy()->subSeconds($min))) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }
        DriverLocation::create([
            'user_id' => $user->id, 'booking_job_id' => $job->id,
            'lat' => round((float) $data['lat'], 7), 'lng' => round((float) $data['lng'], 7),
            'accuracy' => isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
            'speed' => isset($data['speed']) ? (int) round((float) $data['speed'] * 3.6) : null,
            'recorded_at' => $at, 'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
