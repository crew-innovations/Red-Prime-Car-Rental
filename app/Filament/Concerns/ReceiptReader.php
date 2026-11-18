<?php

namespace App\Filament\Concerns;

use App\Support\Ocr\DocumentReader;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * 18 Nov (meeting item 7): "Read the receipt" next to an expense's receipt upload. Fills the
 * amount, date, vendor, reference and description that are still empty; staff check them.
 */
class ReceiptReader
{
    public static function button(string $uploadField = 'receipt', string $referenceField = 'reference'): Forms\Components\Actions
    {
        return Forms\Components\Actions::make([
            Forms\Components\Actions\Action::make('readReceipt')
                ->label('Read the receipt (OCR)')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->action(function (Get $get, Set $set) use ($uploadField, $referenceField) {
                    $files = [];
                    foreach ((array) ($get($uploadField) ?? []) as $file) {
                        $path = match (true) {
                            is_object($file) && method_exists($file, 'getRealPath') => $file->getRealPath(),
                            is_string($file) && $file !== '' && Storage::disk('private')->exists($file) => Storage::disk('private')->path($file),
                            default => null,
                        };
                        if ($path) {
                            $files[] = ['path' => $path, 'mime' => is_object($file) && method_exists($file, 'getMimeType') ? $file->getMimeType() : null];
                        }
                    }
                    try {
                        $r = DocumentReader::readReceipt($files);
                    } catch (\Throwable $e) {
                        Notification::make()->warning()->title('Could not read the receipt')->body($e->getMessage())->send();

                        return;
                    }
                    $filled = [];
                    $put = function (string $field, $value, string $label) use ($get, $set, &$filled) {
                        if ($value !== null && $value !== '' && blank($get($field))) {
                            $set($field, $value);
                            $filled[] = $label;
                        }
                    };
                    $put('amount', $r['amount'] ?? null, 'amount');
                    $put('date', $r['date'] ?? null, 'date');
                    $put('vendor', $r['vendor'] ?? null, 'paid to');
                    $put($referenceField, $r['invoice_number'] ?? null, 'reference');
                    $put('description', $r['description'] ?? null, 'description');
                    Notification::make()->success()->title($filled ? 'Filled in: '.implode(', ', $filled) : 'Nothing new to fill in')
                        ->body(collect([isset($r['vat_amount']) ? 'VAT on the receipt: AED '.number_format((float) $r['vat_amount'], 2) : null, isset($r['trn']) ? 'Vendor TRN '.$r['trn'] : null])->filter()->implode(' · ').' Check the fields before saving.')
                        ->send();
                }),
        ]);
    }
}
