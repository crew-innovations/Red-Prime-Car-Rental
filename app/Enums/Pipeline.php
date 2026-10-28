<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Sales pipelines and their stages (Schedule A3). The lead's overall status
 * (New / Contacted / Qualified / Converted / Lost) follows from its stage.
 */
enum Pipeline: string implements HasLabel, HasColor, HasIcon
{
    case Car = 'car';
    case Yacht = 'yacht';
    case B2b = 'b2b';

    public const LOST = 'lost';

    public function getLabel(): string
    {
        return match ($this) {
            self::Car => 'Car Rental',
            self::Yacht => 'Yachts & Trips',
            self::B2b => 'B2B',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Car => 'primary',
            self::Yacht => 'info',
            self::B2b => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Car => 'heroicon-m-truck',
            self::Yacht => 'heroicon-m-lifebuoy',
            self::B2b => 'heroicon-m-building-office-2',
        };
    }

    /** @return array<string, string> stage key => label, in order */
    public function stages(): array
    {
        return match ($this) {
            self::Car => [
                'new' => 'New Lead',
                'contacted' => 'Contacted',
                'requirements_confirmed' => 'Requirements Confirmed',
                'car_selected' => 'Car Selected',
                'quotation_sent' => 'Quotation Sent',
                'negotiation' => 'Negotiation',
                'documents_received' => 'Documents Received',
                'payment_pending' => 'Payment Pending',
                'confirmed' => 'Confirmed',
                'vehicle_delivered' => 'Vehicle Delivered',
                'completed' => 'Completed',
            ],
            self::Yacht => [
                'new' => 'New Lead',
                'requirements_confirmed' => 'Requirements Confirmed',
                'yacht_selected' => 'Yacht / Trip Selected',
                'availability_confirmed' => 'Availability Confirmed',
                'quotation_sent' => 'Quotation Sent',
                'deposit_pending' => 'Deposit Pending',
                'confirmed' => 'Confirmed',
                'charter_completed' => 'Completed',
                'balance_paid' => 'Balance Paid',
            ],
            self::B2b => [
                'new_company' => 'New Company',
                'decision_maker' => 'Decision Maker Identified',
                'meeting' => 'Meeting / Call',
                'proposal' => 'Proposal',
                'negotiation' => 'Negotiation',
                'agreement' => 'Agreement',
                'active_account' => 'Active Account',
                'renewal' => 'Renewal',
            ],
        };
    }

    /** Stages plus "Lost", for selects. */
    public function stageOptions(): array
    {
        return $this->stages() + [self::LOST => 'Lost'];
    }

    public function firstStage(): string
    {
        return array_key_first($this->stages());
    }

    public function hasStage(?string $stage): bool
    {
        return $stage === self::LOST || array_key_exists((string) $stage, $this->stages());
    }

    public function stageLabel(?string $stage): string
    {
        return $this->stageOptions()[$stage] ?? (string) $stage;
    }

    /** Position of the stage (0-based), or -1 for lost / unknown. */
    public function stageIndex(?string $stage): int
    {
        $i = array_search($stage, array_keys($this->stages()), true);

        return $i === false ? -1 : $i;
    }

    /** First stage that counts as won (deal confirmed / signed). */
    protected function wonFrom(): string
    {
        return $this === self::B2b ? 'agreement' : 'confirmed';
    }

    /** First stage that counts as a qualified opportunity. */
    protected function qualifiedFrom(): string
    {
        return $this === self::B2b ? 'decision_maker' : 'requirements_confirmed';
    }

    public function isWon(?string $stage): bool
    {
        return $this->stageIndex($stage) >= $this->stageIndex($this->wonFrom());
    }

    public function statusFor(?string $stage, bool $contacted = false): LeadStatus
    {
        if ($stage === self::LOST) {
            return LeadStatus::Lost;
        }

        $i = max(0, $this->stageIndex($stage));

        return match (true) {
            $i >= $this->stageIndex($this->wonFrom()) => LeadStatus::Converted,
            $i >= $this->stageIndex($this->qualifiedFrom()) => LeadStatus::Qualified,
            $i > 0 || $contacted => LeadStatus::Contacted,
            default => LeadStatus::New,
        };
    }

    public function stageColor(?string $stage): string
    {
        return $this->statusFor($stage, true)->getColor();
    }

    /** The pipeline a new lead goes into, based on what they asked about. */
    public static function forService(ServiceType|string|null $service): self
    {
        $service = $service instanceof ServiceType ? $service->value : $service;

        // Yacht trips, trips & packages (and old charters) share the "Yacht Trips" pipeline.
        return in_array($service, [ServiceType::Yacht->value, ServiceType::Trip->value, ServiceType::Package->value], true) ? self::Yacht : self::Car;
    }

    /** Stage a lead reaches when its booking gets to this status (only ever moves forward). */
    public function stageForBooking(BookingStatus $status, bool $fullyPaid = false): ?string
    {
        return match ($this) {
            self::Car => match ($status) {
                BookingStatus::Quotation => 'quotation_sent',
                BookingStatus::PendingPayment => 'payment_pending',
                BookingStatus::Confirmed => 'confirmed',
                BookingStatus::InProgress => 'vehicle_delivered',
                BookingStatus::Completed => 'completed',
                default => null,
            },
            self::Yacht => match ($status) {
                BookingStatus::Quotation => 'quotation_sent',
                BookingStatus::PendingPayment => 'deposit_pending',
                BookingStatus::Confirmed, BookingStatus::InProgress => 'confirmed',
                BookingStatus::Completed => $fullyPaid ? 'balance_paid' : 'charter_completed',
                default => null,
            },
            self::B2b => null,
        };
    }
}
