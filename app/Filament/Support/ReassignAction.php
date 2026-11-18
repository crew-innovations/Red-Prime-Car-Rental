<?php

namespace App\Filament\Support;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Support\Access;
use App\Support\Leads\Reassign;
use Filament\Forms;
use Filament\Notifications\Notification;

/** 5 Nov (S3, B.4): "Move to another agent" on a lead or a customer. Admin only, with a reason. */
class ReassignAction
{
    public static function make(string $class)
    {
        return $class::make('reassign')
            ->label('Move to another agent')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->authorize('assign-leads')
            ->modalDescription('Moves this and everything still open (leads, customer, bookings not finished) to the agent you choose. Finished records keep who handled them. Both agents are told.')
            ->form([
                Forms\Components\Select::make('to')->label('Agent')->required()->searchable()
                    ->options(fn () => User::query()->where('is_active', true)->where('is_system', false)->whereNull('customer_id')
                        ->whereHas('roles', fn ($q) => $q->whereIn('name', Access::withLegacy(['Agent', 'Admin'])))->orderBy('name')->pluck('name', 'id')->all()),
                Forms\Components\TextInput::make('reason')->label('Why?')->required()->maxLength(200),
            ])
            ->action(function (Lead|Customer $record, array $data, $livewire) {
                $moved = Reassign::chain($record, User::findOrFail($data['to']), $data['reason']);
                Notification::make()->title('Moved to '.User::find($data['to'])?->name)
                    ->body($moved['leads'].' open lead(s) and '.$moved['bookings'].' open booking(s) moved.')->success()->send();
                $livewire->redirect($livewire::getResource()::getUrl('edit', ['record' => $record]));
            });
    }
}
