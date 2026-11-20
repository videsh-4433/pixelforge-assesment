<?php

namespace App\Services;

use App\Exceptions\BalanceCapExceededException;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a user's balance goes through here: one ledger row per change, plus the cached
 * users.balance_nano updated in the same transaction. Sum(ledger) == balance at all times.
 */
class CreditLedger
{
    /**
     * Add (fake) purchased credits, refusing to push the balance above $maxBalanceNano. The check
     * runs under a row lock so concurrent purchases can't both slip under the cap.
     *
     * @throws BalanceCapExceededException
     */
    public function purchase(User $user, int $amountNano, ?int $maxBalanceNano = null): CreditTransaction
    {
        return DB::transaction(function () use ($user, $amountNano, $maxBalanceNano) {
            $balance = User::whereKey($user->id)->lockForUpdate()->value('balance_nano');

            if ($maxBalanceNano !== null && $balance + $amountNano > $maxBalanceNano) {
                throw new BalanceCapExceededException;
            }

            return $this->record($user, CreditTransaction::TYPE_PURCHASE, $amountNano, null, 'Credit purchase');
        });
    }

    /**
     * Charge a succeeded generation. Must run inside the transaction that holds the generation's row
     * lock. The unique (generation_id, type) index is a second guard against a double charge.
     */
    public function chargeGeneration(Generation $generation): CreditTransaction
    {
        return $this->record(
            $generation->user,
            CreditTransaction::TYPE_GENERATION_CHARGE,
            -$generation->charge_nano,
            $generation->id,
            'Image generation #'.$generation->id,
        );
    }

    private function record(User $user, string $type, int $amountNano, ?int $generationId, string $description): CreditTransaction
    {
        $transaction = CreditTransaction::create([
            'user_id' => $user->id,
            'type' => $type,
            'amount_nano' => $amountNano,
            'generation_id' => $generationId,
            'description' => $description,
        ]);

        // Atomic in-database increment so concurrent writers can't lose an update.
        User::whereKey($user->id)->increment('balance_nano', $amountNano);

        return $transaction;
    }
}
