<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimated_cost_nano' => 'integer',
            'estimated_charge_nano' => 'integer',
            'max_charge_nano' => 'integer',
            'cost_nano' => 'integer',
            'charge_nano' => 'integer',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::inFlight());
    }

    /** Parameters sent to POST /v1/generations. */
    public function toPixelforgeRequest(): array
    {
        return array_filter([
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?: null,
            'metadata' => [
                'app_generation_id' => $this->uuid,
                'user_id' => (string) $this->user_id,
            ],
        ], fn ($value) => $value !== null);
    }

    /** Shape sent to the Vue pages. Money as exact decimal strings. */
    public function toFrontend(): array
    {
        $money = fn (?int $nano) => $nano === null ? null : Money::nanoToDecimal($nano);

        return [
            'id' => $this->id,
            'pixelforge_id' => $this->pixelforge_id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_charge' => $money($this->estimated_charge_nano),
            'max_charge' => $money($this->max_charge_nano),
            'charge' => $money($this->charge_nano),
            'usage' => $this->status === GenerationStatus::Succeeded ? [
                'input_text_tokens' => $this->input_text_tokens,
                'input_image_tokens' => $this->input_image_tokens,
                'output_tokens' => $this->output_tokens,
                'reasoning_tokens' => $this->reasoning_tokens,
            ] : null,
            'image_url' => $this->image_url,
            'image_expired' => $this->image_url_expires_at?->isPast() ?? false,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'notice' => $this->notice,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
