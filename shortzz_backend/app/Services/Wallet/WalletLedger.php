<?php

namespace App\Services\Wallet;

use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The only code allowed to change a wallet balance.
//
// Three balances live on tbl_users:
// - coin_wallet (+ coin_wallet_value): spend-only coins and what they cost.
// - earnings_balance: K a creator has earned from gifts — the only thing that
//   can be withdrawn.
// - (the platform's commission has no row; it lives in the ledger alone.)
//
// Every change writes wallet_transactions rows in the same DB transaction, so
// the ledger always sums to the balances (wallet:reconcile checks). Credits that
// can be replayed — webhook deliveries, purchase syncs, refunds, migrations —
// carry an idempotency key; the unique index on it is what makes them
// exactly-once, never a status read beforehand.
//
// Amounts are integer units of 1/10 000 K (see Money).
final class WalletLedger
{
    public const ACCOUNT_COINS = 'coins';
    public const ACCOUNT_EARNINGS = 'earnings';
    public const ACCOUNT_PLATFORM = 'platform';

    public const TYPE_PURCHASE_TOPUP = 'purchase_topup';
    public const TYPE_PURCHASE_IAP = 'purchase_iap';
    public const TYPE_BONUS_REGISTRATION = 'bonus_registration';
    public const TYPE_ADMIN_ADJUSTMENT = 'admin_adjustment';
    public const TYPE_GIFT_SENT = 'gift_sent';
    public const TYPE_GIFT_RECEIVED = 'gift_received';
    public const TYPE_PLATFORM_COMMISSION = 'platform_commission';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_WITHDRAWAL_REFUND = 'withdrawal_refund';
    public const TYPE_IAP_REFUND = 'iap_refund';
    public const TYPE_CREATOR_FUND = 'creator_fund';
    public const TYPE_MIGRATION_OPENING = 'migration_opening';
    // A store refund of coins that were already gifted: the creator's share
    // and the platform's commission on those gifts are taken back.
    public const TYPE_GIFT_REVERSED = 'gift_reversed';
    public const TYPE_COMMISSION_REVERSED = 'commission_reversed';

    // Earnings that trace back to a fan's payment can still be reversed (a
    // store refund, a stolen mobile-money wallet), so they wait out
    // earnings_hold_days before they can be withdrawn. Refunds of a creator's
    // own withdrawal and migrated balances are not held.
    public const HELD_TYPES = [self::TYPE_GIFT_RECEIVED, self::TYPE_CREATOR_FUND];

    private const PURCHASE_TYPES = [self::TYPE_PURCHASE_TOPUP, self::TYPE_PURCHASE_IAP];

    public static function sharePercent(): int
    {
        $share = (int) (GlobalSettings::first()->creator_share_percent ?? 60);
        return max(0, min(100, $share));
    }

    public static function holdDays(): int
    {
        return max(0, (int) (GlobalSettings::first()->earnings_hold_days ?? 7));
    }

    /// Adds coins. $valueUnits is what they cost (0 for bonus/admin coins,
    /// which can be gifted but never turn into anyone's earnings).
    /// Returns false when $key was already used — the credit already happened.
    public static function creditCoins(int $userId, int $coins, int $valueUnits, string $type, ?string $key, array $refs = [], array $meta = []): bool
    {
        if ($coins <= 0 || $valueUnits < 0) {
            throw new \InvalidArgumentException('creditCoins needs positive coins and a non-negative value');
        }
        return DB::transaction(function () use ($userId, $coins, $valueUnits, $type, $key, $refs, $meta) {
            $user = self::lockUser($userId);
            if ($user === null) {
                return false;
            }
            $claimed = self::insertRow(array_merge($refs, [
                'user_id' => $userId,
                'account' => self::ACCOUNT_COINS,
                'type' => $type,
                'coins' => $coins,
                'amount' => Money::fromUnits($valueUnits),
                'balance_after_coins' => (int) $user->coin_wallet + $coins,
                'balance_after_amount' => Money::fromUnits(Money::toUnits($user->coin_wallet_value) + $valueUnits),
                'idempotency_key' => $key,
                'meta' => $meta ? json_encode($meta) : null,
            ]));
            if (!$claimed) {
                return false;
            }
            $update = [
                'coin_wallet' => DB::raw('coin_wallet + ' . $coins),
                'coin_wallet_value' => DB::raw('coin_wallet_value + ' . Money::fromUnits($valueUnits)),
            ];
            if (in_array($type, self::PURCHASE_TYPES, true)) {
                $update['coin_purchased_lifetime'] = DB::raw('coin_purchased_lifetime + ' . $coins);
            }
            DB::table('tbl_users')->where('id', $userId)->update($update);
            return true;
        }, 3);
    }

    /// Removes up to $coins (never below zero) together with their share of
    /// the cost basis. Returns the coins actually removed, or null when $key
    /// was already used.
    public static function debitCoins(int $userId, int $coins, string $type, ?string $key, array $refs = [], array $meta = []): ?int
    {
        return DB::transaction(function () use ($userId, $coins, $type, $key, $refs, $meta) {
            $user = self::lockUser($userId);
            if ($user === null) {
                return 0;
            }
            $held = (int) $user->coin_wallet;
            $removed = max(0, min($coins, $held));
            $walletUnits = Money::toUnits($user->coin_wallet_value);
            $valueUnits = Money::proportion($walletUnits, $removed, $held);
            if ($removed < $coins) {
                $meta['shortfall_coins'] = $coins - $removed;
            }
            $claimed = self::insertRow(array_merge($refs, [
                'user_id' => $userId,
                'account' => self::ACCOUNT_COINS,
                'type' => $type,
                'coins' => -$removed,
                'amount' => Money::fromUnits(-$valueUnits),
                'balance_after_coins' => $held - $removed,
                'balance_after_amount' => Money::fromUnits($walletUnits - $valueUnits),
                'idempotency_key' => $key,
                'meta' => $meta ? json_encode($meta) : null,
            ]));
            if (!$claimed) {
                return null;
            }
            if ($removed > 0) {
                DB::table('tbl_users')->where('id', $userId)->update([
                    'coin_wallet' => DB::raw('coin_wallet - ' . $removed),
                    'coin_wallet_value' => DB::raw('coin_wallet_value - ' . Money::fromUnits($valueUnits)),
                ]);
            }
            return $removed;
        }, 3);
    }

    /// A gift: the sender spends $price coins at their average cost, the
    /// receiver earns creator_share_percent of that value, the platform keeps
    /// the remainder. Coins bought for nothing (bonus, admin grants) carry no
    /// value, so gifting them pays nobody — that is what closes sign-up bonus
    /// farming. Returns the split, or null when the sender cannot afford it.
    public static function transferGift(int $senderId, int $receiverId, int $price, ?int $giftId, array $context = []): ?array
    {
        if ($price <= 0 || $senderId === $receiverId) {
            throw new \InvalidArgumentException('transferGift needs a positive price between two different users');
        }
        $share = self::sharePercent();
        return DB::transaction(function () use ($senderId, $receiverId, $price, $giftId, $context, $share) {
            // Both rows, always in id order, so two people gifting each other
            // at the same moment cannot deadlock.
            $lock = fn () => DB::table('tbl_users')
                ->whereIn('id', [$senderId, $receiverId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'coin_wallet', 'coin_wallet_value', 'earnings_balance'])
                ->keyBy('id');
            $rows = $lock();
            // A wallet from before the ledger is converted first, so the
            // gift is valued against real costs and the receiver's legacy
            // received coins become earnings before this gift lands.
            $converted = false;
            foreach ($rows as $id => $row) {
                if (LegacyWallet::needsConversion((int) $id, $row)) {
                    LegacyWallet::convert((int) $id);
                    $converted = true;
                }
            }
            if ($converted) {
                $rows = $lock();
            }
            $sender = $rows->get($senderId);
            $receiver = $rows->get($receiverId);
            if ($sender === null || $receiver === null || (int) $sender->coin_wallet < $price) {
                return null;
            }

            $walletUnits = Money::toUnits($sender->coin_wallet_value);
            $valueUnits = Money::proportion($walletUnits, $price, (int) $sender->coin_wallet);
            $creatorUnits = intdiv($valueUnits * $share, 100);
            $platformUnits = $valueUnits - $creatorUnits;
            $earningsAfter = Money::toUnits($receiver->earnings_balance) + $creatorUnits;

            DB::table('tbl_users')->where('id', $senderId)->update([
                'coin_wallet' => DB::raw('coin_wallet - ' . $price),
                'coin_wallet_value' => DB::raw('coin_wallet_value - ' . Money::fromUnits($valueUnits)),
                'coin_gifted_lifetime' => DB::raw('coin_gifted_lifetime + ' . $price),
            ]);
            DB::table('tbl_users')->where('id', $receiverId)->update([
                'earnings_balance' => DB::raw('earnings_balance + ' . Money::fromUnits($creatorUnits)),
                'earnings_lifetime' => DB::raw('earnings_lifetime + ' . Money::fromUnits($creatorUnits)),
                // Coins received still drive levels and the public "collected"
                // count, whatever they paid out.
                'coin_collected_lifetime' => DB::raw('coin_collected_lifetime + ' . $price),
            ]);

            $group = (string) Str::uuid();
            $shared = [
                'group_id' => $group,
                'gift_id' => $giftId,
                'post_id' => $context['post_id'] ?? null,
                'livestream_id' => $context['livestream_id'] ?? null,
            ];
            // On the earnings and platform rows `coins` is the gift's size,
            // for display; only coins-account rows move a coin balance.
            self::insertRow(array_merge($shared, [
                'user_id' => $senderId,
                'account' => self::ACCOUNT_COINS,
                'type' => self::TYPE_GIFT_SENT,
                'coins' => -$price,
                'amount' => Money::fromUnits(-$valueUnits),
                'balance_after_coins' => (int) $sender->coin_wallet - $price,
                'balance_after_amount' => Money::fromUnits($walletUnits - $valueUnits),
                'counterparty_user_id' => $receiverId,
            ]));
            self::insertRow(array_merge($shared, [
                'user_id' => $receiverId,
                'account' => self::ACCOUNT_EARNINGS,
                'type' => self::TYPE_GIFT_RECEIVED,
                'coins' => $price,
                'amount' => Money::fromUnits($creatorUnits),
                'balance_after_amount' => Money::fromUnits($earningsAfter),
                'counterparty_user_id' => $senderId,
                'meta' => json_encode(['share_percent' => $share, 'gift_value' => Money::fromUnits($valueUnits)]),
            ]));
            self::insertRow(array_merge($shared, [
                'user_id' => null,
                'account' => self::ACCOUNT_PLATFORM,
                'type' => self::TYPE_PLATFORM_COMMISSION,
                'coins' => $price,
                'amount' => Money::fromUnits($platformUnits),
                'counterparty_user_id' => $receiverId,
            ]));

            return ['value' => $valueUnits, 'creator' => $creatorUnits, 'platform' => $platformUnits, 'group_id' => $group];
        }, 3);
    }

    /// Adds K to a creator's earnings. False when $key was already used.
    public static function creditEarnings(int $userId, int $amountUnits, string $type, ?string $key, array $refs = [], array $meta = []): bool
    {
        if ($amountUnits <= 0) {
            throw new \InvalidArgumentException('creditEarnings needs a positive amount');
        }
        return DB::transaction(function () use ($userId, $amountUnits, $type, $key, $refs, $meta) {
            $user = self::lockUser($userId);
            if ($user === null) {
                return false;
            }
            $claimed = self::insertRow(array_merge($refs, [
                'user_id' => $userId,
                'account' => self::ACCOUNT_EARNINGS,
                'type' => $type,
                'amount' => Money::fromUnits($amountUnits),
                'balance_after_amount' => Money::fromUnits(Money::toUnits($user->earnings_balance) + $amountUnits),
                'idempotency_key' => $key,
                'meta' => $meta ? json_encode($meta) : null,
            ]));
            if (!$claimed) {
                return false;
            }
            $update = ['earnings_balance' => DB::raw('earnings_balance + ' . Money::fromUnits($amountUnits))];
            // Lifetime counts what was earned, not money coming back from a
            // cancelled withdrawal.
            if ($type !== self::TYPE_WITHDRAWAL_REFUND) {
                $update['earnings_lifetime'] = DB::raw('earnings_lifetime + ' . Money::fromUnits($amountUnits));
            }
            DB::table('tbl_users')->where('id', $userId)->update($update);
            return true;
        }, 3);
    }

    /// Takes K out of earnings. With $respectHold, only what has cleared the
    /// hold window can go. Returns null on success, or why it cannot.
    public static function debitEarnings(int $userId, int $amountUnits, string $type, ?string $key, array $refs = [], array $meta = [], bool $respectHold = true): ?string
    {
        if ($amountUnits <= 0) {
            throw new \InvalidArgumentException('debitEarnings needs a positive amount');
        }
        return DB::transaction(function () use ($userId, $amountUnits, $type, $key, $refs, $meta, $respectHold) {
            $user = self::lockUser($userId);
            if ($user === null) {
                return 'user not found';
            }
            $balance = Money::toUnits($user->earnings_balance);
            $spendable = $respectHold ? self::availableUnits($userId, $balance) : $balance;
            if ($spendable < $amountUnits) {
                return 'insufficient earnings';
            }
            $claimed = self::insertRow(array_merge($refs, [
                'user_id' => $userId,
                'account' => self::ACCOUNT_EARNINGS,
                'type' => $type,
                'amount' => Money::fromUnits(-$amountUnits),
                'balance_after_amount' => Money::fromUnits($balance - $amountUnits),
                'idempotency_key' => $key,
                'meta' => $meta ? json_encode($meta) : null,
            ]));
            if (!$claimed) {
                return 'already recorded';
            }
            DB::table('tbl_users')->where('id', $userId)->update([
                'earnings_balance' => DB::raw('earnings_balance - ' . Money::fromUnits($amountUnits)),
            ]);
            return null;
        }, 3);
    }

    /// Earnings that can be withdrawn now: the balance minus what was earned
    /// inside the hold window.
    public static function availableUnits(int $userId, ?int $balanceUnits = null): int
    {
        if ($balanceUnits === null) {
            $balanceUnits = Money::toUnits(DB::table('tbl_users')->where('id', $userId)->value('earnings_balance'));
        }
        $holdDays = self::holdDays();
        if ($holdDays === 0) {
            return max(0, $balanceUnits);
        }
        $recent = DB::table('wallet_transactions')
            ->where('user_id', $userId)
            ->where('account', self::ACCOUNT_EARNINGS)
            ->whereIn('type', self::HELD_TYPES)
            ->where('created_at', '>=', now()->subDays($holdDays))
            ->sum('amount');
        return max(0, min($balanceUnits, $balanceUnits - Money::toUnits((string) $recent)));
    }

    /// The wallet numbers the owner sees, for API payloads.
    public static function summary(int $userId): array
    {
        $user = DB::table('tbl_users')->where('id', $userId)->first(['earnings_balance', 'earnings_lifetime', 'coin_wallet']);
        if ($user === null) {
            return [];
        }
        $balance = Money::toUnits($user->earnings_balance);
        $available = self::availableUnits($userId, $balance);
        return [
            'earnings_balance' => Money::format($balance),
            'earnings_available' => Money::format($available),
            'earnings_on_hold' => Money::format($balance - $available),
            'earnings_lifetime' => Money::format(Money::toUnits($user->earnings_lifetime)),
        ];
    }

    /// Locks the user's row for the rest of the transaction. A wallet from
    /// before the ledger is converted on first touch (see LegacyWallet), so
    /// nothing the ledger writes can mix with an unconverted balance.
    private static function lockUser(int $userId): ?object
    {
        $lock = fn () => DB::table('tbl_users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->first(['id', 'coin_wallet', 'coin_wallet_value', 'earnings_balance']);
        $row = $lock();
        if ($row !== null && LegacyWallet::needsConversion($userId, $row)) {
            LegacyWallet::convert($userId);
            $row = $lock();
        }
        return $row;
    }

    /// A store refund of a coin purchase: the refunded coins and their price
    /// leave the wallet. The VALUE comes out at the price paid (not at the
    /// wallet's average cost, which would leave most of a refunded payment
    /// riding on free coins). Whatever the wallet no longer holds was already
    /// gifted away: those gifts — newest first, since the purchase — are
    /// reversed out of the creators' earnings and the platform's commission,
    /// as far as the earnings are still there. That is what the earnings hold
    /// window exists to make possible. Idempotent by $key.
    public static function refundPurchase(int $userId, int $coins, int $valueUnits, string $key, ?string $purchasedAt, array $meta = []): bool
    {
        $refund = DB::transaction(function () use ($userId, $coins, $valueUnits, $key, $meta) {
            $user = self::lockUser($userId);
            if ($user === null) {
                return null;
            }
            // Already refunded: hand back what was recorded, so the gift
            // reversals below can finish if an earlier run died halfway.
            $existing = DB::table('wallet_transactions')->where('idempotency_key', $key)->first(['id', 'meta', 'created_at']);
            if ($existing !== null) {
                $recorded = json_decode((string) $existing->meta, true) ?: [];
                return ['new' => false, 'shortfall' => Money::toUnits((string) ($recorded['shortfall_value'] ?? '0')), 'at' => (string) $existing->created_at];
            }

            $heldCoins = (int) $user->coin_wallet;
            $heldValue = Money::toUnits($user->coin_wallet_value);
            $removedCoins = max(0, min($coins, $heldCoins));
            $removedValue = max(0, min($valueUnits, $heldValue));
            $shortfall = $valueUnits - $removedValue;
            $meta['refunded_value'] = Money::fromUnits($valueUnits);
            if ($removedCoins < $coins) {
                $meta['shortfall_coins'] = $coins - $removedCoins;
            }
            if ($shortfall > 0) {
                $meta['shortfall_value'] = Money::fromUnits($shortfall);
            }
            $at = now();
            self::insertRow([
                'user_id' => $userId,
                'account' => self::ACCOUNT_COINS,
                'type' => self::TYPE_IAP_REFUND,
                'coins' => -$removedCoins,
                'amount' => Money::fromUnits(-$removedValue),
                'balance_after_coins' => $heldCoins - $removedCoins,
                'balance_after_amount' => Money::fromUnits($heldValue - $removedValue),
                'idempotency_key' => $key,
                'meta' => json_encode($meta),
                'created_at' => $at,
            ]);
            DB::table('tbl_users')->where('id', $userId)->update([
                'coin_wallet' => DB::raw('coin_wallet - ' . $removedCoins),
                'coin_wallet_value' => DB::raw('coin_wallet_value - ' . Money::fromUnits($removedValue)),
            ]);
            return ['new' => true, 'shortfall' => $shortfall, 'at' => (string) $at];
        }, 3);

        if ($refund === null) {
            return false;
        }
        if ($refund['shortfall'] > 0) {
            self::reverseGiftsFunded($userId, $refund['shortfall'], $key, $purchasedAt, $refund['at']);
        }
        return $refund['new'];
    }

    /// Takes $valueUnits of already-gifted value back from the gifts $userId
    /// sent between the purchase ($since) and the refund ($until), newest
    /// first: each creator loses their share of the reversed part — never
    /// below zero — and the platform its commission. Bounded to that window
    /// so a re-run never touches gifts sent after the refund, and keyed per
    /// gift so nothing is reversed twice.
    private static function reverseGiftsFunded(int $userId, int $valueUnits, string $key, ?string $since, string $until): void
    {
        $currency = GlobalSettings::first()->currency ?? '';
        $sent = DB::table('wallet_transactions')
            ->where('user_id', $userId)
            ->where('type', self::TYPE_GIFT_SENT)
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->where('created_at', '<=', $until)
            ->orderByDesc('id')
            ->get(['id', 'group_id', 'amount', 'gift_id', 'post_id', 'livestream_id']);

        $remaining = $valueUnits;
        foreach ($sent as $gift) {
            if ($remaining <= 0) {
                break;
            }
            $giftValue = -Money::toUnits((string) $gift->amount);
            if ($giftValue <= 0) {
                continue;
            }
            $portion = min($giftValue, $remaining);
            $remaining -= $portion;

            $received = DB::table('wallet_transactions')->where('group_id', $gift->group_id)->where('type', self::TYPE_GIFT_RECEIVED)->first(['user_id', 'amount']);
            $creatorPart = $received ? Money::proportion(Money::toUnits((string) $received->amount), $portion, $giftValue) : 0;
            $platformPart = $portion - $creatorPart;
            $refs = ['group_id' => $gift->group_id, 'gift_id' => $gift->gift_id, 'post_id' => $gift->post_id, 'livestream_id' => $gift->livestream_id];

            if ($received && $creatorPart > 0) {
                $creatorId = (int) $received->user_id;
                $taken = DB::transaction(function () use ($creatorId, $creatorPart, $key, $gift, $refs, $userId) {
                    $creator = self::lockUser($creatorId);
                    $balance = Money::toUnits($creator->earnings_balance ?? '0');
                    $take = max(0, min($creatorPart, $balance));
                    $claimed = self::insertRow(array_merge($refs, [
                        'user_id' => $creatorId,
                        'account' => self::ACCOUNT_EARNINGS,
                        'type' => self::TYPE_GIFT_REVERSED,
                        'amount' => Money::fromUnits(-$take),
                        'balance_after_amount' => Money::fromUnits($balance - $take),
                        'counterparty_user_id' => $userId,
                        'idempotency_key' => 'reverse:' . $key . ':' . $gift->id,
                        'meta' => json_encode(['owed' => Money::fromUnits($creatorPart), 'unrecovered' => Money::fromUnits($creatorPart - $take)]),
                    ]));
                    if ($claimed && $take > 0) {
                        DB::table('tbl_users')->where('id', $creatorId)->update([
                            'earnings_balance' => DB::raw('earnings_balance - ' . Money::fromUnits($take)),
                        ]);
                    }
                    return $claimed ? $take : null;
                }, 3);
                if ($taken !== null && $taken > 0) {
                    GlobalFunction::pushToUser($creatorId, 'A gift was refunded',
                        'The person who sent you a gift got their money back from the store, so ' . $currency . Money::format($taken) . ' was removed from your earnings.',
                        ['type' => 'wallet']);
                }
            }
            if ($platformPart > 0) {
                self::insertRow(array_merge($refs, [
                    'user_id' => null,
                    'account' => self::ACCOUNT_PLATFORM,
                    'type' => self::TYPE_COMMISSION_REVERSED,
                    'amount' => Money::fromUnits(-$platformPart),
                    'counterparty_user_id' => $received->user_id ?? null,
                    'idempotency_key' => 'reverse-platform:' . $key . ':' . $gift->id,
                ]));
            }
        }
    }

    /// False only when the idempotency key is already taken.
    private static function insertRow(array $row): bool
    {
        $row['created_at'] = $row['created_at'] ?? now();
        try {
            DB::table('wallet_transactions')->insert($row);
            return true;
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && !empty($row['idempotency_key'])) {
                return false;
            }
            throw $e;
        }
    }
}
