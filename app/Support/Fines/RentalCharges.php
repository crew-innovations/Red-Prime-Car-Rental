<?php

namespace App\Support\Fines;

use App\Enums\FineStatus;
use App\Models\Booking;
use App\Models\TollTrip;
use App\Models\TrafficFine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 19 Nov: traffic fines and tolls seen per rental, per car and per customer (like AppicFleet's
 * "Fines" and "Salik" on a contract, and its fines report). Reads only; charging, paying the
 * authority and disputes stay in Fines / TrafficFineResource / TollTripResource.
 */
class RentalCharges
{
    /** Fine statuses where the customer owes or paid it (passed on). */
    public const PASSED_ON = [FineStatus::Charged, FineStatus::Collected];

    /** Fine statuses still waiting for someone to act. */
    public const OPEN = [FineStatus::New, FineStatus::Matched, FineStatus::Disputed];

    /** HTML list for the contract page. */
    public static function html(Booking $b): string
    {
        $fines = TrafficFine::query()->where('booking_id', $b->id)->with('car')->orderBy('offence_at')->get();
        $tolls = TollTrip::query()->where('booking_id', $b->id)->orderBy('passed_at')->get();
        if ($fines->isEmpty() && $tolls->isEmpty()) {
            return '<span style="opacity:.7">None yet. Fines and Salik / Darb passes are matched to this rental by car and time when they are entered or imported.</span>';
        }

        $out = '';
        if ($fines->isNotEmpty()) {
            $out .= '<div style="font-weight:600;margin-bottom:.25rem">Traffic fines · AED '.number_format((float) $fines->sum('amount') + (float) $fines->sum('admin_fee'), 2).'</div>';
            foreach ($fines as $f) {
                $url = \App\Filament\Resources\TrafficFineResource::getUrl('edit', ['record' => $f]);
                $out .= '<div><a style="text-decoration:underline" href="'.e($url).'">'.e($f->ticket_no).'</a> · '.e($f->offence_at?->format('d M H:i'))
                    .' · '.e($f->car?->name ?? $f->plate).' · AED '.number_format((float) $f->amount, 2)
                    .((float) $f->admin_fee > 0 ? ' + fee '.number_format((float) $f->admin_fee, 2) : '')
                    .' <span style="opacity:.7">· '.e($f->status?->getLabel()).($f->paid_at ? ' · paid to authority' : '').'</span></div>';
            }
        }
        if ($tolls->isNotEmpty()) {
            $out .= '<div style="font-weight:600;margin:.5rem 0 .25rem">Tolls and parking · '.$tolls->count().' · AED '.number_format((float) $tolls->sum('amount'), 2).'</div>';
            foreach ($tolls->groupBy('status') as $status => $list) {
                $out .= '<div>'.e(TollTrip::STATUSES[$status] ?? $status).': '.$list->count().' · AED '.number_format((float) $list->sum('amount'), 2).'</div>';
            }
        }

        return $out;
    }

    /** Totals for one list of fines and one list of tolls. */
    protected static function totals(Collection $fines, Collection $tolls): array
    {
        $amount = fn (Collection $c) => round((float) $c->sum(fn ($f) => (float) $f->amount + (float) $f->admin_fee), 2);

        return [
            'fines' => $fines->count(),
            'fines_amount' => $amount($fines),
            'fines_passed_on' => $amount($fines->filter(fn ($f) => in_array($f->status, self::PASSED_ON, true))),
            'fines_collected' => $amount($fines->filter(fn ($f) => $f->status === FineStatus::Collected)),
            'fines_open' => $amount($fines->filter(fn ($f) => in_array($f->status, self::OPEN, true))),
            'fines_company' => $amount($fines->filter(fn ($f) => in_array($f->status, [FineStatus::Waived, FineStatus::CompanyCost], true))),
            'fines_unpaid_authority' => round((float) $fines->whereNull('paid_at')->sum('amount'), 2),
            'tolls' => $tolls->count(),
            'tolls_amount' => round((float) $tolls->sum('amount'), 2),
            'tolls_charged' => round((float) $tolls->where('status', 'charged')->sum('amount'), 2),
            'tolls_open' => round((float) $tolls->whereIn('status', ['new', 'matched'])->sum('amount'), 2),
        ];
    }

    /**
     * Per car or per customer for a period (by offence / pass time).
     *
     * @return list<array{name: string, url: ?string, ...}>
     */
    public static function report(string $by, Carbon $from, Carbon $until): array
    {
        $fines = TrafficFine::query()->whereBetween('offence_at', [$from, $until])->with(['car', 'customer'])->get();
        $tolls = TollTrip::query()->whereBetween('passed_at', [$from, $until])->with(['car', 'booking.customer'])->get();

        if ($by === 'customer') {
            $fineKey = fn (TrafficFine $f) => $f->customer_id ?: 0;
            $tollKey = fn (TollTrip $t) => $t->booking?->customer_id ?: 0;
            $name = fn ($id) => $id ? (\App\Models\Customer::withTrashed()->find($id)?->name ?? '#'.$id) : 'No customer (company / not matched)';
            $url = fn ($id) => $id ? \App\Filament\Resources\CustomerResource::getUrl('edit', ['record' => $id]) : null;
        } else {
            $fineKey = fn (TrafficFine $f) => $f->car_id ?: 0;
            $tollKey = fn (TollTrip $t) => $t->car_id ?: 0;
            $name = fn ($id) => $id ? (($c = \App\Models\Car::withTrashed()->find($id)) ? $c->name.($c->registration_number ? ' · '.$c->registration_number : '') : '#'.$id) : 'Car not found (plate not matched)';
            $url = fn ($id) => $id ? \App\Filament\Resources\CarResource::getUrl('edit', ['record' => $id]) : null;
        }

        $fineGroups = $fines->groupBy($fineKey);
        $tollGroups = $tolls->groupBy($tollKey);
        $rows = [];
        foreach ($fineGroups->keys()->merge($tollGroups->keys())->unique() as $id) {
            $rows[] = ['name' => $name($id), 'url' => $url($id)] + self::totals($fineGroups->get($id, collect()), $tollGroups->get($id, collect()));
        }

        return collect($rows)->sortByDesc(fn ($r) => $r['fines_amount'] + $r['tolls_amount'])->values()->all();
    }
}
