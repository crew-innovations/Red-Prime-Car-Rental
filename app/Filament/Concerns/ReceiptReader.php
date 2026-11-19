<?php

namespace App\Filament\Concerns;

use App\Support\Ocr\DocumentReader;
use App\Support\Ocr\LocalOcr;
use App\Support\Ocr\TextParser;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * 18 Nov (meeting item 7): "Read the receipt" next to an expense's receipt upload. Fills the
 * amount, date, vendor, reference and description that are still empty; staff check them.
 * 19 Nov: without an AI reader set up, the receipt is read on the staff computer (LocalOcr).
 */
class ReceiptReader
{
    public static function button(string $uploadField = 'receipt', string $referenceField = 'reference'): Forms\Components\Group
    {
        return Forms\Components\Group::make([
            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('readReceipt')
                    ->label('Read the receipt (OCR)')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->action(function (Get $get, Set $set, $livewire, $component) use ($uploadField, $referenceField) {
                        $files = self::files($get, $uploadField);
                        if (! DocumentReader::ready()) {
                            LocalOcr::start($livewire, $component->getContainer()->getStatePath().'.receipt_ocr_text', $files);

                            return;
                        }
                        try {
                            $r = DocumentReader::readReceipt($files);
                        } catch (\Throwable $e) {
                            Notification::make()->warning()->title('Could not read the receipt')->body($e->getMessage())->send();

                            return;
                        }
                        self::apply($get, $set, $r, $referenceField);
                    }),
            ]),
            Forms\Components\Hidden::make('receipt_ocr_text')->dehydrated(false)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($referenceField) {
                    if (blank($state)) {
                        return;
                    }
                    $texts = collect(LocalOcr::texts($state))->pluck('text')->all();
                    $set('receipt_ocr_text', null);
                    self::apply($get, $set, TextParser::receipt($texts), $referenceField, $texts === [] ? 'No text could be read. Try a sharper photo.' : null);
                }),
        ]);
    }

    protected static function files(Get $get, string $uploadField): array
    {
        $files = [];
        foreach ((array) ($get($uploadField) ?? []) as $file) {
            $path = match (true) {
                is_object($file) && method_exists($file, 'getRealPath') => $file->getRealPath(),
                is_string($file) && $file !== '' && Storage::disk('private')->exists($file) => Storage::disk('private')->path($file),
                default => null,
            };
            if ($path) {
                $files[] = ['path' => $path, 'mime' => is_object($file) && method_exists($file, 'getMimeType') ? $file->getMimeType() : null, 'key' => 'receipt'];
            }
        }

        return $files;
    }

    public static function apply(Get $get, Set $set, array $r, string $referenceField, ?string $problem = null): void
    {
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
        Notification::make()->{$filled ? 'success' : 'warning'}()->title($filled ? 'Filled in: '.implode(', ', $filled) : 'Nothing new to fill in')
            ->body(collect([$problem, isset($r['vat_amount']) ? 'VAT on the receipt: AED '.number_format((float) $r['vat_amount'], 2) : null, isset($r['trn']) ? 'Vendor TRN '.$r['trn'] : null])->filter()->implode(' · ').' Check the fields before saving.')
            ->send();
    }
}
