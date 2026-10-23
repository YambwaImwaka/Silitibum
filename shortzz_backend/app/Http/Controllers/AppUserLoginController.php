<?php

namespace App\Http\Controllers;

use App\Models\GlobalFunction;
use App\Models\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

// Users-scoped web login for the mobile-money coin top-up page (see
// TopUpController) — deliberately separate from LoginController/CheckLogin,
// which are hard-wired to the Admin model and its reversible-encryption
// password scheme. This queries Users and checks the same bcrypt hash the
// mobile app's UserController::logInUser already uses (Hash::check against
// Users.password), and stores its own session key (app_user_id) so it can
// never collide with the admin panel's session state (username/
// userpassword/user_type) even though both share the same session store.
class AppUserLoginController extends Controller
{
    public function login()
    {
        if (Session::get('app_user_id')) {
            return redirect(Session::pull('app_user_intended') ?: route('topup.show'));
        }
        return view('topup_login');
    }

    /** Lifetime of a sign-in link the app opens in the browser. */
    private const LINK_TTL_MINUTES = 5;

    /** Pages a sign-in link may land on. */
    private const LINK_DESTINATIONS = ['topup' => 'topup.show', 'verify' => 'verify.show'];

    // misc/createWebLoginLink (app, authtoken): a single-use link that signs
    // this user into the web payment pages. The password form cannot serve
    // accounts made with Google or Apple sign-in — they have no password.
    public static function createLink(int $userId, string $next): string
    {
        $token = Str::random(48);
        Cache::put('web_login:' . hash('sha256', $token), $userId, now()->addMinutes(self::LINK_TTL_MINUTES));
        return route('topup.auto', ['t' => $token, 'next' => $next]);
    }

    public function createWebLoginLink(Request $request)
    {
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if ($user == null) {
            return GlobalFunction::sendSimpleResponse(false, 'unauthorized');
        }
        $next = array_key_exists($request->next, self::LINK_DESTINATIONS) ? $request->next : 'topup';
        return GlobalFunction::sendDataResponse(true, 'link created', [
            'url' => self::createLink((int) $user->id, $next),
        ]);
    }

    // The link itself. Cache::pull makes it single use; an expired or reused
    // link falls back to the normal sign-in form.
    public function auto(Request $request)
    {
        $token = (string) $request->query('t', '');
        $userId = $token === '' ? null : Cache::pull('web_login:' . hash('sha256', $token));
        $user = $userId ? Users::find($userId) : null;
        if ($user == null || $user->is_freez == 1) {
            return redirect(route('topup.login'));
        }
        $request->session()->regenerate();
        Session::put('app_user_id', $user->id);
        $route = self::LINK_DESTINATIONS[$request->query('next')] ?? 'topup.show';
        return redirect(route($route));
    }

    public function checkLogin(Request $request)
    {
        $request->validate([
            'identity' => 'required',
            'password' => 'required',
        ]);

        $candidates = $this->candidateIdentities($request->identity);
        $matches = Users::where(function ($query) use ($candidates) {
            $query->whereIn('identity', $candidates)->orWhereIn('user_email', $candidates);
        })->get();

        $user = null;
        $passwordless = false;
        foreach ($matches as $match) {
            if ($match->password == null) {
                $passwordless = true;
            } else if (Hash::check($request->password, $match->password)) {
                $user = $match;
                break;
            }
        }

        if ($user == null) {
            // Rows created by googleLogin/appleLogin never get a password hash
            // written, so no password typed here can ever match one. Say that
            // outright rather than leave the user retrying a password that
            // cannot work — same disclosure the app's own logInUser already
            // makes with account_not_found/incorrect_password.
            if ($passwordless) {
                return response()->json(['status' => false, 'message' => 'This account signs in with Google or Apple, so it has no password to use here.']);
            }
            return response()->json(['status' => false, 'message' => 'Wrong credentials!']);
        }
        if ($user->is_freez == 1) {
            return response()->json(['status' => false, 'message' => 'This account is frozen.']);
        }

        Session::put('app_user_id', $user->id);
        $redirect = Session::pull('app_user_intended') ?: route('topup.show');

        return response()->json(['status' => true, 'message' => 'Login successful',
            'data' => ['id' => $user->id, 'redirect' => $redirect]]);
    }

    // Accounts are keyed by the canonical identity the app's signup builds:
    // '+<countryCode><national digits, leading zeros stripped>' (see
    // AuthScreenController._normalizedPhone). This page has no country picker
    // and most users type their number the local way ('0977…') or don't know
    // their country code at all, so rather than demand the canonical form we
    // rebuild every identity the typed input could plausibly be and let the
    // password decide which row is really theirs. A wrong guess can't log
    // anyone in: Hash::check still has to pass against that specific row.
    private function candidateIdentities(string $input): array
    {
        $input = trim($input);
        $candidates = [$input];

        // An email is already the identity as stored — no rebuilding to do.
        if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
            $digits = preg_replace('/[^0-9]/', '', $input);
            if ($digits !== '') {
                // Already carries the country code, '+' present or not.
                $candidates[] = '+' . $digits;
            }
            // The local form the app's picker would have canonicalised for them.
            $canonical = GlobalFunction::canonicalMsisdn($input);
            if ($canonical !== null) {
                $candidates[] = '+' . $canonical;
            }
        }

        return array_values(array_unique($candidates));
    }

    public function logout()
    {
        Session::pull('app_user_id');
        return redirect(route('topup.login'));
    }
}
