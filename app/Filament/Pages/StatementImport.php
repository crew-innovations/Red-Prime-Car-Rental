<?php

namespace App\Filament\Pages;

use App\Models\ImportBatch;
use App\Models\TollAccount;
use App\Models\TollTrip;
use App\Models\TrafficFine;
use App\Support\Access;
use App\Support\Imports\StatementImporter;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Fines & tolls › Import statement (4 Nov, C.3): upload the authority's or Salik / Darb Excel / CSV,
 * map the columns once, see the counts, then import. Each row is matched to the booking that had
 * the car; duplicates are skipped.
 */
class StatementImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.statement-import';

    protected static ?string $slug = 'statement-import';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Fines & tolls';

    protected static ?string $navigationLabel = 'Import statement';

    protected static ?string $title = 'Import statement';

    protected static ?int $navigationSort = 4;

    #[Url]
    public ?string $kind = 'fines';

    public ?array $data = [];

    /** @var list<string> */
    public array $headings = [];

    public ?array $preview = null;

    /** The uploaded file once saved on the private disk. */
    public ?string $storedPath = null;

    public ?string $storedName = null;

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-fines');
    }

    public function mount(): void
    {
        $this->kind = in_array($this->kind, ['fines', 'tolls'], true) ? $this->kind : 'fines';
        $this->form->fill(['kind' => $this->kind, 'source' => $this->kind === 'fines' ? 'Dubai Police' : 'salik']);
    }

    public function form(Form $form): Form
    {
        $fields = fn (Get $get) => StatementImporter::FIELDS[$get('kind') ?: 'fines'];

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('1. The file')->columns(3)->schema([
                Forms\Components\ToggleButtons::make('kind')->label('What')->inline()->options(['fines' => 'Traffic fines', 'tolls' => 'Salik / Darb / parking'])->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        $set('source', $state === 'fines' ? 'Dubai Police' : 'salik');
                        $this->headings = [];
                        $this->preview = null;
                    }),
                Forms\Components\Select::make('source')->label(fn (Get $get) => $get('kind') === 'tolls' ? 'Type' : 'Authority')->required()->native(false)->live()
                    ->options(fn (Get $get) => $get('kind') === 'tolls' ? TollTrip::TYPES : array_combine(TrafficFine::AUTHORITIES, TrafficFine::AUTHORITIES)),
                Forms\Components\Select::make('account_id')->label('Take the trips off this toll account')->placeholder('None')->native(false)
                    ->options(fn (Get $get) => TollAccount::query()->where('is_active', true)->where('toll_type', $get('source'))->pluck('name', 'id'))
                    ->visible(fn (Get $get) => $get('kind') === 'tolls'),
                Forms\Components\FileUpload::make('file')->label('Excel or CSV file')->required()->disk('private')->directory('imports')->visibility('private')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])
                    ->maxSize(10240)->storeFileNamesIn('file_name')->columnSpanFull()
                    ->afterStateUpdated(function () {
                        $this->headings = [];
                        $this->preview = null;
                        $this->storedPath = null;
                    }),
            ]),
            Forms\Components\Section::make('2. Which column is which')
                ->description('Saved for next time for this authority / type.')
                ->visible(fn () => $this->headings !== [])
                ->columns(3)
                ->schema(fn (Get $get) => collect($fields($get))->map(fn ($meta, $field) => Forms\Components\Select::make('map.'.$field)
                    ->label($meta[0])->required($meta[1])->native(false)->options(array_combine($this->headings, $this->headings) ?: [])->live())->values()->all()),
        ]);
    }

    protected function filePath(): ?string
    {
        return $this->storedPath;
    }

    protected function fileName(): string
    {
        return (string) ($this->storedName ?: basename((string) $this->storedPath));
    }

    /** Reads the headings and fills the saved / guessed mapping. */
    public function readFile(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        $f = $state['file'] ?? null;
        $n = $state['file_name'] ?? null;
        $this->storedPath = is_array($f) ? (string) collect($f)->first() : $f;
        $this->storedName = is_array($n) ? (string) collect($n)->first() : ($n ?: basename((string) $this->storedPath));
        if (! $this->storedPath || ! \Illuminate\Support\Facades\Storage::disk('private')->exists($this->storedPath)) {
            Notification::make()->title('Choose the file first')->warning()->send();

            return;
        }
        try {
            [$headings, $rows] = StatementImporter::read($this->filePath(), $this->fileName());
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('The file could not be read')->body('Save it as .xlsx or .csv and try again.')->danger()->send();

            return;
        }
        if (! $headings || ! $rows) {
            Notification::make()->title('No rows found')->body('The first row must hold the column names.')->warning()->send();

            return;
        }
        $this->headings = array_values(array_filter($headings, fn ($h) => $h !== ''));
        $this->data['map'] = StatementImporter::mapping($this->data['kind'], $this->data['source'], $this->headings);
        $this->check();
    }

    /** Counts with the current mapping. */
    public function check(): void
    {
        if (! $this->headings || ! $this->storedPath) {
            return;
        }
        [$headings, $rows] = StatementImporter::read($this->filePath(), $this->fileName());
        $map = array_filter((array) ($this->data['map'] ?? []));
        $missing = collect(StatementImporter::FIELDS[$this->data['kind']])->filter(fn ($m, $f) => $m[1] && empty($map[$f]))->map(fn ($m) => $m[0])->values()->all();
        $res = StatementImporter::records($this->data['kind'], $headings, $rows, $map);
        $counts = StatementImporter::preview($this->data['kind'], $this->data['source'], $res['records']);
        $this->preview = ['rows' => count($rows), 'new' => $counts['new'], 'duplicate' => $counts['duplicate'], 'errors' => count($res['errors']),
            'error_lines' => array_slice($res['errors'], 0, 8), 'missing' => $missing,
            'sample' => array_map(fn ($r) => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('d M Y H:i') : $v, $r), array_slice($res['records'], 0, 5))];
    }

    public function import(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->check();
        if (! $this->preview || $this->preview['missing'] || ! $this->preview['new']) {
            Notification::make()->title($this->preview['missing'] ?? false ? 'Pick the columns for: '.implode(', ', $this->preview['missing']) : 'Nothing new to import')->warning()->send();

            return;
        }
        $account = filled($this->data['account_id'] ?? null) ? TollAccount::find($this->data['account_id']) : null;
        $batch = StatementImporter::import($this->data['kind'], $this->data['source'], $this->filePath(), $this->fileName(), array_filter((array) $this->data['map']), $account);
        Notification::make()->success()->title($batch->rows_new.' imported, '.$batch->rows_matched.' matched to a customer')
            ->body(($batch->rows_new - $batch->rows_matched).' to review · '.$batch->rows_duplicate.' already there · '.$batch->rows_error.' rows not read')->persistent()->send();
        $this->redirect($this->data['kind'] === 'fines'
            ? \App\Filament\Resources\TrafficFineResource::getUrl('index', ['activeTab' => $batch->rows_new > $batch->rows_matched ? 'review' : 'matched'])
            : \App\Filament\Resources\TollTripResource::getUrl('index', ['activeTab' => $batch->rows_new > $batch->rows_matched ? 'review' : 'charged']));
    }

    public function batches()
    {
        return ImportBatch::query()->with('creator')->latest('id')->limit(10)->get();
    }
}
