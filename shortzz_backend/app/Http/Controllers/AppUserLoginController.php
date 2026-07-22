<?php

namespace App\Http\Controllers;

use App\Models\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

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
            return redirect(route('topup.show'));
        }
        return view('topup_login');
    }

    public function checkLogin(Request $request)
    {
        $request->validate([
            'identity' => 'required',
            'password' => 'required',
        ]);

        $user = Users::where('identity', $request->identity)->first();
        if ($user == null || $user->password == null || !Hash::check($request->password, $user->password)) {
            return response()->json(['status' => false, 'message' => 'Wrong credentials!']);
        }
        if ($user->is_freez == 1) {
            return response()->json(['status' => false, 'message' => 'This account is frozen.']);
        }

        Session::put('app_user_id', $user->id);

        return response()->json(['status' => true, 'message' => 'Login successful', 'data' => ['id' => $user->id]]);
    }

    public function logout()
    {
        Session::pull('app_user_id');
        return redirect(route('topup.login'));
    }
}
