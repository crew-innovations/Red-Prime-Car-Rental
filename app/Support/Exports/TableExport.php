<?php

namespace App\Support\Exports;

use App\Models\AuditLog;
use App\Support\Access;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Columns;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Excel / CSV export for any CRM list (brief 28 Oct-2, Phase 7). Exports exactly
 * what the list shows the person (tab, filters, search, sort, agent scope), with
 * the list's own column names, the record ID first, and nothing the list hides.
 * Text that a spreadsheet would run as a formula is made safe. Every export is
 * written to the history log.
 */
class TableExport
{
    /** Columns that are pictures or custom views carry no data worth exporting. */
    protected const SKIP = [Columns\ImageColumn::class, Columns\ViewColumn::class, Columns\ColorColumn::class, Columns\SpatieMediaLibraryImageColumn::class];

    public static function action(string $name = 'export'): Action
    {
        return Action::make($name)
            ->label('Export')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => Access::allows('export-data'))
            ->modalHeading('Export this list')
            ->modalDescription('Exports what this list shows you now: the tab, filters, search and sort you chose.')
            ->modalSubmitActionLabel('Download')
            ->modalWidth('md')
            ->form([
                Forms\Components\Radio::make('format')
                    ->options(['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)'])
                    ->default('xlsx')
                    ->required(),
            ])
            ->action(function (array $data, HasTable $livewire) {
                abort_unless(Access::allows('export-data'), 403);

                return self::download($livewire, $data['format'] === 'csv' ? 'csv' : 'xlsx');
            });
    }

    /** @return array{0: list<string>, 1: list<Columns\Column>} */
    public static function columns(HasTable $livewire): array
    {
        $columns = collect($livewire->getTable()->getColumns())
            ->reject(fn (Columns\Column $c) => $c->isHidden())
            ->reject(fn (Columns\Column $c) => in_array($c::class, self::SKIP, true) || collect(self::SKIP)->contains(fn ($s) => is_a($c, $s)))
            ->values();

        $headers = ['ID'];
        foreach ($columns as $c) {
            $label = trim(strip_tags((string) $c->getLabel())) ?: Str::headline($c->getName());
            $headers[] = $label;
            if (self::hasDescription($c)) {
                $headers[] = $label.' (details)'; // the grey line under the value: phone, end date…
            }
        }

        return [$headers, $columns->all()];
    }

    public static function rows(HasTable $livewire): \Generator
    {
        [, $columns] = self::columns($livewire);
        $query = $livewire->getFilteredSortedTableQuery();

        foreach ($query->lazy(500) as $record) {
            yield self::row($record, $columns);
        }
    }

    /** @param  list<Columns\Column>  $columns */
    public static function row(Model $record, array $columns): array
    {
        $out = [self::key($record)];
        foreach ($columns as $column) {
            $column->record($record);
            try {
                $state = $column->getState();
            } catch (\Throwable) {
                $state = null;
            }
            // Use the list's own wording for codes (e.g. "car_rental" → "Car rental"), never for numbers or dates.
            if (is_string($state) && $column instanceof Columns\TextColumn && ! is_numeric($state)
                && ($format = (fn () => $this->formatStateUsing)->call($column))) {
                try {
                    $formatted = $column->evaluate($format, ['state' => $state]);
                    $state = $formatted instanceof HasLabel ? $formatted->getLabel() : ($formatted instanceof \Illuminate\Contracts\Support\Htmlable ? $formatted->toHtml() : $formatted);
                } catch (\Throwable) {
                }
            }
            $out[] = self::cell($state, $column);
            if (self::hasDescription($column)) {
                try {
                    $d = $column->getDescriptionBelow() ?? $column->getDescriptionAbove();
                } catch (\Throwable) {
                    $d = null;
                }
                $out[] = self::cell($d instanceof \Illuminate\Contracts\Support\Htmlable ? $d->toHtml() : $d);
            }
        }

        return $out;
    }

    protected static function hasDescription(Columns\Column $column): bool
    {
        return $column instanceof Columns\TextColumn
            && ((fn () => $this->descriptionBelow ?? $this->descriptionAbove)->call($column)) !== null;
    }

    /** The reference people use, with the database ID when there is none. */
    protected static function key(Model $record): string|int
    {
        foreach (['reference', 'number'] as $attr) {
            if (filled($record->getAttribute($attr))) {
                return (string) $record->getAttribute($attr);
            }
        }

        return $record->getKey();
    }

    public static function cell(mixed $state, ?Columns\Column $column = null): string|int|float|null
    {
        if ($state instanceof \Illuminate\Support\Collection) {
            $state = $state->all();
        }
        if (is_array($state)) {
            return (collect($state)->map(fn ($v) => is_scalar($v) || $v instanceof BackedEnum || $v instanceof UnitEnum ? self::plain($v) : json_encode($v))->filter(fn ($v) => $v !== '' && $v !== null)->implode(', '));
        }

        if (is_bool($state)) {
            return $state ? 'Yes' : 'No';
        }
        if (is_int($state) || is_float($state)) {
            return $state;
        }
        if (is_string($state) && is_numeric($state) && ! preg_match('/^(\+|0\d)/', $state)) {
            return $state + 0; // money and counts stay numbers; phone-like values stay text
        }

        // Select columns show the option label, not the stored key.
        if ($column instanceof Columns\SelectColumn && is_scalar($state)) {
            $options = $column->getOptions();
            $state = $options[$state] ?? $state;
        }

        return self::plain($state);
    }

    protected static function plain(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            $v instanceof HasLabel => (string) $v->getLabel(),
            $v instanceof BackedEnum => (string) $v->value,
            $v instanceof UnitEnum => $v->name,
            $v instanceof CarbonInterface => $v->format($v->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i'),
            is_bool($v) => $v ? 'Yes' : 'No',
            is_scalar($v) => trim(html_entity_decode(strip_tags((string) $v))),
            default => null,
        };
    }

    /**
     * CSV only: a cell starting with = + - @ (or a tab / return) would run as a
     * formula when opened, so it is kept as text. (Excel files store text cells
     * as text, so they need no change.)
     */
    public static function safe(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }

    public static function download(HasTable $livewire, string $format): StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        [$headers] = self::columns($livewire);
        $title = method_exists($livewire, 'getTitle') ? strip_tags((string) $livewire->getTitle()) : 'Export';
        $file = Str::slug($title).'-'.now()->format('Y-m-d-His').'.'.$format;
        $count = 0;
        $filters = collect((array) ($livewire->tableFilters ?? []))->filter(fn ($f) => collect((array) $f)->filter(fn ($v) => filled($v) && $v !== false)->isNotEmpty())->keys()->implode(', ');
        $context = collect([
            'tab' => property_exists($livewire, 'activeTab') ? $livewire->activeTab : null,
            'search' => $livewire->getTableSearch() ?: null,
            'filters' => $filters ?: null,
        ])->filter()->map(fn ($v, $k) => $k.': '.$v)->implode(' · ');
        $model = $livewire->getTable()->getModel();
        $rows = self::rows($livewire);

        $log = function (int $count) use ($model, $title, $format, $context) {
            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'export',
                'auditable_type' => (new $model)->getMorphClass(),
                'auditable_id' => 0,
                'new_values' => ['rows' => $count, 'format' => $format, 'list' => $title],
                'note' => mb_substr('Exported '.$count.' row(s) of '.$title.' as '.strtoupper($format).($context ? ' ('.$context.')' : ''), 0, 255),
            ]);
        };

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers, $rows, $log, &$count) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // so Excel opens Arabic / accented names correctly
                fputcsv($out, $headers);
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($v) => is_string($v) ? self::safe($v) : $v, $row));
                    $count++;
                }
                fclose($out);
                $log($count);
            }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Excel files are zipped, so they are written to a temporary file first.
        $tmp = tempnam(sys_get_temp_dir(), 'rpx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($tmp);
        $writer->getCurrentSheet()->setName(mb_substr(trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $title)) ?: 'Export', 0, 31));
        $writer->addRow(Row::fromValues($headers, (new Style)->setFontBold()));
        foreach ($rows as $row) {
            // Text is always written as a text cell, so "=…" can never become a formula.
            $writer->addRow(new Row(array_map(fn ($v) => is_string($v) && $v !== '' ? new StringCell($v, null) : Cell::fromValue($v), $row)));
            $count++;
        }
        $writer->close();
        $log($count);

        return response()->download($tmp, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** For tests: the rows as arrays, header first. */
    public static function toArray(HasTable $livewire): array
    {
        [$headers] = self::columns($livewire);

        return [$headers, ...iterator_to_array(self::rows($livewire), false)];
    }
}
