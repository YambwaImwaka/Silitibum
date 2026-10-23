<?php

namespace App\Http\Controllers;

use App\Models\CoinPackages;
use App\Models\Constants;
use App\Models\CreatorProfile;
use App\Models\Gifts;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Models\Posts;
use App\Models\RedeemRequests;
use App\Models\Users;
use App\Models\WalletTransaction;
use App\Services\Payments\GatewayResult;
use App\Services\Payments\MobileMoney;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\Payments\RevenueCatCoinSync;
use App\Services\Wallet\CreatorProgram;
use App\Services\Wallet\Money;
use App\Services\Wallet\WalletException;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\Withdrawals;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

use function Psy\debug;

class WalletController extends Controller
{
    // Called by the app right after a store purchase. Credits whatever coin
    // purchases RevenueCat holds for this user that are not credited yet —
    // the same sync the RevenueCat webhook runs, so a purchase is credited
    // exactly once whichever arrives first, and one whose app call never
    // happened (crash, lost network) is still credited by the webhook.
    // coin_package_id / purchased_at are still accepted from older builds but
    // are no longer trusted for anything.
    public function buyCoins(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        $result = RevenueCatCoinSync::syncCustomer((int) $user->id);
        if ($result === null) {
            return GlobalFunction::sendSimpleResponse(false, 'we could not confirm your purchase yet — your coins will be added automatically');
        }

        $user = GlobalFunction::prepareUserFullData($user->id);
        return GlobalFunction::sendDataResponse(true, $result['credited'] > 0
            ? 'coins purchased successfully'
            : 'your coins will appear shortly', $user);
    }

    // Free coins from an admin (a giveaway, a goodwill gesture). They carry
    // no cost, so gifting them pays creators nothing — a creator who is owed
    // money gets it through adjustUserEarnings_FromAdmin instead.
    public function addCoinsToUserWallet_FromAdmin(Request $request){
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:tbl_users,id',
            'coins' => 'required|integer|not_in:0|min:-10000000|max:10000000',
            'reason' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return GlobalFunction::sendSimpleResponse(false, $validator->errors()->first());
        }
        $coins = (int) $request->coins;
        $meta = ['admin' => self::adminName(), 'reason' => $request->reason];
        if ($coins > 0) {
            WalletLedger::creditCoins((int) $request->user_id, $coins, 0, WalletLedger::TYPE_ADMIN_ADJUSTMENT, null, [], $meta);
        } else {
            WalletLedger::debitCoins((int) $request->user_id, -$coins, WalletLedger::TYPE_ADMIN_ADJUSTMENT, null, [], $meta);
        }

        return GlobalFunction::sendSimpleResponse(true, 'Coins updated successfully!');
    }

    // Moves a creator's withdrawable earnings by hand: paying out a
    // correction, or taking back earnings from proven fraud.
    public function adjustUserEarnings_FromAdmin(Request $request){
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:tbl_users,id',
            'amount' => ['required', 'regex:/^-?\d{1,9}(\.\d{1,2})?$/'],
            'reason' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return GlobalFunction::sendSimpleResponse(false, $validator->errors()->first());
        }
        $units = Money::toUnits((string) $request->amount);
        if ($units === 0) {
            return GlobalFunction::sendSimpleResponse(false, 'enter a non-zero amount');
        }
        $meta = ['admin' => self::adminName(), 'reason' => $request->reason];
        if ($units > 0) {
            WalletLedger::creditEarnings((int) $request->user_id, $units, WalletLedger::TYPE_ADMIN_ADJUSTMENT, null, [], $meta);
        } else {
            $error = WalletLedger::debitEarnings((int) $request->user_id, -$units, WalletLedger::TYPE_ADMIN_ADJUSTMENT, null, [], $meta, false);
            if ($error !== null) {
                return GlobalFunction::sendSimpleResponse(false, 'the creator\'s earnings balance is smaller than that');
            }
        }

        return GlobalFunction::sendSimpleResponse(true, 'Earnings updated successfully!');
    }

    public function listCoinPackages(Request $request)
    {
        $query = CoinPackages::query();
        $totalData = $query->count();

        $limit = $request->input('length');
        $start = $request->input('start');
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('coin_amount', 'LIKE', "%{$searchValue}%")
                ->orwhere('coin_plan_price', 'LIKE', "%{$searchValue}%")
                ->orwhere('playstore_product_id', 'LIKE', "%{$searchValue}%")
                ->orwhere('appstore_product_id', 'LIKE', "%{$searchValue}%");
            });
        }
        $totalFiltered = $query->count();

        $result = $query->offset($start)
                        ->limit($limit)
                        ->orderBy('id', 'DESC')
                        ->get();

        $settings = GlobalSettings::first();
        $data = $result->map(function ($item) use($settings) {

            $imgUrl = GlobalFunction::generateFileUrl($item->image);
            $image = "<img class='rounded' width='80' height='80' src='{$imgUrl}' alt=''>";

            $edit = "<a href='#'
                        rel='{$item->id}'
                        data-coinamount='{$item->coin_amount}'
                        data-coinprice='{$item->coin_plan_price}'
                        data-playstoreid='{$item->playstore_product_id}'
                        data-appstoreid='{$item->appstore_product_id}'
                        data-image='{$imgUrl}'
                        class='action-btn edit d-flex align-items-center justify-content-center btn border rounded-2 text-success ms-1'>
                        <i class='uil-pen'></i>
                        </a>";

            $delete = "<a href='#'
                          rel='{$item->id}'
                          class='action-btn delete d-flex align-items-center justify-content-center btn border rounded-2 text-danger ms-1'>
                            <i class='uil-trash-alt'></i>
                        </a>";
            $action = "<span class='d-flex justify-content-end align-items-center'>{$edit}{$delete}</span>";

            $checked = $item->status == 1 ? 'checked' : '';
            $status = "<input type='checkbox' id='coinPackageStatus-{$item->id}' rel='{$item->id}' class='onOffCoinPackage' {$checked} data-switch='none'/>
                    <label for='coinPackageStatus-{$item->id}'></label>";

            return [
                $image,
                $item->coin_amount,
                $settings->currency.$item->coin_plan_price,
                $status,
                $item->playstore_product_id,
                $item->appstore_product_id,
                $action
            ];
        });

        $json_data = [
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data,
        ];

        return response()->json($json_data);
    }

    function changeCoinPackageStatus(Request $request){
        $coinPackage = CoinPackages::find($request->id);
        $coinPackage->status = $request->status;
        $coinPackage->save();

        return GlobalFunction::sendSimpleResponse(true, 'Status changed successfully!');
    }

    function editCoinPackage(Request $request){
        $item = CoinPackages::find($request->id);
        if($request->has('image')){
            if($item->image != null){
                GlobalFunction::deleteFile($item->image);
            }
            $item->image = GlobalFunction::saveFileAndGivePath($request->image);
        }
        $item->coin_amount = $request->coin_amount;
        $item->coin_plan_price = $request->coin_plan_price;
        $item->appstore_product_id = $request->appstore_product_id;
        $item->playstore_product_id = $request->playstore_product_id;
        $item->save();

        return GlobalFunction::sendSimpleResponse(true, 'Coin package edited successfully');
    }
    function addCoinPackage(Request $request){
        $item = new CoinPackages();
        $item->image = GlobalFunction::saveFileAndGivePath($request->image);
        $item->coin_amount = $request->coin_amount;
        $item->coin_plan_price = $request->coin_plan_price;
        $item->appstore_product_id = $request->appstore_product_id;
        $item->playstore_product_id = $request->playstore_product_id;
        $item->save();

        return GlobalFunction::sendSimpleResponse(true, 'Coin package added successfully');
    }
    function deleteCoinPackage(Request $request){
        $item = CoinPackages::find($request->id);
        GlobalFunction::deleteFile($item->image);
        $item->delete();

        return GlobalFunction::sendSimpleResponse(true, 'Coin package deleted successfully');
    }

    public function coinPackages(){
        return view('coinPackages');
    }
    private static function adminName(): ?string
    {
        return Session::get('username');
    }

    public function rejectWithdrawal(Request $request){
        $item = RedeemRequests::find($request->id);
        if ($item == null) {
            return GlobalFunction::sendSimpleResponse(false, 'withdrawal not found');
        }
        // An automated payout that is still Processing may yet complete at the
        // provider; refunding it now would pay the user twice. Recheck asks the
        // provider, and a provider-confirmed failure refunds automatically.
        if ($item->status == Constants::withdrawalProcessing && !Withdrawals::isManual($item)) {
            return GlobalFunction::sendSimpleResponse(false, 'this payout is still in progress at ' . $item->provider . ' — use Recheck; a confirmed failure is refunded automatically');
        }
        // Claimed by the status write itself; only the click that moved it
        // refunds (a double-click used to refund once per click).
        if (!Withdrawals::reject($item, $request->reason ?: null, self::adminName())) {
            return GlobalFunction::sendSimpleResponse(false, 'this withdrawal is already closed');
        }
        return GlobalFunction::sendSimpleResponse(true,'withdrawal rejected — the amount is back in the creator\'s earnings');
    }

    public function completeWithdrawal(Request $request){
        $item = RedeemRequests::find($request->id);
        if ($item == null || !Withdrawals::complete($item, self::adminName())) {
            return GlobalFunction::sendSimpleResponse(false, 'this withdrawal is already closed');
        }
        return GlobalFunction::sendSimpleResponse(true,'withdrawal marked as paid');
    }

    // Pending -> sent through the active automated provider. Manual payouts
    // never come here: the admin pays by hand and uses Mark paid.
    public function approveWithdrawal(Request $request){
        $item = RedeemRequests::find($request->id);
        if ($item == null || $item->status != Constants::withdrawalPending) {
            return GlobalFunction::sendSimpleResponse(false, 'only a pending withdrawal can be approved');
        }
        $settings = GlobalSettings::first();
        $gateway = PaymentGatewayFactory::active();
        if ($gateway == null || ($settings->active_payout_provider ?? 'manual') === 'manual') {
            return GlobalFunction::sendSimpleResponse(false, 'no automated payout provider is active — pay by hand and use Mark paid');
        }
        $claimed = RedeemRequests::where('id', $item->id)
            ->where('status', Constants::withdrawalPending)
            ->update([
                'status' => Constants::withdrawalProcessing,
                'provider' => $settings->active_payout_provider,
                'processed_by' => self::adminName(),
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return GlobalFunction::sendSimpleResponse(false, 'this withdrawal was already handled');
        }
        $item->refresh();
        $result = $this->sendPayout($item, $gateway, $settings);

        return GlobalFunction::sendSimpleResponse($result['status'] !== 'failed', match ($result['status']) {
            'completed' => 'payout sent',
            'failed' => 'the provider refused this payout — the amount is back in the creator\'s earnings',
            default => 'payout submitted; it will complete when the provider confirms',
        });
    }

    // Manual "check now" for a Processing request — in case a webhook never
    // arrived. Same server-side re-verify used by PaymentWebhookController,
    // just triggered by an admin click instead of a provider callback.
    public function recheckWithdrawal(Request $request){
        $item = RedeemRequests::find($request->id);
        if ($item == null || $item->status != Constants::withdrawalProcessing) {
            return GlobalFunction::sendSimpleResponse(false, 'nothing to recheck');
        }
        $settings = GlobalSettings::first();
        $gateway = PaymentGatewayFactory::forProvider($item->provider, $settings);
        if ($gateway == null) {
            return GlobalFunction::sendSimpleResponse(false, 'that request\'s provider is not currently configured');
        }
        Withdrawals::applyProviderStatus($item, $gateway->verifyPayout($item->provider_reference ?? $item->request_number));
        $item->refresh();

        return GlobalFunction::sendSimpleResponse(true, 'rechecked — status is now ' . $item->status);
    }

    // Re-attempts a Failed request through the currently active provider
    // (which may differ from whichever provider failed the first time).
    // Takes the amount out of the creator's earnings again — it went back in
    // when the attempt failed.
    public function retryWithdrawal(Request $request){
        $item = RedeemRequests::find($request->id);
        if ($item == null || $item->status != Constants::withdrawalFailed || $item->user_id === null) {
            return GlobalFunction::sendSimpleResponse(false, 'nothing to retry');
        }
        $settings = GlobalSettings::first();
        $gateway = PaymentGatewayFactory::active();
        if ($gateway == null) {
            return GlobalFunction::sendSimpleResponse(false, 'no active payout provider configured');
        }

        try {
            $error = DB::transaction(function () use ($item, $settings) {
                $claimed = RedeemRequests::where('id', $item->id)
                    ->where('status', Constants::withdrawalFailed)
                    ->update([
                        'status' => Constants::withdrawalProcessing,
                        'provider' => $settings->active_payout_provider,
                        'processed_by' => self::adminName(),
                        'updated_at' => now(),
                    ]);
                if ($claimed !== 1) {
                    return 'nothing to retry';
                }
                // Keyed to the attempt about to start, so its refund (same key
                // shape) can only ever balance this debit.
                $error = WalletLedger::debitEarnings(
                    (int) $item->user_id,
                    Money::toUnits((string) $item->amount),
                    WalletLedger::TYPE_WITHDRAWAL,
                    'wd:' . $item->id . ':' . ((int) $item->attempt + 1),
                    ['redeem_request_id' => $item->id],
                    ['retry' => true],
                    false
                );
                if ($error !== null) {
                    // Roll the status claim back too.
                    throw new WalletException('the creator no longer has enough earnings to retry this withdrawal');
                }
                return null;
            });
        } catch (WalletException $e) {
            $error = $e->getMessage();
        }
        if ($error !== null) {
            return GlobalFunction::sendSimpleResponse(false, $error);
        }
        $item->refresh();
        $result = $this->sendPayout($item, $gateway, $settings);

        return GlobalFunction::sendSimpleResponse($result['status'] !== 'failed', $result['status'] === 'failed'
            ? 'retry failed — the amount is back in the creator\'s earnings'
            : 'retry submitted');
    }

    // One provider attempt for a request already claimed into Processing.
    // Every attempt sends its own reference, saved BEFORE the call: a webhook
    // that beats the HTTP response still finds the row, and a retry never
    // re-sends a reference the provider has already seen (Lenco rejects those
    // as "Duplicate reference", so retries used to be dead on arrival).
    private function sendPayout(RedeemRequests $item, $gateway, $settings): array
    {
        $item->attempt = (int) $item->attempt + 1;
        $item->provider_reference = $item->request_number . '-' . $item->attempt;
        $item->save();

        try {
            $result = $gateway->payout($item->account, (float) $item->amount, $settings->currency_code ?? 'ZMW', $item->provider_reference);
        } catch (\Throwable $e) {
            // Not a failure: the transfer may have gone out. It stays
            // Processing until the provider confirms either way.
            Log::error('sendPayout: gateway payout threw: ' . $e->getMessage());
            $result = GatewayResult::unknown($item->provider_reference, 'gateway_exception');
        }

        // provider_reference stays OUR reference (what webhooks and status
        // checks are keyed on); the provider's own id is kept for support.
        $txn = $result['provider_reference'] ?? null;
        $updates = [];
        if ($txn !== null && $txn !== $item->provider_reference) {
            $updates['provider_txn_id'] = $txn;
        }
        if (isset($result['fee'])) {
            $updates['fee'] = $result['fee'];
        }
        if ($updates) {
            RedeemRequests::where('id', $item->id)->update($updates);
        }

        Withdrawals::applyProviderStatus($item, $result);
        return $result;
    }
    public function deleteGift(Request $request){
        $item = Gifts::find($request->id);
        GlobalFunction::deleteFile($item->image);
        $item->delete();

        return GlobalFunction::sendSimpleResponse(true, 'gift deleted successfully');
    }
    public function listCompletedWithdrawals(Request $request)
    {
        return $this->listWithdrawals($request, Constants::withdrawalCompleted);
    }
    public function listRejectedWithdrawals(Request $request)
    {
        return $this->listWithdrawals($request, Constants::withdrawalRejected);
    }
    public function listPendingWithdrawals(Request $request)
    {
        return $this->listWithdrawals($request, Constants::withdrawalPending);
    }
    public function listProcessingWithdrawals(Request $request)
    {
        return $this->listWithdrawals($request, Constants::withdrawalProcessing);
    }
    public function listFailedWithdrawals(Request $request)
    {
        return $this->listWithdrawals($request, Constants::withdrawalFailed);
    }

    // One renderer for all five withdrawal tabs (they used to be five copies).
    // Columns: request, creator, amount, payout details, date, actions.
    private function listWithdrawals(Request $request, int $status)
    {
        $query = RedeemRequests::where('status', $status);
        $totalData = $query->count();

        $searchValue = $request->input('search.value');
        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('request_number', 'LIKE', "%{$searchValue}%")
                    ->orWhere('account', 'LIKE', "%{$searchValue}%");
            });
        }
        $totalFiltered = $query->count();

        $result = $query->offset($request->input('start'))
            ->limit($request->input('length'))
            ->orderBy('id', 'DESC')
            ->get();

        $settings = GlobalSettings::first();
        $automatedActive = !empty($settings->active_payout_provider) && $settings->active_payout_provider !== 'manual';
        $colour = [
            Constants::withdrawalCompleted => 'text-success',
            Constants::withdrawalRejected => 'text-danger',
            Constants::withdrawalFailed => 'text-danger',
        ][$status] ?? 'text-primary';

        $data = $result->map(function ($item) use ($settings, $status, $automatedActive, $colour) {
            $user = GlobalFunction::createUserDetailsColumn($item->user_id)
                ?: "<span class='text-muted fs-6'>" . __('Deleted account') . "</span>";

            $amount = "<h4 class='text-primary m-0'>" . e($settings->currency) . ' ' . number_format((float) $item->amount, 2) . "</h4>";
            if ((int) $item->coins > 0) {
                // Requests from before the earnings wallet were made in coins.
                $amount .= "<span class='fs-6'>" . __('Coins') . ": {$item->coins} × {$item->coin_value}</span>";
            } else {
                $amount .= "<span class='fs-6 text-muted'>" . __('from earnings') . "</span>";
            }
            if ($item->fee !== null) {
                $amount .= "<br><span class='fs-6 text-muted'>" . __('Provider fee') . ': ' . e($item->fee) . "</span>";
            }
            if (!empty($item->reason)) {
                $amount .= "<br><span class='fs-6 text-danger'>" . e($item->reason) . "</span>";
            }

            $network = $item->operator ? MobileMoney::label($item->operator) : ($item->gateway ?: '');
            $paymentDetails = "<span class='badge badge-info-lighten fs-6'>" . e($network) . "</span>"
                . "<br><span class='fs-6'>" . e($item->account) . "</span>";
            if (!empty($item->provider) && $item->provider !== 'manual') {
                $paymentDetails .= "<br><span class='fs-6 text-muted'>" . e($item->provider) . ' ref: ' . e($item->provider_reference) . "</span>";
            }
            if (!empty($item->processed_by)) {
                $paymentDetails .= "<br><span class='fs-6 text-muted'>" . __('by') . ' ' . e($item->processed_by) . "</span>";
            }

            $requestNumber = "<h5 class='{$colour}'>#" . e($item->request_number) . "</h5>";

            $row = [$requestNumber, $user, $amount, $paymentDetails, GlobalFunction::formateDatabaseTime($item->created_at)];
            // The Completed and Rejected tables have no action column.
            if (!in_array($status, [Constants::withdrawalCompleted, Constants::withdrawalRejected], true)) {
                $row[] = $this->withdrawalActions($item, $status, $automatedActive);
            }
            return $row;
        });

        return response()->json([
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data,
        ]);
    }

    private function withdrawalActions(RedeemRequests $item, int $status, bool $automatedActive): string
    {
        $button = fn (string $class, string $icon, string $tone, string $title) =>
            "<a href='#' rel='{$item->id}' title='{$title}'
                class='action-btn {$class} d-flex align-items-center justify-content-center btn border rounded-2 {$tone} ms-1'>
                <i class='{$icon}'></i></a>";

        $buttons = [];
        if ($status == Constants::withdrawalPending) {
            // With an automated provider the money is sent by Approve; with
            // the manual provider the admin pays by hand, then marks it paid.
            $buttons[] = $automatedActive
                ? $button('approve', 'uil-message', 'text-success', 'Approve and send now')
                : $button('complete', 'uil-check', 'text-success', 'I have sent this payout');
            $buttons[] = $button('reject', 'uil-times', 'text-danger', 'Reject and return to earnings');
        } elseif ($status == Constants::withdrawalProcessing) {
            // A manual payout has no provider to ask, so Recheck can never
            // move it — the admin who sent the money closes it.
            if (Withdrawals::isManual($item)) {
                $buttons[] = $button('complete', 'uil-check', 'text-success', 'I have sent this payout');
                $buttons[] = $button('reject', 'uil-times', 'text-danger', 'Reject and return to earnings');
            } else {
                $buttons[] = $button('recheck', 'uil-sync', 'text-info', 'Check status now');
            }
        } elseif ($status == Constants::withdrawalFailed && $item->user_id !== null) {
            $buttons[] = $button('retry', 'uil-redo', 'text-success', 'Retry via the currently active provider');
        }
        return $buttons ? "<span class='d-flex justify-content-end align-items-center'>" . implode('', $buttons) . "</span>" : '';
    }
    public function withdrawals(){
        return view('withdrawals');
    }
    public function gifts(){
        $gifts = Gifts::all();
        $baseUrl = GlobalFunction::getItemBaseUrl();
        return view('gifts' ,compact('gifts','baseUrl'));
    }

    public function editGift(Request $request)
    {
        $item = Gifts::find($request->id);
        $item->coin_price = $request->coin_price;
        if($request->has('image')){
            GlobalFunction::deleteFile($item->image);
            $item->image = GlobalFunction::saveFileAndGivePath($request->image);
        }
        $item->save();

        return response()->json([
            'status' => true,
            'message' => 'Gift Added Successfully',
        ]);
    }
    public function addGift(Request $request)
    {
        $item = new Gifts();
        $item->coin_price = $request->coin_price;
        $item->image = GlobalFunction::saveFileAndGivePath($request->image);
        $item->save();

        return response()->json([
            'status' => true,
            'message' => 'Gift Added Successfully',
        ]);
    }
    public function fetchMyWithdrawalRequest(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $rules = [
            'limit' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }

        $query = RedeemRequests::where('user_id', $user->id)
        ->orderBy('id', 'DESC')
        ->limit($request->limit);
        if($request->has('last_item_id')){
            $query->where('id','<',$request->last_item_id);
        }

        $redeemRequests =  $query->get();

        return GlobalFunction::sendDataResponse(true, 'Withdrawal requests fetched successfully', $redeemRequests);

    }
    // A creator asks for some of their earnings (K) to be sent to their
    // registered mobile-money account (CreatorProgram). Nothing goes to a
    // provider here: the request waits for an admin (Mark paid for hand
    // payouts, Approve for an automated provider), so a person sees every
    // payout before money leaves.
    public function submitWithdrawalRequest(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        // App versions before the earnings wallet send `coins` and show
        // spendable coins as the balance — coins can no longer be cashed out.
        if (!$request->filled('amount')) {
            return GlobalFunction::sendSimpleResponse(false, 'Please update Silitibum to withdraw your earnings.');
        }
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $settings = GlobalSettings::first();
        if (($settings->is_withdrawal_on ?? 1) == 0) {
            return GlobalFunction::sendSimpleResponse(false, 'withdrawals are currently disabled');
        }
        $amountUnits = Money::toUnits((string) $request->amount);
        $minUnits = Money::toUnits((string) ($settings->min_withdrawal_amount ?? '50'));
        if ($amountUnits < $minUnits || $amountUnits <= 0) {
            return GlobalFunction::sendSimpleResponse(false, 'the minimum withdrawal is ' . $settings->currency . Money::format($minUnits));
        }

        // The destination is the creator's registered account — never a
        // number sent with the request, which is what let a stolen session
        // send a creator's balance anywhere.
        $profile = CreatorProfile::find($user->id);
        $eligibility = CreatorProgram::eligibility($user, $profile);
        if (!$eligibility['eligible']) {
            $blocking = collect($eligibility['checks'])->firstWhere('ok', false);
            return GlobalFunction::sendDataResponse(false, 'You can not withdraw yet: ' . $blocking['label'], ['eligibility' => $eligibility]);
        }

        $maxPerWeek = max(1, (int) ($settings->max_withdrawals_per_week ?? 1));
        try {
            $redeem = DB::transaction(function () use ($user, $settings, $amountUnits, $profile, $maxPerWeek) {
                // Locking the user row first makes the checks below and the
                // debit one atomic step: two taps on Submit cannot both pass.
                DB::table('tbl_users')->where('id', $user->id)->lockForUpdate()->first(['id']);
                $open = RedeemRequests::where('user_id', $user->id)->whereIn('status', Withdrawals::OPEN)->exists();
                if ($open) {
                    throw new WalletException('you already have a withdrawal in progress');
                }
                $thisWeek = RedeemRequests::where('user_id', $user->id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->whereNotIn('status', [Constants::withdrawalRejected, Constants::withdrawalFailed])
                    ->count();
                if ($thisWeek >= $maxPerWeek) {
                    throw new WalletException("you can withdraw {$maxPerWeek} time(s) per week");
                }

                $redeem = new RedeemRequests();
                $redeem->request_number = GlobalFunction::generateRedeemRequestNumber($user->id);
                $redeem->user_id = $user->id;
                $redeem->operator = $profile->payout_operator;
                $redeem->gateway = MobileMoney::label($profile->payout_operator);
                $redeem->account = $profile->payout_phone;
                $redeem->provider = $settings->active_payout_provider ?: 'manual';
                $redeem->coins = 0;
                $redeem->coin_value = 0;
                $redeem->amount = Money::format($amountUnits);
                $redeem->status = Constants::withdrawalPending;
                $redeem->save();

                $error = WalletLedger::debitEarnings(
                    (int) $user->id,
                    $amountUnits,
                    WalletLedger::TYPE_WITHDRAWAL,
                    'wd:' . $redeem->id,
                    ['redeem_request_id' => $redeem->id]
                );
                if ($error !== null) {
                    throw new WalletException($error === 'insufficient earnings'
                        ? 'you do not have that much available to withdraw yet'
                        : $error);
                }
                return $redeem;
            });
        } catch (WalletException $e) {
            return GlobalFunction::sendSimpleResponse(false, $e->getMessage());
        }

        return GlobalFunction::sendDataResponse(true, 'Withdrawal requested. ' . $settings->currency . $redeem->amount . ' will be sent to ' . $redeem->account . ' once it is approved.', [
            'withdrawal' => $redeem->fresh(),
            'wallet' => WalletLedger::summary((int) $user->id),
        ]);
    }

    // Everything the app's earnings screen needs in one call: balances, the
    // creator share, the payout account (masked) and the withdrawal checklist.
    public function fetchCreatorStatus(Request $request){
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        $settings = GlobalSettings::first();
        $profile = CreatorProfile::find($user->id);

        return GlobalFunction::sendDataResponse(true, 'creator status fetched successfully', [
            'wallet' => WalletLedger::summary((int) $user->id),
            'creator_share_percent' => WalletLedger::sharePercent(),
            'currency' => $settings->currency,
            'min_withdrawal_amount' => Money::format(Money::toUnits((string) ($settings->min_withdrawal_amount ?? '50'))),
            'earnings_hold_days' => WalletLedger::holdDays(),
            'max_withdrawals_per_week' => max(1, (int) ($settings->max_withdrawals_per_week ?? 1)),
            'withdrawals_enabled' => ($settings->is_withdrawal_on ?? 1) == 1,
            'operators' => collect(MobileMoney::OPERATORS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'payout_account' => CreatorProgram::publicProfile($profile),
            'eligibility' => CreatorProgram::eligibility($user, $profile),
            'open_withdrawal' => RedeemRequests::where('user_id', $user->id)
                ->whereIn('status', Withdrawals::OPEN)
                ->first(['id', 'request_number', 'amount', 'status', 'created_at']),
        ]);
    }

    // Registers (or changes) where the creator's withdrawals are sent.
    public function savePayoutAccount(Request $request){
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $validator = Validator::make($request->all(), [
            'legal_name' => 'required|string|max:150',
            'phone' => ['required', 'regex:/^\+?[0-9]{8,15}$/'],
            'operator' => 'required|in:' . implode(',', array_keys(MobileMoney::OPERATORS)),
            // Payouts are for adults; the creator states it here.
            'confirm_adult' => 'accepted',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $profile = CreatorProgram::savePayoutAccount($user, (string) $request->legal_name, (string) $request->phone, (string) $request->operator);
        if (is_string($profile)) {
            return GlobalFunction::sendSimpleResponse(false, $profile);
        }

        $message = match ($profile->name_match) {
            'match' => 'Payout account saved and verified.',
            'mismatch' => 'Saved, but this mobile money account does not appear to be registered in your name. Use a number registered to you, or contact support.',
            default => 'Payout account saved.',
        };
        return GlobalFunction::sendDataResponse(true, $message, [
            'payout_account' => CreatorProgram::publicProfile($profile),
            'eligibility' => CreatorProgram::eligibility($user, $profile),
        ]);
    }

    // The creator's own money history: every gift received (with who sent
    // it and which video or live earned it), withdrawal, refund and
    // adjustment, newest first, plus the current wallet numbers.
    public function fetchWalletTransactions(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        $validator = Validator::make($request->all(), [
            'limit' => 'required|integer|min:1',
            'last_item_id' => 'nullable|integer',
            'account' => 'nullable|in:coins,earnings',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $query = WalletTransaction::where('user_id', $user->id)->orderBy('id', 'DESC')->limit(min(50, (int) $request->limit));
        if ($request->filled('last_item_id')) {
            $query->where('id', '<', (int) $request->last_item_id);
        }
        if ($request->filled('account')) {
            $query->where('account', $request->account);
        }
        $rows = $query->get();

        $people = Users::whereIn('id', $rows->pluck('counterparty_user_id')->filter()->unique())
            ->get(['id', 'username', 'fullname', 'profile_photo', 'is_verify'])->keyBy('id');
        $gifts = Gifts::whereIn('id', $rows->pluck('gift_id')->filter()->unique())->get(['id', 'image', 'coin_price'])->keyBy('id');
        $posts = Posts::withTrashed()->whereIn('id', $rows->pluck('post_id')->filter()->unique())
            ->get(['id', 'post_type', 'thumbnail', 'description'])->keyBy('id');

        $items = $rows->map(function (WalletTransaction $tx) use ($people, $gifts, $posts) {
            $meta = $tx->metaArray();
            $post = $tx->post_id ? $posts->get($tx->post_id) : null;
            return [
                'id' => $tx->id,
                'account' => $tx->account,
                'type' => $tx->type,
                'coins' => (int) $tx->coins,
                'amount' => Money::format(Money::toUnits((string) $tx->amount)),
                'balance_after_coins' => $tx->balance_after_coins,
                'balance_after_amount' => $tx->balance_after_amount !== null ? Money::format(Money::toUnits((string) $tx->balance_after_amount)) : null,
                'created_at' => $tx->created_at,
                'counterparty' => $tx->counterparty_user_id ? $people->get($tx->counterparty_user_id) : null,
                'gift' => $tx->gift_id ? $gifts->get($tx->gift_id) : null,
                'post' => $post ? [
                    'id' => $post->id,
                    'post_type' => $post->post_type,
                    'thumbnail' => $post->thumbnail,
                    'description' => mb_substr((string) $post->description, 0, 80),
                ] : null,
                'livestream_id' => $tx->livestream_id,
                'redeem_request_id' => $tx->redeem_request_id,
                // An admin's reason is the creator's business; who did it isn't.
                'note' => $tx->type === WalletLedger::TYPE_ADMIN_ADJUSTMENT ? ($meta['reason'] ?? null) : null,
            ];
        });

        return GlobalFunction::sendDataResponse(true, 'wallet transactions fetched successfully', [
            'wallet' => WalletLedger::summary((int) $user->id),
            'creator_share_percent' => WalletLedger::sharePercent(),
            'transactions' => $items,
        ]);
    }

    // Admin: one user's ledger on their details page.
    public function listUserWalletTransactions(Request $request)
    {
        $query = WalletTransaction::where('user_id', (int) $request->user_id);
        $totalData = $query->count();
        $rows = $query->orderBy('id', 'DESC')
            ->offset($request->input('start'))
            ->limit($request->input('length'))
            ->get();
        $settings = GlobalSettings::first();

        $data = $rows->map(function (WalletTransaction $tx) use ($settings) {
            $meta = $tx->metaArray();
            $isCoins = $tx->account === WalletLedger::ACCOUNT_COINS;
            $amount = Money::toUnits((string) $tx->amount);
            $movement = $isCoins
                ? sprintf('%+d coins', (int) $tx->coins) . "<br><span class='text-muted fs-6'>" . __('value') . ' ' . e($settings->currency) . Money::format($amount) . "</span>"
                : "<span class='" . ($amount < 0 ? 'text-danger' : 'text-success') . "'>" . e($settings->currency) . Money::format($amount) . "</span>";
            $after = $isCoins
                ? ((int) $tx->balance_after_coins) . ' coins'
                : ($tx->balance_after_amount !== null ? e($settings->currency) . Money::format(Money::toUnits((string) $tx->balance_after_amount)) : '');

            $details = [];
            if ($tx->counterparty_user_id) {
                $details[] = __('with user') . ' #' . (int) $tx->counterparty_user_id;
            }
            if ($tx->post_id) {
                $details[] = __('post') . ' #' . (int) $tx->post_id;
            }
            if ($tx->livestream_id) {
                $details[] = __('live') . ' #' . (int) $tx->livestream_id;
            }
            if ($tx->redeem_request_id) {
                $details[] = __('withdrawal') . ' #' . (int) $tx->redeem_request_id;
            }
            if (!empty($meta['reason'])) {
                $details[] = e($meta['reason']) . (!empty($meta['admin']) ? ' (' . e($meta['admin']) . ')' : '');
            }

            return [
                GlobalFunction::formateDatabaseTime($tx->created_at),
                "<span class='badge bg-secondary'>" . e(str_replace('_', ' ', $tx->type)) . "</span>",
                $movement,
                $after,
                implode('<br>', $details),
            ];
        });

        return response()->json([
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalData),
            "data" => $data,
        ]);
    }

    public function sendGift(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $rules = [
            'user_id' => 'required|exists:tbl_users,id',
            'gift_id' => 'required|exists:tbl_gifts,id',
            // The reel/post the gift was sent on, so the creator can see what
            // each video earned. Optional: chat and profile gifts have none.
            'post_id' => 'nullable|integer',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $dataUser = Users::find($request->user_id);
        $gift = Gifts::find($request->gift_id);
        $context = [];
        if ($request->filled('post_id')) {
            // Only credit the video to its own author; anything else is noise.
            $postOwner = Posts::where('id', (int) $request->post_id)->value('user_id');
            if ($postOwner !== null && (int) $postOwner === (int) $dataUser->id) {
                $context['post_id'] = (int) $request->post_id;
            }
        }
        $error = GlobalFunction::transferGiftCoins($user, $dataUser, $gift, $context);
        if ($error !== null) {
            return GlobalFunction::sendSimpleResponse(false, $error);
        }

        return GlobalFunction::sendSimpleResponse(true, 'gift sent successfully!');
    }
}
