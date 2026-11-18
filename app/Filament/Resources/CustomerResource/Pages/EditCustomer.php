<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\LeadResource;
use App\Models\Customer;
use App\Support\Phone;
use Filament\Actions;
use App\Filament\Concerns\GuardsSave;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    use GuardsSave;

    protected static string $resource = CustomerResource::class;

    /** The customer's login, if any (created on first invite). */
    protected function customerLogin(bool $create = false): ?\App\Models\User
    {
        $existing = \App\Models\User::where('customer_id', $this->getRecord()->getKey())->first();
        if ($existing || ! $create) {
            return $existing;
        }

        try {
            return \App\Support\Accounts::customerAccount($this->getRecord());
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Filament\Notifications\Notification::make()->title('Cannot create the login')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();

            throw new \Filament\Support\Exceptions\Halt;
        }
    }

    /** Customer account status under the title (4 Nov, 13.4). */
    public function getSubheading(): ?string
    {
        $c = $this->getRecord();

        return ($c->status === 'prospect' ? 'Prospect (has not booked yet) · ' : '').'Account: '.\App\Support\Portal::status($c)['label'];
    }

    protected function getHeaderActions(): array
    {
        /** @var Customer $customer */
        $customer = $this->getRecord();

        return [
            \App\Filament\Support\EmailActions::send(Actions\Action::class),
            \App\Filament\Support\ReassignAction::make(Actions\Action::class), // 5 Nov (S3): Admin only
            Actions\Action::make('whatsapp')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->url(Phone::whatsappUrl($customer->whatsapp ?: $customer->phone, \App\Support\Options::message('msg_greeting', ['name' => $customer->name])))
                ->openUrlInNewTab()
                ->visible(filled($customer->whatsapp ?: $customer->phone)),
            Actions\Action::make('call')
                ->label('Call')
                ->icon('heroicon-o-phone')
                ->color('gray')
                ->url(Phone::telUrl($customer->phone))
                ->visible(filled($customer->phone)),
            Actions\Action::make('newLead')
                ->label('New enquiry')
                ->icon('heroicon-o-plus')
                ->url(LeadResource::getUrl('create', ['customer' => $customer->getKey()])),
            Actions\ActionGroup::make([
                \App\Filament\Support\AccountActions::sendLink(
                    fn () => $this->customerLogin(create: true),
                    $this->customerLogin() ? 'Send new login link' : 'Invite to customer account',
                    fn (): bool => (bool) \App\Models\Setting::read('customer_accounts_enabled', true) && ! $customer->trashed() && ($this->customerLogin()?->login_enabled ?? true),
                ),
                \App\Filament\Support\AccountActions::toggleLogin(fn () => $this->customerLogin()),
                Actions\Action::make('changeLoginEmail')
                    ->label('Change login email')
                    ->icon('heroicon-o-at-symbol')
                    ->visible(fn (): bool => $this->customerLogin() !== null)
                    ->authorize(fn (): bool => \App\Support\Access::allows('manage-accounts'))
                    ->modalDescription('The new address gets a link to confirm it and choose a password. The old address is told about the change.')
                    ->fillForm(fn () => ['email' => $this->customerLogin()?->email])
                    ->form([\Filament\Forms\Components\TextInput::make('email')->label('New login email')->email()->required()->maxLength(190)])
                    ->action(function (array $data) {
                        try {
                            \App\Support\Portal::changeEmail($this->customerLogin(), $data['email']);
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            \Filament\Notifications\Notification::make()->title(collect($e->errors())->flatten()->implode(' '))->danger()->send();

                            return;
                        }
                        \Filament\Notifications\Notification::make()->title('Login email changed. A confirmation link went to '.$data['email'])->success()->send();
                        $this->refreshFormData(['email']);
                    }),
            ])
                ->label('Account')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->button()
                ->visible(fn (): bool => \App\Support\Access::allows('manage-accounts')),
            Actions\ActionGroup::make([
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
