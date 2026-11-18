<?php

namespace App\Support\Imports;

use App\Enums\FineStatus;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\TollAccount;
use App\Models\TollTrip;
use App\Models\TrafficFine;
use App\Support\Fines\Fines;
use App\Support\Tolls\Tolls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Fine and toll statements from Excel / CSV (4 Nov, C.3). Pick the authority or toll type, map the
 * columns once (saved per source), preview the counts, confirm. Duplicates are stopped by the
 * unique keys in the database, so two people importing the same file at once is safe.
 */
class StatementImporter
{
    public const FIELDS = [
        'fines' => [
            'ticket_no' => ['Ticket / fine number', true],
            'offence_at' => ['Date (and time)', true],
            'time' => ['Time (if in its own column)', false],
            'plate' => ['Plate number', false],
            'amount' => ['Amount (AED)', true],
            'offence' => ['Offence', false],
            'location' => ['Location', false],
            'black_points' => ['Black points', false],
        ],
        'tolls' => [
            'passed_at' => ['Date (and time)', true],
            'time' => ['Time (if in its own column)', false],
            'tag_no' => ['Tag number', false],
            'plate' => ['Plate number', false],
            'gate' => ['Gate / zone', false],
            'amount' => ['Amount (AED)', true],
        ],
    ];

    public const MAX_ROWS = 5000;

    /** @return array{0: list<string>, 1: list<list<mixed>>} headings and rows */
    public static function read(string $path, ?string $name = null): array
    {
        $ext = strtolower(pathinfo($name ?: $path, PATHINFO_EXTENSION));
        $full = Storage::disk('private')->path($path);
        $reader = in_array($ext, ['xlsx', 'xlsm'], true) ? new XlsxReader : new CsvReader;
        $reader->open($full);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($c) => $c instanceof \DateTimeInterface ? Carbon::instance($c)->toDateTimeString() : (is_string($c) ? trim($c) : $c), $row->toArray());
                if (array_filter($cells, fn ($c) => $c !== null && $c !== '') === []) {
                    continue;
                }
                $rows[] = $cells;
                if (count($rows) > self::MAX_ROWS) {
                    break 2;
                }
            }
            break; // first sheet only
        }
        $reader->close();
        $headings = array_map(fn ($h) => trim((string) $h), array_shift($rows) ?? []);

        return [$headings, $rows];
    }

    /** Saved mapping for this source, or a guess from the headings. */
    public static function mapping(string $kind, string $source, array $headings): array
    {
        $saved = ImportMapping::query()->where('kind', $kind)->where('source', $source)->value('columns');
        if ($saved) {
            return array_filter((array) $saved, fn ($h) => in_array($h, $headings, true));
        }
        $guess = [];
        $words = [
            'ticket_no' => ['ticket', 'fine no', 'fine number', 'violation no', 'number'],
            'offence_at' => ['date', 'violation date', 'offence date', 'time'], 'passed_at' => ['date', 'trip date', 'transaction date', 'time'],
            'plate' => ['plate'], 'tag_no' => ['tag'], 'amount' => ['amount', 'fine amount', 'value', 'aed'],
            'offence' => ['offence', 'violation', 'description'], 'location' => ['location', 'place', 'street'],
            'gate' => ['gate', 'toll gate', 'zone'], 'black_points' => ['black points', 'points'],
        ];
        foreach (array_keys(self::FIELDS[$kind]) as $field) {
            foreach ($words[$field] ?? [] as $w) {
                $hit = collect($headings)->first(fn ($h) => str_contains(mb_strtolower($h), $w) && ! in_array($h, $guess, true));
                if ($hit) {
                    $guess[$field] = $hit;
                    break;
                }
            }
        }

        return $guess;
    }

    public static function date(mixed $v, mixed $time = null): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) { // Excel serial date
                $d = Carbon::create(1899, 12, 30)->addSeconds((int) round((float) $v * 86400));
            } else {
                $s = trim((string) $v);
                $d = null;
                foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y h:i A', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd M Y H:i', 'd M Y'] as $f) {
                    $try = \DateTime::createFromFormat('!'.$f, $s);
                    if ($try && \DateTime::getLastErrors() === false) {
                        $d = Carbon::instance($try);
                        break;
                    }
                }
                $d ??= Carbon::parse($s);
            }
            if (filled($time)) {
                $t = is_numeric($time) && (float) $time < 1 ? Carbon::today()->addSeconds((int) round((float) $time * 86400)) : Carbon::parse((string) $time);
                $d->setTime($t->hour, $t->minute, $t->second);
            }

            return $d->year >= 2000 && $d->year <= (int) date('Y') + 1 ? $d : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function money(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (float) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $v));

        return $n > 0 ? round($n, 2) : null;
    }

    /**
     * Rows → clean records, with the row errors.
     *
     * @return array{records: list<array>, errors: list<string>}
     */
    public static function records(string $kind, array $headings, array $rows, array $map): array
    {
        $col = fn (array $row, string $field) => isset($map[$field]) && ($i = array_search($map[$field], $headings, true)) !== false ? ($row[$i] ?? null) : null;
        $out = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $line = $n + 2;
            $amount = self::money($col($row, 'amount'));
            if ($kind === 'fines') {
                $at = self::date($col($row, 'offence_at'), $col($row, 'time'));
                $ticket = trim((string) $col($row, 'ticket_no'));
                if ($ticket === '' || ! $at || ! $amount) {
                    $errors[] = 'Row '.$line.': '.($ticket === '' ? 'no ticket number' : (! $at ? 'date not understood' : 'no amount'));

                    continue;
                }
                $out[] = ['ticket_no' => mb_substr($ticket, 0, 80), 'offence_at' => $at, 'amount' => $amount, 'plate' => self::text($col($row, 'plate'), 40),
                    'offence' => self::text($col($row, 'offence'), 255), 'location' => self::text($col($row, 'location'), 255),
                    'black_points' => is_numeric($col($row, 'black_points')) ? (int) $col($row, 'black_points') : null];
            } else {
                $at = self::date($col($row, 'passed_at'), $col($row, 'time'));
                $tag = self::text($col($row, 'tag_no'), 40);
                $plate = self::text($col($row, 'plate'), 40);
                if (! $at || ! $amount || (! $tag && ! $plate)) {
                    $errors[] = 'Row '.$line.': '.(! $at ? 'date not understood' : (! $amount ? 'no amount' : 'no tag or plate'));

                    continue;
                }
                $out[] = ['passed_at' => $at, 'amount' => $amount, 'tag_no' => $tag, 'plate' => $plate, 'gate' => self::text($col($row, 'gate'), 120)];
            }
        }

        return ['records' => $out, 'errors' => $errors];
    }

    protected static function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Counts for the preview: new and already imported. */
    public static function preview(string $kind, string $source, array $records): array
    {
        $dup = 0;
        foreach ($records as $r) {
            $exists = $kind === 'fines'
                ? TrafficFine::withTrashed()->where('authority', $source)->where('ticket_no', $r['ticket_no'])->exists()
                : TollTrip::query()->where('dedupe_key', TollTrip::dedupeKey($source, $r['tag_no'], $r['plate'], $r['passed_at'], $r['gate']))->exists();
            $dup += $exists ? 1 : 0;
        }

        return ['new' => count($records) - $dup, 'duplicate' => $dup];
    }

    /** Saves the new rows, matches them, and (tolls) charges the bookings. */
    public static function import(string $kind, string $source, string $path, string $fileName, array $map, ?TollAccount $account = null): ImportBatch
    {
        [$headings, $rows] = self::read($path, $fileName);
        ['records' => $records, 'errors' => $errors] = self::records($kind, $headings, $rows, $map);
        ImportMapping::updateOrCreate(['kind' => $kind, 'source' => $source], ['columns' => $map]);

        $batch = ImportBatch::create(['kind' => $kind, 'source' => $source, 'file_path' => $path, 'file_name' => mb_substr($fileName, 0, 255),
            'rows_total' => count($rows), 'rows_error' => count($errors), 'errors' => array_slice($errors, 0, 200) ?: null, 'created_by' => auth()->id()]);
        $new = $dup = $matched = 0;
        $bookings = [];
        $sum = 0.0;
        foreach ($records as $r) {
            try {
                if ($kind === 'fines') {
                    $fine = TrafficFine::create($r + ['authority' => $source, 'status' => FineStatus::New, 'import_batch_id' => $batch->id, 'created_by' => auth()->id()]);
                    $fine = Fines::match($fine);
                    $matched += $fine->status === FineStatus::Matched ? 1 : 0;
                } else {
                    $trip = TollTrip::create($r + ['toll_type' => $source, 'status' => 'new', 'import_batch_id' => $batch->id, 'created_by' => auth()->id(),
                        'dedupe_key' => TollTrip::dedupeKey($source, $r['tag_no'], $r['plate'], $r['passed_at'], $r['gate'])]);
                    $trip = Tolls::match($trip);
                    if ($trip->status === 'matched') {
                        $matched++;
                        $bookings[$trip->booking_id] = true;
                    }
                    $sum += (float) $trip->amount;
                }
                $new++;
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'Duplicate') || ($e->errorInfo[1] ?? null) === 1062) {
                    $dup++;

                    continue;
                }
                throw $e;
            }
        }
        if ($kind === 'tolls' && Tolls::autoCharge()) {
            foreach (array_keys($bookings) as $id) {
                if ($b = \App\Models\Booking::find($id)) {
                    rescue(fn () => Tolls::chargeBooking($b));
                }
            }
        }
        if ($account && $sum > 0) {
            Tolls::usage($account, $sum, 'Statement '.$fileName.' ('.$new.' trips)');
        }
        $batch->update(['rows_new' => $new, 'rows_duplicate' => $dup, 'rows_matched' => $matched]);
        $review = $new - $matched;
        if ($review > 0) {
            \App\Support\Drivers\Notify::staff('manage-fines', $review.' '.($kind === 'fines' ? 'fine(s)' : 'toll trip(s)').' to review', 'From '.$fileName.': no customer booking found for them.',
                null, null, 'warning', event: 'fine_review');
        }

        return $batch;
    }
}
