<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mobile-money payout/collection providers. One row (tbl_settings, same
// single-row table as every other admin setting) holds every provider's
// credentials plus which one is currently active — mirrors the existing
// is_content_moderation/sight_engine_* pattern. Switching providers is an
// admin dropdown change, not a deploy: see PaymentGatewayFactory::active().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_settings', function (Blueprint $table) {
            $table->string('active_payout_provider', 50)->nullable();
            // Separate from the payout provider on purpose — confirmed while
            // building the drivers that not every provider does both sides
            // well (DPO: collections only, no payout API at all; Lenco:
            // payout-shaped but bank-transfer only, no collections API).
            // Picking one provider for both would silently break whichever
            // side that provider doesn't actually support.
            $table->string('active_collection_provider', 50)->nullable();

            $table->string('dpo_company_token', 191)->nullable();
            $table->string('dpo_service_type', 191)->nullable();

            $table->string('lenco_api_key', 191)->nullable();

            $table->string('paystack_secret_key', 191)->nullable();
            $table->string('paystack_public_key', 191)->nullable();

            $table->string('flutterwave_secret_key', 191)->nullable();
        });

        // No "processing"/"failed" states existed before automated payouts —
        // completeWithdrawal/rejectWithdrawal were the only two admin
        // actions on a Pending request. An async provider call needs a
        // third and fourth state in between.
        Schema::table('tbl_redeem_request', function (Blueprint $table) {
            $table->string('provider', 50)->nullable()->after('account');
            $table->string('provider_reference', 191)->nullable()->after('provider');
        });

        // Web mobile-money coin top-ups (routed here instead of the app to
        // stay clear of Apple/Google's in-app-purchase requirement — see
        // TopUpController). Mirrors tbl_redeem_request's shape: a row is
        // created before the charge is initiated so the webhook has
        // something to reconcile against, exactly like RedeemRequests does
        // for payouts.
        Schema::create('tbl_coin_topup_request', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('coin_package_id');
            $table->integer('coins');
            $table->decimal('amount', 12, 2);
            $table->string('phone', 20);
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 191)->nullable();
            // pending: created, charge not yet initiated with the provider
            // processing: provider push sent, awaiting customer PIN approval
            // completed: wallet credited (terminal, only set once)
            // failed: charge failed or was never confirmed
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamps();
            $table->index('user_id');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_settings', function (Blueprint $table) {
            $table->dropColumn([
                'active_payout_provider',
                'active_collection_provider',
                'dpo_company_token',
                'dpo_service_type',
                'lenco_api_key',
                'paystack_secret_key',
                'paystack_public_key',
                'flutterwave_secret_key',
            ]);
        });

        Schema::table('tbl_redeem_request', function (Blueprint $table) {
            $table->dropColumn(['provider', 'provider_reference']);
        });

        Schema::dropIfExists('tbl_coin_topup_request');
    }
};
