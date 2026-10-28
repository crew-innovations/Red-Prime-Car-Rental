<?php

namespace App\Support\Documents;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\JobReport;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Job completion reports (brief 28 Oct-2, Phase 6). Built only from what the CRM
 * recorded (booking, handover / return, driver and crew jobs, damage, money).
 * Made automatically when a job is completed and again on request; every version
 * is kept. A report never completes a job. Identity documents are never included.
 */
class JobReports
{
    /** Required details for this kind of job that are still empty. */
    public static function missing(Booking $b): array
    {
        $out = [];
        $isCar = $b->service_type === ServiceType::Car;
        $isTransfer = $b->service_type === ServiceType::Transfer;
        $isCharter = $b->service_type === ServiceType::Yacht;
        if ($b->status !== BookingStatus::Completed) {
            $out[] = 'Job not completed yet';
        }
        if (($isCar || $isCharter || $isTransfer) && ! $b->delivered_at) {
            $out[] = $isTransfer ? 'Pickup time not recorded' : 'Handover not recorded';
        }
        if (($isCar || $isCharter || $isTransfer) && ! $b->returned_at) {
            $out[] = $isTransfer ? 'Drop-off not recorded' : 'Return not recorded';
        }
        if ($isCar) {
            if (! $b->delivery_km) {
                $out[] = 'Odometer at handover';
            }
            if (! $b->return_km) {
                $out[] = 'Odometer at return';
            }
            if (! $b->delivery_fuel) {
                $out[] = 'Fuel at handover';
            }
            if (! $b->return_fuel) {
                $out[] = 'Fuel at return';
            }
            if (! $b->return_condition) {
                $out[] = 'Condition at return';
            }
            $min = (int) Setting::read('return_min_photos', 0);
            if ($min && $b->getMedia('return')->count() < $min) {
                $out[] = "Return photos (at least {$min})";
            }
        }
        if (($isCharter || $b->service_type === ServiceType::Trip) && ! $b->guests) {
            $out[] = 'Number of guests';
        }
        if ($isTransfer && ! $b->driver_id) {
            $out[] = 'Driver';
        }
        if ($isTransfer && $b->journey_type !== 'hourly' && ! $b->route_km) {
            $out[] = 'Route distance';
        }
        if ((float) $b->balance > 0) {
            $out[] = 'Balance still open (AED '.number_format((float) $b->balance, 2).')';
        }
        if ($b->depositHeld() > 0) {
            $out[] = 'Security deposit still held (AED '.number_format($b->depositHeld(), 2).')';
        }
        if ($b->damageReports()->where('status', 'open')->exists()) {
            $out[] = 'Damage report not reviewed';
        }

        return $out;
    }

    /** Everything the report shows, gathered once (also used by tests). */
    public static function data(Booking $b, ?User $by): array
    {
        $b->loadMissing(['customer', 'bookable', 'assignee', 'driver', 'deliveredBy', 'receivedBy', 'jobs.driver', 'damageReports', 'transferService', 'vehicleCategory']);
        $money = $b->transactions()->where('is_balance', false)->orderBy('date')->orderBy('id')->get();
        $paid = fn ($t) => in_array($t->status, [PaymentStatus::Paid, PaymentStatus::PartiallyPaid], true) && $t->counts();

        return [
            'b' => $b,
            'by' => $by,
            'missing' => self::missing($b),
            'payments' => $money->filter(fn ($t) => $t->type?->value === 'income' && ! in_array($t->category, ['deposit'], true)),
            'deposits' => $money->filter(fn ($t) => in_array($t->category, ['deposit', 'deposit_refund'], true)),
            'refunds' => $money->filter(fn ($t) => $t->status === PaymentStatus::Refunded),
            'paidOk' => $paid,
            'photos' => self::photos($b),
            'crew' => $b->jobs->where('type', 'charter')->where('status', '!=', 'cancelled'),
            'jobs' => $b->jobs->where('status', '!=', 'cancelled')->sortBy('scheduled_at'),
        ];
    }

    /** Small copies of the handover, return and damage photos (at most 8), embedded in the PDF. */
    public static function photos(Booking $b, int $limit = 8): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return [];
        }
        $items = $b->getMedia('delivery')->map(fn ($m) => ['Handover', $m])
            ->concat($b->getMedia('return')->map(fn ($m) => ['Return', $m]))
            ->concat($b->damageReports->flatMap(fn ($r) => $r->getMedia('photos')->map(fn ($m) => ['Damage', $m])))
            ->take($limit);

        $out = [];
        foreach ($items as [$label, $media]) {
            try {
                $raw = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
                $img = $raw ? @imagecreatefromstring($raw) : false;
                if (! $img) {
                    continue;
                }
                $w = imagesx($img);
                $h = imagesy($img);
                $nw = min(360, $w);
                $nh = (int) round($h * $nw / max(1, $w));
                $thumb = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                ob_start();
                imagejpeg($thumb, null, 70);
                $jpg = ob_get_clean();
                imagedestroy($img);
                imagedestroy($thumb);
                $out[] = ['label' => $label, 'src' => 'data:image/jpeg;base64,'.base64_encode($jpg), 'at' => $media->created_at];
            } catch (\Throwable) {
                continue;
            }
        }

        return $out;
    }

    /** Makes a new version (the older ones stay). */
    public static function generate(Booking $b, ?User $by = null, string $reason = 'Job completed'): JobReport
    {
        $by ??= auth()->user();
        $data = self::data($b, $by);
        $pdf = Pdf::available();

        return DB::transaction(function () use ($b, $by, $reason, $data, $pdf) {
            $version = (int) JobReport::where('booking_id', $b->id)->lockForUpdate()->max('version') + 1;
            $report = JobReport::create([
                'booking_id' => $b->id, 'version' => $version, 'is_current' => false, 'path' => '', 'format' => $pdf ? 'pdf' : 'html',
                'missing' => $data['missing'] ?: null, 'reason' => mb_substr($reason, 0, 255), 'generated_by' => $by?->id,
            ])->fresh();
            $view = $data + ['number' => $report->number, 'version' => $version];
            $content = $pdf ? Pdf::render('pdf.job-report', $view) : view('pdf.job-report', $view + ['asPdf' => false])->render();
            $path = 'job-reports/'.$b->id.'/'.$report->number.'-'.$b->reference.'-v'.$version.'.'.($pdf ? 'pdf' : 'html');
            Storage::disk('private')->put($path, $content);
            JobReport::where('booking_id', $b->id)->whereKeyNot($report->id)->update(['is_current' => false]);
            $report->forceFill(['path' => $path, 'is_current' => true, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)])->save();
            AuditLog::note($b, 'job_report', 'Job report '.$report->number.' (version '.$version.') made: '.$reason.($data['missing'] ? ' · still missing: '.implode(', ', $data['missing']) : ''));

            return $report;
        });
    }

    public static function current(Booking $b): ?JobReport
    {
        return JobReport::where('booking_id', $b->id)->where('is_current', true)->latest('id')->first();
    }
}
