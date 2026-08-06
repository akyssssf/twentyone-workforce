<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Draft = 'draft';
    case PendingPeer = 'pending_peer';
    case PendingManager = 'pending_manager';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::PendingPeer => 'Menunggu Rekan',
            self::PendingManager => 'Menunggu Manager',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
            self::Expired => 'Kedaluwarsa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'emerald',
            self::Rejected, self::Expired => 'red',
            self::PendingPeer, self::PendingManager => 'amber',
            default => 'slate',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::PendingPeer, self::PendingManager], true);
    }

    public function isFinal(): bool
    {
        return ! $this->isOpen();
    }
}
