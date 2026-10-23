<?php

namespace App\Http\Controllers;

use App\Models\CreatorProfile;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

// Admin side of creator earnings: the economics settings (creator share,
// withdrawal rules) and decisions on individual creators' payout accounts.
// Every route here also sits behind the fullAdmin middleware.
class MonetizationController extends Controller
{
    public function saveMonetizationSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // What creators keep of what fans spend on gifts. The rest pays the
            // store/mobile-money fees, payouts, streaming and storage.
            'creator_share_percent' => 'required|integer|min:1|max:100',
            'min_withdrawal_amount' => 'required|numeric|min:1|max:100000',
            'earnings_hold_days' => 'required|integer|min:0|max:60',
            'max_withdrawals_per_week' => 'required|integer|min:1|max:14',
            'creator_min_account_age_days' => 'required|integer|min:0|max:365',
            'payout_change_hold_hours' => 'required|integer|min:0|max:720',
            'lenco_account_id' => 'nullable|string|max:64',
            // The blue tick sold at /verify (BlueTick).
            'verification_price' => 'required|numeric|min:1|max:100000',
            'verification_days' => 'required|integer|min:0|max:3650',
        ]);
        if ($validator->fails()) {
            return GlobalFunction::sendSimpleResponse(false, $validator->errors()->first());
        }

        $setting = GlobalSettings::first();
        $setting->creator_share_percent = (int) $request->creator_share_percent;
        $setting->min_withdrawal_amount = round((float) $request->min_withdrawal_amount, 2);
        $setting->earnings_hold_days = (int) $request->earnings_hold_days;
        $setting->max_withdrawals_per_week = (int) $request->max_withdrawals_per_week;
        $setting->creator_min_account_age_days = (int) $request->creator_min_account_age_days;
        $setting->payout_change_hold_hours = (int) $request->payout_change_hold_hours;
        $setting->wallet_v2 = $request->boolean('wallet_v2') ? 1 : 0;
        $setting->verification_enabled = $request->boolean('verification_enabled') ? 1 : 0;
        $setting->verification_price = round((float) $request->verification_price, 2);
        $setting->verification_days = (int) $request->verification_days;
        // The page never shows the stored id; an empty field keeps it.
        if ($request->filled('lenco_account_id')) {
            $setting->lenco_account_id = trim($request->lenco_account_id);
        }
        $setting->save(); // GlobalSettings busts the app settings cache on save

        return GlobalFunction::sendSimpleResponse(true, 'Monetization settings saved');
    }

    // accept_name: an admin checked a name mismatch by hand and accepts it.
    // suspend / unsuspend: stop or allow this creator's withdrawals.
    public function updateCreatorProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'action' => 'required|in:accept_name,suspend,unsuspend',
            'note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return GlobalFunction::sendSimpleResponse(false, $validator->errors()->first());
        }
        $profile = CreatorProfile::find((int) $request->user_id);
        if ($profile === null) {
            return GlobalFunction::sendSimpleResponse(false, 'this user has no payout account yet');
        }

        $admin = Session::get('username');
        switch ($request->action) {
            case 'accept_name':
                $profile->name_override_by = $admin;
                break;
            case 'suspend':
                $profile->status = 'suspended';
                break;
            case 'unsuspend':
                $profile->status = 'active';
                break;
        }
        if ($request->filled('note')) {
            $profile->notes = trim(($profile->notes ? $profile->notes . "\n" : '') . now()->toDateTimeString() . " {$admin}: " . $request->note);
        }
        $profile->save();

        return GlobalFunction::sendSimpleResponse(true, 'Creator payout account updated');
    }
}
