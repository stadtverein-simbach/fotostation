<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SubmissionStatus: string implements HasColor, HasLabel
{
    case Received = 'received';
    case Processing = 'processing';
    case AutoRejected = 'auto_rejected';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Received => 'Eingegangen',
            self::Processing => 'In Verarbeitung',
            self::AutoRejected => 'Automatisch abgelehnt',
            self::PendingReview => 'Wartet auf Freigabe',
            self::Approved => 'Freigegeben',
            self::Published => 'Veröffentlicht',
            self::Rejected => 'Abgelehnt',
            self::Failed => 'Fehler',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Received, self::Processing => 'gray',
            self::AutoRejected, self::Rejected => 'danger',
            self::PendingReview => 'warning',
            self::Approved => 'info',
            self::Published => 'success',
            self::Failed => 'danger',
        };
    }

    /**
     * Status, in denen die Verarbeitungskette noch arbeiten darf.
     */
    public function isProcessable(): bool
    {
        return in_array($this, [self::Received, self::Processing], true);
    }
}
