<?php

namespace App\Support\Pricing;

use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;

/**
 * The price of a quotation or booking as customer-facing lines (4 Nov, 10 and 12):
 * list price → discount % → discount amount → net, per line, then the discount
 * on the total. Used by the quotation PDF, the quotation page and the booking PDFs.
 */
class PriceLines
{
    /**
     * @return array{lines: array<int, array{label: string, detail: ?string, list: float, percent: ?float, off: float, net: float, waived: bool}>,
     *               gross: float, list_gross: float, overall: float, overall_percent: ?float, net: float, has_discount: bool}
     */
    public static function for(Model $r): array
    {
        $service = $r->service_type;
        $label = match ($service) {
            ServiceType::Car => 'Rental',
            ServiceType::Yacht => 'Charter',
            ServiceType::Transfer => 'Transfer',
            default => 'Price',
        };
        $detail = ($r->price_breakdown['base_label'] ?? null)
            ?: ($r->quantity && $r->rate !== null ? PriceEngine::qtyText((float) $r->quantity).' × AED '.number_format((float) $r->rate, 2).' / '.PriceEngine::unitLabel($r->rate_type) : null);

        $base = (float) $r->base_amount;
        $listBase = isset($r->price_breakdown['base_amount']) ? (float) $r->price_breakdown['base_amount'] : $base;
        $lines = [self::row($label, $detail, max($listBase, 0), $base, false)];

        foreach ((array) $r->extras as $l) {
            $amount = (float) ($l['amount'] ?? 0);
            $list = isset($l['standard']) && $l['standard'] !== null && $l['standard'] !== '' ? (float) $l['standard'] : $amount;
            $qty = isset($l['qty']) ? (float) $l['qty'] : 1;
            $lines[] = self::row((string) ($l['label'] ?? 'Extra'), $qty && $qty != 1 ? PriceEngine::qtyText($qty).' × AED '.number_format((float) ($l['unit_price'] ?? 0), 2) : null,
                $list, $amount, ! empty($l['waived']));
        }
        if (! $r->extras && (float) $r->extras_amount > 0) {
            $lines[] = self::row('Add-ons & delivery', null, (float) $r->extras_amount, (float) $r->extras_amount, false);
        }

        $gross = round(collect($lines)->sum('net'), 2);
        $listGross = round(collect($lines)->sum('list'), 2);
        $overall = round((float) $r->discount, 2);

        return [
            'lines' => $lines,
            'gross' => $gross,
            'list_gross' => $listGross,
            'overall' => $overall,
            'overall_percent' => $overall > 0 && $gross > 0 ? round($overall / $gross * 100, 2) : null,
            'net' => round($gross - $overall, 2),
            'has_discount' => $overall > 0 || collect($lines)->contains(fn ($l) => $l['off'] > 0.005),
        ];
    }

    protected static function row(string $label, ?string $detail, float $list, float $net, bool $waived): array
    {
        $list = max($list, $net); // a price above the list is shown as it is, not as a negative discount
        $off = round($list - $net, 2);

        return [
            'label' => $label,
            'detail' => $detail,
            'list' => round($list, 2),
            'percent' => $off > 0.005 && $list > 0 ? Discounts::percentOff($list, $net) : null,
            'off' => $off > 0.005 ? $off : 0.0,
            'net' => round($net, 2),
            'waived' => $waived,
        ];
    }
}
