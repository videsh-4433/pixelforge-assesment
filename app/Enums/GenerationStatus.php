<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';        // ours: created locally, not yet accepted by Pixelforge
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** Position in the lifecycle. A generation only ever moves to a higher rank. */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    /** @return list<self> */
    public static function inFlight(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}
