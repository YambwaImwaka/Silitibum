<?php

namespace App\Services\Payments;

// The one place that decides what a provider response MEANS for money.
//
// 'failed' is a verdict that triggers irreversible handling: a top-up row goes
// terminal (later webhooks are ignored) and a payout gets refunded. So it is
// only ever returned when the provider itself said the transaction failed or
// rejected the request outright. Everything else that goes wrong between us and
// the provider — a timeout, a 5xx, a 429, "not found", "Duplicate reference" —
// tells us nothing about whether money moved, and maps to 'processing' so the
// sweep (topups:reconcile / payouts:reconcile) or a later webhook decides.
//
// Before this existed every gateway mapped those to 'failed': a paid top-up
// whose status check timed out was buried for good, and once an automated
// payout provider is active the same mapping would refund a payout that had
// already left the account.
final class GatewayResult
{
    public const COMPLETED = 'completed';
    public const PROCESSING = 'processing';
    public const FAILED = 'failed';

    public static function make(string $status, ?string $reference, $message = null, array $extra = []): array
    {
        return array_merge([
            'success' => $status !== self::FAILED,
            'status' => $status,
            'provider_reference' => $reference,
            'message' => $message,
        ], $extra);
    }

    /// The call threw (DNS, TLS, connection reset, timeout). The provider may
    /// or may not have acted on it.
    public static function unknown(?string $reference, string $message = 'provider_unreachable', array $extra = []): array
    {
        return self::make(self::PROCESSING, $reference, $message, array_merge(['unconfirmed' => true], $extra));
    }

    /// A non-2xx answer to a call that STARTS a transaction (charge/payout).
    /// A 4xx validation error means nothing was created, so it is a real
    /// failure. Server errors, throttling and "Duplicate reference" (a
    /// transaction with this reference already exists) are not.
    public static function fromInitiationError($response, ?string $reference): array
    {
        $httpStatus = (int) $response->status();
        $message = self::messageOf($response) ?: ('http_' . $httpStatus);
        if ($httpStatus >= 500 || $httpStatus === 429 || $httpStatus === 408
            || stripos($message, 'duplicate') !== false) {
            return self::unknown($reference, $message);
        }
        return self::make(self::FAILED, $reference, $message);
    }

    /// A non-2xx (or status:false) answer to a STATUS check. Never a verdict:
    /// the transaction's state is simply unknown right now. 'not_found' lets
    /// the sweep expire a transaction that never existed, after a grace period.
    public static function fromVerifyError($response, ?string $reference): array
    {
        $httpStatus = (int) $response->status();
        $message = self::messageOf($response) ?: ('http_' . $httpStatus);
        $notFound = $httpStatus === 404 || stripos($message, 'not found') !== false;
        return self::unknown($reference, $message, ['not_found' => $notFound]);
    }

    private static function messageOf($response): string
    {
        try {
            return (string) ($response->json('message') ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
