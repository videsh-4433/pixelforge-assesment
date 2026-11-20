<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_GENERATION_CHARGE = 'generation_charge';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_nano' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
