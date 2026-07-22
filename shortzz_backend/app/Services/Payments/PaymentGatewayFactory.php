<?php

namespace App\Services\Payments;

use App\Models\GlobalSettings;

class PaymentGatewayFactory
{
    // Reads the admin's current choice (tbl_settings.active_payout_provider)
    // and returns the matching gateway, already configured with that
    // provider's credentials from the same settings row. Changing the
    // active provider is an admin dropdown change (SettingsController::
    // savePaymentProviderSettings) — this always reflects it immediately,
    // no deploy needed, same as GlobalFunction::getItemBaseUrl's
    // FILES_STORAGE_LOCATION switch does for storage.
    public static function active(): ?PaymentGatewayInterface
    {
        $settings = GlobalSettings::first();
        return self::forProvider($settings->active_payout_provider ?? null, $settings);
    }

    // Separate from active() on purpose — see the migration comment on
    // active_collection_provider. A provider that's great for payouts
    // (e.g. Lenco) may not support collections at all, and vice versa (DPO).
    public static function activeForCollection(): ?PaymentGatewayInterface
    {
        $settings = GlobalSettings::first();
        return self::forProvider($settings->active_collection_provider ?? null, $settings);
    }

    public static function forProvider(?string $provider, GlobalSettings $settings): ?PaymentGatewayInterface
    {
        switch ($provider) {
            case 'dpo':
                if (empty($settings->dpo_company_token)) return null;
                return new DpoGateway($settings->dpo_company_token, $settings->dpo_service_type ?? '');
            case 'lenco':
                if (empty($settings->lenco_api_key)) return null;
                return new LencoGateway($settings->lenco_api_key);
            case 'paystack':
                if (empty($settings->paystack_secret_key)) return null;
                return new PaystackGateway($settings->paystack_secret_key);
            case 'flutterwave':
                if (empty($settings->flutterwave_secret_key)) return null;
                return new FlutterwaveGateway($settings->flutterwave_secret_key);
            default:
                return null;
        }
    }
}
