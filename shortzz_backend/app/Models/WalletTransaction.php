<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One row of the wallet ledger. Written only by App\Services\Wallet\WalletLedger
// (append-only — never updated or deleted); read here for history screens.
class WalletTransaction extends Model
{
    public $table = 'wallet_transactions';
    public $timestamps = false;

    // Internal bookkeeping, never part of an API payload.
    protected $hidden = ['idempotency_key', 'meta', 'group_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function metaArray(): array
    {
        $meta = json_decode((string) $this->meta, true);
        return is_array($meta) ? $meta : [];
    }
}
