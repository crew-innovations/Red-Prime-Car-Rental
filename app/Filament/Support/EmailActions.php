<?php

namespace App\Filament\Support;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\ManualEmail;
use App\Support\Access;
use App\Support\Mail\Templates;
use App\Support\Notifications\Notifier;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * "Send email" on a lead, customer, booking, quotation, driver or staff profile (4 Nov, 14.3):
 * the recipient filled in, a template or free text, the record's PDFs attached, sent from the
 * routed mailbox, logged in the email log and in the record's history.
 */
class EmailActions
{
    public static function send(string $class)
    {
        return $class::make('sendEmail')
            ->label('Send email')
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn (Model $record): bool => filled(self::recipient($record)[0]) && self::allowed($record))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Send')
            ->fillForm(fn (Model $record) => ['to' => self::recipient($record)[0], 'lang' => self::lang($record)])
            ->form(fn (Model $record) => [
                Forms\Components\TextInput::make('to')->label('To')->email()->required()->maxLength(190),
                Forms\Components\Select::make('template')->label('Template')->native(false)->placeholder('Write your own')
                    ->options(fn () => EmailTemplate::query()->where('is_active', true)->whereIn('audience', [self::audience($record), 'any'])->orderBy('name')->pluck('name', 'id')->all())
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) use ($record) {
                        if ($state && ($t = EmailTemplate::find($state))) {
                            $r = Templates::render($t, $record, (string) $get('lang'));
                            $set('subject', $r['subject']);
                            $set('body', $r['body']);
                        }
                    }),
                Forms\Components\ToggleButtons::make('lang')->label('Language')->options(['en' => 'English', 'ar' => 'Arabic'])->inline()->default('en'),
                Forms\Components\TextInput::make('subject')->required()->maxLength(190),
                Forms\Components\Textarea::make('body')->label('Text')->required()->rows(8)->helperText('Leave an empty line between paragraphs.'),
                Forms\Components\CheckboxList::make('pdfs')->label('Attach')->options(self::pdfOptions($record))->visible(self::pdfOptions($record) !== [])->columns(2),
            ])
            ->action(function (Model $record, array $data) {
                abort_unless(self::allowed($record), 403);
                $pdfs = collect((array) ($data['pdfs'] ?? []))->filter(fn ($k) => array_key_exists($k, self::pdfOptions($record)))
                    ->map(fn ($k) => explode(':', $k))->map(fn ($p) => [$p[0], (int) $p[1]])->values()->all();
                $mail = new ManualEmail($data['subject'], $data['body'], self::type($record), 'Email: '.$data['subject'], $pdfs, null, ($data['lang'] ?? 'en') === 'ar');
                $user = $record instanceof User ? $record : User::where('email', $data['to'])->first();
                $count = $user && mb_strtolower($user->email) === mb_strtolower($data['to'])
                    ? Notifier::send($user, $mail, $record)
                    : Notifier::mail($data['to'], $mail, $record);
                AuditLog::note($record, 'email', 'Email sent to '.$data['to'].': '.$data['subject'].($pdfs ? ' (with '.count($pdfs).' PDF)' : ''));
                Notification::make()->title($count ? 'Email on its way to '.$data['to'] : 'The email could not be sent')->{$count ? 'success' : 'danger'}()->send();
            });
    }

    /** @return array{0: ?string, 1: ?string} */
    public static function recipient(Model $r): array
    {
        return match (true) {
            $r instanceof Lead => [$r->email, $r->name],
            $r instanceof Customer => [$r->email, $r->name],
            $r instanceof Booking => [$r->customer?->email, $r->customer?->name],
            $r instanceof Quotation => [$r->customerEmail(), $r->customerName()],
            $r instanceof User => [$r->email, $r->name],
            default => [null, null],
        };
    }

    protected static function audience(Model $r): string
    {
        return $r instanceof User ? ($r->isDriverOnly() ? 'driver' : 'staff') : 'customer';
    }

    protected static function type(Model $r): string
    {
        return match (self::audience($r)) {
            'driver' => 'driver',
            'staff' => 'staff',
            default => 'customer_sales',
        };
    }

    protected static function lang(Model $r): string
    {
        $language = match (true) {
            $r instanceof Customer => $r->language,
            $r instanceof Booking, $r instanceof Quotation => $r->customer?->language,
            default => null,
        };

        return in_array(strtolower((string) $language), ['ar', 'arabic'], true) ? 'ar' : 'en';
    }

    protected static function allowed(Model $r): bool
    {
        if (Access::isDriverOnly()) {
            return false;
        }

        return match (true) {
            $r instanceof User => Access::allows($r->isDriverOnly() ? 'manage-drivers' : 'manage-team'),
            $r instanceof Booking => Access::allows('manage-bookings'),
            $r instanceof Quotation => Access::allows('manage-quotations'),
            default => Access::allows('manage-leads'),
        };
    }

    /** @return array<string, string> "kind:id" => label */
    public static function pdfOptions(Model $r): array
    {
        $out = [];
        if ($r instanceof Quotation) {
            $out['quotation:'.$r->id] = 'Quotation '.$r->number.' (PDF)';
        }
        if ($r instanceof Booking) {
            if (in_array($r->status, \App\Enums\BookingStatus::billable(), true)) {
                $out['confirmation:'.$r->id] = 'Booking confirmation (PDF)';
            }
            if ($r->delivered_at) {
                $out['statement:'.$r->id] = 'Return statement (PDF)';
            }
            foreach (\App\Models\Invoice::where('booking_id', $r->id)->where('status', 'issued')->get() as $i) {
                $out['invoice:'.$i->id] = $i->title().' '.$i->number.' (PDF)';
            }
        }

        return $out;
    }
}
