<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailLogResource\Pages;
use App\Models\EmailLog;
use App\Support\Access;
use App\Support\Mail\EmailLogger;
use App\Support\Mail\MailRouter;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Settings › Email log (4 Nov, 14.4): every email the CRM sent, from which mailbox, its status
 * and error. Failed emails can be sent again. Texts are cleared after the retention period.
 */
class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Email log';

    protected static ?string $modelLabel = 'email';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'email-log';

    public static function canViewAny(): bool
    {
        return Access::allows('manage-integrations') || Access::allows('manage-email') || Access::allows('view-history');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(self::columns())
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(EmailLog::STATUSES),
                Tables\Filters\SelectFilter::make('type')->options(MailRouter::TYPES + ['other' => 'Other']),
                Tables\Filters\SelectFilter::make('mail_account_id')->label('Mailbox')->relationship('account', 'label'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::resendAction(),
            ])
            ->emptyStateHeading('No emails yet');
    }

    public static function columns(): array
    {
        return [
            Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable()->description(fn (EmailLog $r) => $r->sent_at ? 'Sent '.$r->sent_at->format('H:i') : null),
            Tables\Columns\TextColumn::make('to_email')->label('To')->searchable()->description(fn (EmailLog $r) => $r->to_name),
            Tables\Columns\TextColumn::make('subject')->searchable()->limit(60)->description(fn (EmailLog $r) => $r->kind),
            Tables\Columns\TextColumn::make('account.label')->label('Mailbox')->placeholder('.env mailer')->toggleable(),
            Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => EmailLog::STATUSES[$state] ?? $state)
                ->color(fn ($state) => EmailLog::COLORS[$state] ?? 'gray')
                ->description(fn (EmailLog $r) => $r->status === 'failed' ? \Illuminate\Support\Str::limit((string) $r->error, 70) : null),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('to_email')->label('To'),
            Infolists\Components\TextEntry::make('subject'),
            Infolists\Components\TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => EmailLog::STATUSES[$state] ?? $state)->color(fn ($state) => EmailLog::COLORS[$state] ?? 'gray'),
            Infolists\Components\TextEntry::make('account.label')->label('Mailbox')->placeholder('.env mailer'),
            Infolists\Components\TextEntry::make('type')->formatStateUsing(fn ($state) => MailRouter::TYPES[$state] ?? $state),
            Infolists\Components\TextEntry::make('triggeredBy.name')->label('Sent by')->placeholder('The system'),
            Infolists\Components\TextEntry::make('error')->visible(fn (EmailLog $r) => filled($r->error))->columnSpanFull(),
            Infolists\Components\TextEntry::make('body')->label('Email')->columnSpanFull()
                ->state(fn (EmailLog $r) => $r->body ? new HtmlString('<iframe sandbox="" style="width:100%;height:520px;border:1px solid rgba(127,127,127,.3);border-radius:10px;background:#fff" srcdoc="'.e($r->body).'"></iframe>') : 'The text was cleared after the retention period (or the email was never built).'),
        ])->columns(3);
    }

    public static function resendAction(string $class = Tables\Actions\Action::class)
    {
        return $class::make('resend')
            ->label('Send again')->icon('heroicon-o-arrow-path')->color('warning')
            ->visible(fn (EmailLog $record): bool => $record->status === 'failed' && filled($record->body) && (Access::allows('manage-integrations') || Access::allows('manage-email')))
            ->requiresConfirmation()
            ->action(function (EmailLog $record) {
                $ok = self::resend($record);
                Notification::make()->title($ok ? 'Sent again' : 'It failed again')->{$ok ? 'success' : 'danger'}()->send();
            });
    }

    /** Sends the stored email again (same text) through the mailbox for its type. */
    public static function resend(EmailLog $old): bool
    {
        $id = EmailLogger::queued($old->to_email, $old->to_name, $old->type, $old->kind ?: 'Sent again', $old->related, $old->user, $old->batch);
        try {
            $account = MailRouter::accountFor($old->type);
            $mailer = app('mail.manager')->mailer($account ? MailRouter::mailerFor($account) : null);
            $mailer->html((string) $old->body, function ($m) use ($old, $account, $id) {
                $m->to($old->to_email, $old->to_name)->subject((string) $old->subject);
                if ($account) {
                    $m->from($account->from_address, $account->from_name);
                }
                $m->getSymfonyMessage()->getHeaders()->addTextHeader(EmailLogger::HEADER, (string) $id);
            });
            \App\Models\AuditLog::event('email', 'Email sent again to '.$old->to_email.': '.$old->subject);

            return true;
        } catch (\Throwable $e) {
            EmailLogger::failed($id, $e);

            return false;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailLogs::route('/'),
        ];
    }
}
