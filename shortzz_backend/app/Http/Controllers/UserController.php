<?php

namespace App\Http\Controllers;

use App\Models\Constants;
use App\Models\Followers;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Models\UserAuthTokens;
use App\Models\UserBlocks;
use App\Models\UserLinks;
use App\Models\UsernameRestrictions;
use App\Models\Users;
use App\Models\VerificationCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UserController extends Controller
{
    //
    public function updateUser(Request $request){
        $user = Users::find($request->id);

        if($request->username != $user->username){
            $userExists = Users::where('username', $request->username)->exists();
            if($userExists){
                return GlobalFunction::sendSimpleResponse(false,'Username exists already!');
            }
        }
        if($request->has('profile_photo')){
            if($user->profile_photo != null){
                GlobalFunction::deleteFile($user->profile_photo);
            }
            $user->profile_photo = GlobalFunction::saveFileAndGivePath($request->profile_photo);
        }
        $user->username = $request->username;
        $user->fullname = $request->fullname;
        $user->user_email = $request->user_email;
        $user->mobile_country_code = $request->mobile_country_code;
        $user->user_mobile_no = $request->user_mobile_no;
        $user->bio = $request->bio;

        if($user->is_dummy == 1){
            if($request->has('is_verify')){
                $user->is_verify = $request->is_verify;
            }
            if($request->has('password')){
                $user->password = $request->password;
            }
        }
       $user->save();
       return GlobalFunction::sendSimpleResponse(true,'User details updated successfully');
    }
    public function users(){

        return view('users');
    }
    public function createDummyUser(){

        return view('createDummyUser');
    }
    public function editUser($id){
        $phoneCountryCodes = GlobalFunction::getPhoneCountryCodes();
        $user = Users::find($id);
        return view('editUser')->with([
            'user'=> $user,
            'phoneCountryCodes'=> $phoneCountryCodes,
        ]);
    }
    public function editDummyUser($id){

        $user = Users::find($id);
        if($user->is_dummy != 1){
            return redirect()->back();
        }
        return view('editDummyUser')->with([
            'user'=> $user
        ]);
    }
    public function addDummyUser(Request $request){
        $user = Users::where('username', $request->username)->first();
        if($user != null){
            return GlobalFunction::sendSimpleResponse(false,'this username is not available');
        }
            $user = new Users;
            $user->fullname = $request->fullname;
            $user->bio = $request->bio;
            $user->identity = GlobalFunction::generateDummyUserIdentity();
            $user->username = $request->username;
            $user->password = $request->password;
            $user->is_verify = $request->is_verify;
            $user->is_dummy = Constants::userDummy;
            $user->profile_photo = GlobalFunction::saveFileAndGivePath($request->profile_photo);
            $user->save();

            return GlobalFunction::sendSimpleResponse(true,'Dummy user added successfully');
    }

    public function changeUserModeratorStatus(Request $request){
        $user = Users::find($request->user_id);
        $user->is_moderator = $request->is_moderator;
        $user->save();

        return GlobalFunction::sendSimpleResponse(true, 'changes applied successfully');
    }

    public function deleteUserLink_Admin(Request $request){
        $link = UserLinks::find($request->id);
        $link->delete();

        return GlobalFunction::sendSimpleResponse(true,'link deleted successfully');
    }

    public function unFollowUser(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        $rules = [
            'user_id' => 'required|exists:tbl_users,id',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $dataUser = GlobalFunction::prepareUserFullData($request->user_id);
        // Self check
        if($user->id == $dataUser->id){
            return GlobalFunction::sendSimpleResponse(false, 'you can not follow/unfollow yourself!');
        }
        $follow = Followers::where([
            'from_user_id'=> $user->id,
            'to_user_id'=> $dataUser->id,
            ])->first();
        if($follow == null){
            return GlobalFunction::sendSimpleResponse(false, 'you are not following this user!');
        }
        $follow->delete();

        GlobalFunction::settleFollowCount($dataUser->id);
        GlobalFunction::settleFollowCount($user->id);

        GlobalFunction::deleteNotifications(Constants::notify_follow_user, $user->id, $user->id);

        return GlobalFunction::sendSimpleResponse(true, 'unfollow successful');

    }
    public function fetchUserFollowings(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserOrGuest($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $rules = [
            'user_id' => 'required|exists:tbl_users,id',
            'limit' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $dataUser = GlobalFunction::prepareUserFullData($request->user_id);

        //  Check if show my following on/off
        if($dataUser->show_my_following == 0){ //1=yes 0=no
            return GlobalFunction::sendSimpleResponse(false, 'this user has turned off his following show.');
        }

         // Block check
         $isBlock = GlobalFunction::checkUserBlock($user->id, $dataUser->id);
         if($isBlock){
             return GlobalFunction::sendSimpleResponse(false, 'you can not continue this action!');
         }


         $query = Followers::where('from_user_id', $dataUser->id)
                ->orderBy('id', 'DESC')
                ->with(['to_user:'.Constants::userPublicFields])
                ->limit($request->limit);
         if($request->has('last_item_id')){
             $query->where('id','<',$request->last_item_id);
         }
        $data = $query ->get();

        $myFollowingIds = [];
        if ($user->id) {
            $myFollowingIds = array_flip(Followers::where('from_user_id', $user->id)
                ->whereIn('to_user_id', collect($data)->pluck('to_user_id')->all())
                ->pluck('to_user_id')->all());
        }
        foreach($data as $folliwingItem){
            $folliwingItem->to_user->is_following = isset($myFollowingIds[$folliwingItem->to_user_id]);
        }

        return GlobalFunction::sendDataResponse(true, 'following fetched successfully', $data);


    }
    public function fetchUserFollowers(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserOrGuest($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $rules = [
            'user_id' => 'required|exists:tbl_users,id',
            'limit' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $dataUser = GlobalFunction::prepareUserFullData($request->user_id);
         // Block check
         $isBlock = GlobalFunction::checkUserBlock($user->id, $dataUser->id);
         if($isBlock){
             return GlobalFunction::sendSimpleResponse(false, 'you can not continue this action!');
         }
         $query = Followers::where('to_user_id', $dataUser->id)
                ->orderBy('id', 'DESC')
                ->with(['from_user:'.Constants::userPublicFields])
                ->limit($request->limit);
         if($request->has('last_item_id')){
             $query->where('id','<',$request->last_item_id);
         }
        $data = $query ->get();

        $myFollowingIds = [];
        if ($user->id) {
            $myFollowingIds = array_flip(Followers::where('from_user_id', $user->id)
                ->whereIn('to_user_id', collect($data)->pluck('from_user_id')->all())
                ->pluck('to_user_id')->all());
        }
        foreach($data as $followersItem){
            $followersItem->from_user->is_following = isset($myFollowingIds[$followersItem->from_user_id]);
        }


        return GlobalFunction::sendDataResponse(true, 'followers fetched successfully', $data);


    }
    public function fetchMyFollowings(Request $request){
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

         $query = Followers::where('from_user_id', $user->id)
                ->orderBy('id', 'DESC')
                ->with(['to_user:'.Constants::userPublicFields])
                ->limit($request->limit);
         if($request->has('last_item_id')){
             $query->where('id','<',$request->last_item_id);
         }
        $data = $query ->get();

        return GlobalFunction::sendDataResponse(true, 'my following fetched successfully', $data);

    }

    public function fetchMyFollowers(Request $request){
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

         $query = Followers::where('to_user_id', $user->id)
                ->orderBy('id', 'DESC')
                ->with(['from_user:'.Constants::userPublicFields])
                ->limit($request->limit);
         if($request->has('last_item_id')){
             $query->where('id','<',$request->last_item_id);
         }
        $data = $query ->get();

        $myFollowingIds = array_flip(Followers::where('from_user_id', $user->id)
            ->whereIn('to_user_id', collect($data)->pluck('from_user_id')->all())
            ->pluck('to_user_id')->all());
        foreach($data as $item){
            $item->from_user->is_following = isset($myFollowingIds[$item->from_user_id]);
        }

        return GlobalFunction::sendDataResponse(true, 'my followers fetched successfully', $data);

    }
    public function followUser(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $rules = [
            'user_id' => 'required|exists:tbl_users,id',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $dataUser = GlobalFunction::prepareUserFullData($request->user_id);
        // Self check
        if($user->id == $dataUser->id){
            return GlobalFunction::sendSimpleResponse(false, 'you can not follow yourself!');
        }
        $follow = Followers::where([
            'from_user_id'=> $user->id,
            'to_user_id'=> $dataUser->id,
            ])->first();
        if($follow != null){
            return GlobalFunction::sendSimpleResponse(false, 'you are following this user already!');
        }
        // Block check
        $isBlock = GlobalFunction::checkUserBlock($user->id, $dataUser->id);
        if($isBlock){
            return GlobalFunction::sendSimpleResponse(false, 'you can not follow this user!');
        }

        $follow = new Followers();
        $follow->from_user_id = $user->id;
        $follow->to_user_id = $dataUser->id;
        $follow->save();

        GlobalFunction::settleFollowCount($dataUser->id);
        GlobalFunction::settleFollowCount($user->id);

        // Insert Notification Data : Follow User
        $notificationData = GlobalFunction::insertUserNotification(Constants::notify_follow_user,$user->id, $dataUser->id, $user->id);

        return GlobalFunction::sendSimpleResponse(true, 'follow successful');

    }

    public function fetchUserDetails(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserOrGuest($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        $rules = [
            'user_id' => 'required|exists:tbl_users,id',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $dataUser = Users::find($request->user_id);

        // Counters are maintained on the mutation paths (follow/unfollow,
        // like/unlike) — recomputing them here on every profile view was
        // pure write-on-read overhead, not something this read needs.

        $dataUser = GlobalFunction::prepareUserFullData($dataUser->id);

        // Check follow status (single query reused for both directions'
        // worth of state — this used to run the "am I following them" query
        // twice).
        $following = Followers::where([
            'from_user_id' => $user->id,
            'to_user_id' => $dataUser->id
        ])->exists();
        $dataUser->is_following = $following;

        $follower = Followers::where([
            'from_user_id' => $dataUser->id,
            'to_user_id' => $user->id
        ])->exists();

            if ($following && $follower) {
                $dataUser->follow_status = 3; // Both users follow each other
            } elseif ($following) {
                $dataUser->follow_status = 1; // I am following this user
            } elseif ($follower) {
                $dataUser->follow_status = 2; // The user follows me but I don’t follow back
            } else {
                $dataUser->follow_status = 0; // No follow relationship
            }

        $dataUser->is_block = GlobalFunction::checkUserBlock($user->id, $dataUser->id);

        return GlobalFunction::sendDataResponse(true, 'user details fetched successfully', $dataUser);


    }

    public function fetchMyBlockedUsers(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $items = UserBlocks::where([
            'from_user_id' => $user->id
        ])->with(['to_user:'.Constants::userPublicFields])->get();

        return GlobalFunction::sendDataResponse(true, 'blocked users fetched successfully', $items);

    }

    function searchUsers(Request $request)
    {
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserOrGuest($token);
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
            $search = GlobalFunction::cleanString($request->keyword);

            $blockedUserIds = GlobalFunction::getUsersBlockedUsersIdsArray($user->id);

            $query =  Users::whereNotIn('id', $blockedUserIds)
                ->where(function ($query) use ($search) {
                    $query->where('fullname', 'LIKE', "%{$search}%")
                        ->orWhere('username', 'LIKE', "%{$search}%");
                })
                ->select(explode(',',Constants::userPublicFields))
                ->where('is_freez', 0)
                ->orderBy('id', 'DESC')
                ->limit($request->limit);
                if($request->has('last_item_id')){
                    $query->where('id','<',$request->last_item_id);
                }
        $data = $query->get();


        $myFollowingIds = [];
        if ($user->id) {
            $myFollowingIds = array_flip(Followers::where('from_user_id', $user->id)
                ->whereIn('to_user_id', collect($data)->pluck('id')->all())
                ->pluck('to_user_id')->all());
        }
        foreach($data as $singleUser){
            $singleUser->is_following = isset($myFollowingIds[$singleUser->id]);
        }

        return GlobalFunction::sendDataResponse(true, 'search users fetched successfully', $data);
    }

    public function unBlockUser(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        $rules = [
           'user_id' => 'required|exists:tbl_users,id',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $toUser = Users::find($request->user_id);

        if($user->id == $toUser->id){
            return GlobalFunction::sendSimpleResponse(false, 'you can not block/unblock yourself!');
        }
        $item = UserBlocks::where([
            'from_user_id'=> $user->id,
            'to_user_id'=> $toUser->id
        ])->first();
        if($item == null){
            return GlobalFunction::sendSimpleResponse(false, 'this user is not blocked!');
        }
        $item->delete();

        return GlobalFunction::sendSimpleResponse(true, 'user unblocked successfully');

    }

    public function blockUser(Request $request){

        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        $rules = [
            'user_id' => 'required|exists:tbl_users,id',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        $toUser = Users::find($request->user_id);

        if($user->id == $toUser->id){
            return GlobalFunction::sendSimpleResponse(false, 'you can not block/unblock yourself!');
        }
        $item = UserBlocks::where([
            'from_user_id'=> $user->id,
            'to_user_id'=> $toUser->id
        ])->first();
        if($item != null){
            return GlobalFunction::sendSimpleResponse(false, 'user is blocked already!');
        }
        $item = new UserBlocks();
        $item->from_user_id = $user->id;
        $item->to_user_id = $toUser->id;
        $item->save();

        // Follow delete if doing
        Followers::where([
            'from_user_id'=> $toUser->id,
            'to_user_id'=> $user->id,
        ])->delete();

        return GlobalFunction::sendSimpleResponse(true, 'user blocked successfully');

    }
    public function viewUserDetails($id){

        $user = GlobalFunction::prepareUserFullData($id);
        $baseUrl = GlobalFunction::getItemBaseUrl();
        $user->levelNumber = GlobalFunction::determineUserLevel($user->id);

        return view('viewUserDetails',[
            'user'=> $user,
            'baseUrl'=> $baseUrl,
        ]);
    }
    public function listDummyUsers(Request $request)
    {
        $query = Users::query();
        $query->where('is_dummy', 1);
        $totalData = $query->count();

        $columns = ['id'];
        $limit = $request->input('length');
        $start = $request->input('start');
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('fullname', 'LIKE', "%{$searchValue}%")
                ->orWhere('username', 'LIKE', "%{$searchValue}%")
                ->orWhere('identity', 'LIKE', "%{$searchValue}%");
            });
        }
        $totalFiltered = $query->count();

        $result = $query->offset($start)
                        ->limit($limit)
                        ->orderBy('id', 'DESC')
                        ->get();

        $data = $result->map(function ($item) {

            $userProfileCard = GlobalFunction::createUserDetailsColumn($item->id);

            $freeze = GlobalFunction::createUserFreezeSwitch($item,'dummy');

            $moderator = GlobalFunction::createUserModeratorSwitch($item,'dummy');

            $userDetailsUrl = route('viewUserDetails', $item->id);
            $editDummyUserUrl = route('editDummyUser', $item->id);

            $view = "<a href='$userDetailsUrl'
                          rel='{$item->id}'
                          class='action-btn d-flex align-items-center justify-content-center btn border rounded-2 text-info ms-1'>
                            <i class='ri-eye-line'></i>
                        </a>";
            $edit = "<a href='$editDummyUserUrl'
                          rel='{$item->id}'
                          class='action-btn d-flex align-items-center justify-content-center btn border rounded-2 text-info ms-1'>
                            <i class='uil-pen'></i>
                        </a>";
            $delete = "<a href='#'
                          rel='{$item->id}'
                          class='action-btn delete d-flex align-items-center justify-content-center btn border rounded-2 text-danger ms-1'>
                            <i class='uil-trash-alt'></i>
                        </a>";
            $action = "<span class='d-flex justify-content-end align-items-center'>{$view}{$edit}{$delete}</span>";


            $identity = "<h5>{$item->identity}</h5>";
            $password = "<p class='m-0'>{$item->password}</p>";
            $identity_password = '<div class="">'.$identity.$password.'</div>';

            return [
                $userProfileCard,
                $identity_password,
                $freeze,
                $moderator,
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
    public function listAllModerators(Request $request)
    {
        $query = Users::query();
        $query->where('is_moderator', 1);
        $totalData = $query->count();

        $columns = ['id'];
        $limit = $request->input('length');
        $start = $request->input('start');
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('fullname', 'LIKE', "%{$searchValue}%")
                ->orWhere('username', 'LIKE', "%{$searchValue}%")
                ->orWhere('identity', 'LIKE', "%{$searchValue}%");
            });
        }
        $totalFiltered = $query->count();

        $result = $query->offset($start)
                        ->limit($limit)
                        ->orderBy('id', 'DESC')
                        ->get();

        $data = $result->map(function ($item) {

            $userProfileCard = GlobalFunction::createUserDetailsColumn($item->id);

            $realOrFake = GlobalFunction::createUserTypeBadge($item->id);

            $freeze = GlobalFunction::createUserFreezeSwitch($item,'moderators');

            $moderator = GlobalFunction::createUserModeratorSwitch($item,'moderators');

            $userDetailsUrl = route('viewUserDetails', $item->id);

            $view = "<a href='$userDetailsUrl'
                          rel='{$item->id}'
                          class='action-btn delete d-flex align-items-center justify-content-center btn border rounded-2 text-info ms-1'>
                            <i class='ri-eye-line'></i>
                        </a>";
            $action = "<span class='d-flex justify-content-end align-items-center'>{$view}</span>";

            return [
                $userProfileCard,
                $realOrFake,
                $item->identity,
                $freeze,
                $moderator,
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
    public function listAllUsers(Request $request)
    {
        $query = Users::query();
        $totalData = $query->count();

        $columns = ['id'];
        $limit = $request->input('length');
        $start = $request->input('start');
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('fullname', 'LIKE', "%{$searchValue}%")
                ->orWhere('username', 'LIKE', "%{$searchValue}%")
                ->orWhere('identity', 'LIKE', "%{$searchValue}%");
            });
        }
        $totalFiltered = $query->count();

        $result = $query->offset($start)
                        ->limit($limit)
                        ->orderBy('id', 'DESC')
                        ->get();

        $data = $result->map(function ($item) {

            $userProfileCard = GlobalFunction::createUserDetailsColumn($item->id);

            $realOrFake = GlobalFunction::createUserTypeBadge($item->id);

            $freeze = GlobalFunction::createUserFreezeSwitch($item, 'all');

            $moderator = GlobalFunction::createUserModeratorSwitch($item,'all');

            $userDetailsUrl = route('viewUserDetails', $item->id);

            $view = "<a href='$userDetailsUrl'
                          rel='{$item->id}'
                          class='action-btn delete d-flex align-items-center justify-content-center btn border rounded-2 text-info ms-1'>
                            <i class='ri-eye-line'></i>
                        </a>";
            $action = "<span class='d-flex justify-content-end align-items-center'>{$view}</span>";

            return [
                $userProfileCard,
                $realOrFake,
                $item->identity,
                $freeze,
                $moderator,
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
    public function userFreezeUnfreeze(Request $request){
        $user = Users::find($request->user_id);
        $user->is_freez = $request->is_freez;
        $user->save();

        return GlobalFunction::sendSimpleResponse(true, 'Task successful');
    }
    public function updateLastUsedAt(Request $request){

        // Validate user token and fetch user
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        $user->app_last_used_at = Carbon::now();
        $user->save();

        return GlobalFunction::sendSimpleResponse(true, 'last log in updated successfully');

    }

    public function checkUsernameAvailability(Request $request){
        $validator = Validator::make($request->all(), [
            'username' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = Users::where('username', $request->username)->first();
        if($user){
            return GlobalFunction::sendSimpleResponse(false, 'username not available!');
        }

        return GlobalFunction::sendSimpleResponse(true, 'username available!');

    }

    public function editeUserLink(Request $request){

        $validator = Validator::make($request->all(), [
            'link_id' => 'required|exists:user_links,id',
            'title' => 'required',
            'url' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        // Validate user token and fetch user
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        if ($user->is_freez == 1) {
            return response()->json(['status' => false, 'message' => "this user is freezed!"]);
        }
        $link = UserLinks::find($request->link_id);
        if(!$link){
            return GlobalFunction::sendSimpleResponse(false, 'Link not found!');
        }
        if($link->user_id != $user->id){
            return GlobalFunction::sendSimpleResponse(false, 'this link is not owned by this user!');
        }
        $link->title = $request->title;
        $link->url = $request->url;
        $link->save();

        return GlobalFunction::sendDataResponse(true, 'user link edited successfully!', $user->links);

    }
    public function deleteUserLink(Request $request){

        $validator = Validator::make($request->all(), [
            'link_id' => 'required|exists:user_links,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        // Validate user token and fetch user
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        if ($user->is_freez == 1) {
            return response()->json(['status' => false, 'message' => "this user is freezed!"]);
        }
        $link = UserLinks::find($request->link_id);
        if(!$link){
            return GlobalFunction::sendSimpleResponse(false, 'Link not found!');
        }
        if($link->user_id != $user->id){
            return GlobalFunction::sendSimpleResponse(false, 'this link is not owned by this user!');
        }
        $link->delete();

        return GlobalFunction::sendDataResponse(true, 'user link deleted successfully!', $user->links);

    }
    public function deleteMyAccount(Request $request){
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user == null) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        // Password accounts confirm with their password (replaces the Firebase
        // re-auth the app used to do); social accounts confirm via the OS UI.
        if ($user->password != null && !Hash::check($request->password ?? '', $user->password)) {
            return GlobalFunction::sendSimpleResponse(false, 'incorrect_password');
        }
        GlobalFunction::deleteUserAccount($user);
        $user->delete();
         return GlobalFunction::sendSimpleResponse(true, 'account deleted successfully');
    }
    public function deleteDummyUser(Request $request){
        $user = Users::find($request->id);
        if ($user) {
           GlobalFunction::deleteUserAccount($user);
        }
        $user->delete();
         return GlobalFunction::sendSimpleResponse(true, 'User deleted successfully');
    }


    public function addUserLink(Request $request){

        $validator = Validator::make($request->all(), [
            'url' => 'required',
            'title' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        // Validate user token and fetch user
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        if ($user->is_freez == 1) {
            return response()->json(['status' => false, 'message' => "this user is freezed!"]);
        }

        $link = new UserLinks();
        $link->user_id = $user->id;
        $link->title = $request->title;
        $link->url = $request->url;
        $link->save();

        return GlobalFunction::sendDataResponse(true, 'user link added successfully!', $user->links);

    }

    public function updateUserDetails(Request $request)
    {
        $token = $request->header('authtoken');

        // Validate user token and fetch user
        $user = GlobalFunction::getUserFromAuthToken($token);
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        if ($user->is_freez == 1) {
            return response()->json(['status' => false, 'message' => "this user is freezed!"]);
        }

        // Define fields to update
        $updatableFields = [
            'fullname',
            'user_email',
            'user_mobile_no',
            'mobile_country_code',
            'device_token',
            'bio',
            'country',
            'countryCode',
            'region',
            'regionName',
            'city',
            'lon',
            'lat',
            'timezone',
            'notify_post_like',
            'notify_post_comment',
            'notify_follow',
            'notify_mention',
            'notify_gift_received',
            'notify_chat',
            'receive_message',
            'show_my_following',
            'who_can_view_post',
            'saved_music_ids',
            'app_language',
            // is_verify is intentionally NOT here — it must only ever be set
            // by the RevenueCat webhook (UserController::revenueCatWebhook),
            // never by the client, so a subscription can't be self-granted.
        ];

        // Update user fields dynamically
        foreach ($updatableFields as $field) {
            if ($request->has($field)) {
                $user->$field = $request->$field;
            }
        }
        // Handle profile photo separately
        if ($request->has('profile_photo')) {
            if ($user->profile_photo) {
                GlobalFunction::deleteFile($user->profile_photo);
            }
            $user->profile_photo = GlobalFunction::saveFileAndGivePath($request->profile_photo);
        }
        // Handle Username
        if ($request->has('username')) {
            $user2 = Users::where('username', $request->username)->first();
            if($user2 && $user2->id != $user->id){
                return GlobalFunction::sendSimpleResponse(false, 'username is not available!');
            }
            $restriction = UsernameRestrictions::where('username', $request->username)->first();
            if($restriction){
                return GlobalFunction::sendSimpleResponse(false, 'username is not available!');
            }
            $user->username = $request->username;
        }

        // Save updated user details
        $user->save();
        $user = GlobalFunction::prepareUserFullData($user->id);
        return GlobalFunction::sendDataResponse(true, 'User details updated successfully', $user);
    }


    // Builds (does not save) a fresh app user with username + registration bonus.
    private function createAppUser(Request $request)
    {
        $user = new Users;
        $user->fullname = $request->filled('fullname')
            ? GlobalFunction::cleanString($request->fullname)
            : 'User';
        $user->identity = $request->identity;
        $user->login_method = $request->login_method;
        $user->username = GlobalFunction::generateUsername($user->fullname);

        if ($request->has('profile_photo')) {
            $user->profile_photo = GlobalFunction::saveFileAndGivePath($request->profile_photo);
        }

        $settings = GlobalSettings::first();
        if ($settings->registration_bonus_status == 1) {
            $user->coin_wallet = $settings->registration_bonus_amount;
            $user->coin_collected_lifetime = $settings->registration_bonus_amount;
        }
        return $user;
    }

    // Stamps device fields, issues a fresh auth token and returns the full
    // user payload — the single success response for register/login.
    private function respondWithUserData($user, Request $request, $newRegister)
    {
        $user->device_token = $request->device_token;
        $user->device = $request->device;
        $user->login_method = $request->login_method;
        $user->save();

        $token = GlobalFunction::generateUserAuthToken($user);
        $user = GlobalFunction::prepareUserFullData($user->id);
        $user->new_register = $newRegister;
        $user->token = $token;
        $user->following_ids = GlobalFunction::fetchUserFollowingIds($user->id);

        return GlobalFunction::sendDataResponse(true, 'Data Fetch Successful!', $user);
    }

    // MySQL-native signup for the password channels (phone / email). Never an
    // upsert: an identity that already holds a password must log in instead.
    // An identity left over from the Firebase era (no password hash) is
    // claimed by the first signup that proves nothing more — acceptable only
    // because no real Firebase-credentialed users ever shipped.
    function registerUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fullname' => 'nullable',
            'identity' => 'required',
            'password' => 'required|min:6',
            'login_method' => 'required|in:email,phone',
            'device' => 'required',
            'device_token' => 'nullable',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        // Serialize concurrent signups for the same identity. Without this,
        // a double-tap (or any two racing requests) can both pass the
        // "does this identity already exist" check below and both write —
        // this is the double-submit bug: the first tap appears to fail while
        // still persisting a real account, and the second tap then collides
        // with it.
        $lock = Cache::lock('register-lock:' . strtolower($request->identity), 10);
        if (!$lock->get()) {
            return GlobalFunction::sendSimpleResponse(false, 'account_exists');
        }

        try {
            $user = Users::where('identity', $request->identity)->first();
            if ($user != null && $user->password != null) {
                return GlobalFunction::sendSimpleResponse(false, 'account_exists');
            }

            $newRegister = ($user == null);
            if ($user == null) {
                $user = $this->createAppUser($request);
            }
            $user->password = Hash::make($request->password);

            if ($request->login_method == 'email') {
                $user->user_email = $request->identity;
            }

            // Everything that persists the account (user row + auth token)
            // must succeed or fail together. Previously the user row was
            // saved before the auth-token/full-data steps ran, so a failure
            // in those later steps still left a real, password-holding
            // account behind while the client was shown a generic error —
            // the account then looked "half created": retrying signup hit
            // account_exists, yet nothing about it was actually broken, so
            // it should just have been returned as a normal success. Wrapping
            // the whole write in a transaction makes failure genuinely mean
            // "nothing happened" so a retry is a clean, real second attempt.
            try {
                $response = DB::transaction(function () use ($user, $request, $newRegister) {
                    return $this->respondWithUserData($user, $request, $newRegister);
                });
            } catch (Throwable $e) {
                Log::error('registerUser failed for identity ' . $request->identity . ': ' . $e);
                return GlobalFunction::sendSimpleResponse(false, 'registration_failed');
            }

            if ($request->login_method == 'email') {
                // Soft verification: mail failure must never fail the signup.
                try {
                    GlobalFunction::issueVerificationCode($user, $request->identity, VerificationCode::TYPE_VERIFY_EMAIL);
                } catch (Throwable $e) {
                    Log::error('issueVerificationCode failed for identity ' . $request->identity . ': ' . $e);
                }
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    function logInUser(Request $request){
        $validator = Validator::make($request->all(), [
            // fullname: only used when creating a new account (never overwrites).
            // device_token: nullable — devices without Play Services have no FCM token.
            'fullname' => 'nullable',
            'identity' => 'required',
            'device_token' => 'nullable',
            'device' => 'required',
            'login_method' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $loginMethod = $request->login_method;

        // MySQL-native credentials. Password methods never auto-create — the
        // app routes new users through registerUser.
        if ($request->filled('password') && in_array($loginMethod, ['email', 'phone'])) {
            $user = Users::where('identity', $request->identity)->first();
            if ($user == null) {
                return GlobalFunction::sendSimpleResponse(false, 'account_not_found');
            }
            if ($user->password == null) {
                // Firebase-era row without a local hash: first password login claims it.
                $user->password = Hash::make($request->password);
            } else if (!Hash::check($request->password, $user->password)) {
                return GlobalFunction::sendSimpleResponse(false, 'incorrect_password');
            }
            try {
                return DB::transaction(function () use ($user, $request) {
                    return $this->respondWithUserData($user, $request, false);
                });
            } catch (Throwable $e) {
                Log::error('logInUser failed for identity ' . $request->identity . ': ' . $e);
                return GlobalFunction::sendSimpleResponse(false, 'login_failed');
            }
        }

        // Google Sign-In: the app sends the provider ID token; verify it
        // directly against Google (no Firebase in between).
        if ($loginMethod == 'google' && $request->filled('id_token')) {
            $payload = GlobalFunction::verifyGoogleIdToken($request->id_token);
            if ($payload == null) {
                return GlobalFunction::sendSimpleResponse(false, 'Identity verification failed!');
            }
            if (strcasecmp($payload['email'] ?? '', $request->identity) != 0) {
                return GlobalFunction::sendSimpleResponse(false, 'Identity mismatch!');
            }
            $user = Users::where('identity', $request->identity)->first();
            $newRegister = ($user == null);
            if ($user == null) {
                $user = $this->createAppUser($request);
            }
            $user->provider_uid = $payload['sub'] ?? $user->provider_uid;
            // Google accounts arrive with a verified address.
            $user->user_email = $user->user_email ?: $request->identity;
            $user->email_verified_at = $user->email_verified_at ?: Carbon::now();
            return $this->respondWithUserData($user, $request, $newRegister);
        }

        // Sign in with Apple: verify the identityToken against Apple's JWKS.
        // Apple only reveals the email on the first grant, so the durable key
        // is the token's sub claim (stored in provider_uid).
        if ($loginMethod == 'apple' && $request->filled('identity_token')) {
            $claims = GlobalFunction::verifyAppleIdentityToken($request->identity_token);
            if ($claims == null) {
                return GlobalFunction::sendSimpleResponse(false, 'Identity verification failed!');
            }
            $user = Users::where('provider_uid', $claims->sub)->first();
            if ($user == null && !empty($claims->email)) {
                $user = Users::where('identity', $claims->email)->first();
            }
            $newRegister = ($user == null);
            if ($user == null) {
                $user = $this->createAppUser($request);
            }
            $user->provider_uid = $claims->sub;
            if (!empty($claims->email)) {
                $user->user_email = $user->user_email ?: $claims->email;
                $user->email_verified_at = $user->email_verified_at ?: Carbon::now();
            }
            return $this->respondWithUserData($user, $request, $newRegister);
        }

        // No recognized credential: every login method requires proof
        // (password, Google id_token or Apple identity_token).
        return GlobalFunction::sendSimpleResponse(false, 'Identity verification required!');
    }

    // Re-sends the email verification code to the logged-in user.
    function sendEmailVerificationCode(Request $request)
    {
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        $email = filter_var($user->identity, FILTER_VALIDATE_EMAIL) ? $user->identity : $user->user_email;
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return GlobalFunction::sendSimpleResponse(false, 'no_recovery_email');
        }
        [$ok, $msg] = GlobalFunction::issueVerificationCode($user, $email, VerificationCode::TYPE_VERIFY_EMAIL);
        return GlobalFunction::sendSimpleResponse($ok, $msg);
    }

    function verifyEmailCode(Request $request)
    {
        $validator = Validator::make($request->all(), ['code' => 'required']);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        if (!GlobalFunction::consumeVerificationCode($user, VerificationCode::TYPE_VERIFY_EMAIL, $request->code)) {
            return GlobalFunction::sendSimpleResponse(false, 'invalid_code');
        }
        $user->email_verified_at = Carbon::now();
        $user->save();
        return GlobalFunction::sendDataResponse(true, 'Email verified successfully', GlobalFunction::prepareUserFullData($user->id));
    }

    // Password reset entry point. Email identities get the code directly;
    // phone identities fall back to their recovery email (user_email) — there
    // is no SMS channel (an SMS gateway can slot in here later).
    function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), ['identity' => 'required']);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $user = Users::where('identity', $request->identity)->first();
        if ($user == null) {
            return GlobalFunction::sendSimpleResponse(false, 'account_not_found');
        }
        $email = filter_var($request->identity, FILTER_VALIDATE_EMAIL) ? $request->identity : $user->user_email;
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return GlobalFunction::sendSimpleResponse(false, 'no_recovery_email');
        }
        [$ok, $msg] = GlobalFunction::issueVerificationCode($user, $email, VerificationCode::TYPE_RESET_PASSWORD);
        if (!$ok) {
            return GlobalFunction::sendSimpleResponse(false, $msg);
        }
        return GlobalFunction::sendDataResponse(true, 'Code sent successfully', [
            'masked_email' => GlobalFunction::maskEmail($email),
        ]);
    }

    function resetPasswordWithCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
            'code' => 'required',
            'new_password' => 'required|min:6',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $user = Users::where('identity', $request->identity)->first();
        if ($user == null) {
            return GlobalFunction::sendSimpleResponse(false, 'account_not_found');
        }
        if (!GlobalFunction::consumeVerificationCode($user, VerificationCode::TYPE_RESET_PASSWORD, $request->code)) {
            return GlobalFunction::sendSimpleResponse(false, 'invalid_code');
        }
        $user->password = Hash::make($request->new_password);
        $user->save();
        // Invalidate existing sessions — the reset may follow a compromise.
        UserAuthTokens::where('user_id', $user->id)->delete();
        return GlobalFunction::sendSimpleResponse(true, 'Password reset successfully');
    }

    function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), ['new_password' => 'required|min:6']);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        // Social-only accounts (no hash yet) may set a first password without
        // the old one; password accounts must prove it.
        if ($user->password != null && !Hash::check($request->old_password ?? '', $user->password)) {
            return GlobalFunction::sendSimpleResponse(false, 'incorrect_password');
        }
        $user->password = Hash::make($request->new_password);
        $user->save();
        return GlobalFunction::sendSimpleResponse(true, 'Password changed successfully');
    }
    function logOutUser(Request $request){
        // Validate user token and fetch user
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if (!$user) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        $user->device_token = null;
        UserAuthTokens::where('user_id', $user->id)->where('auth_token', $token)->delete();
        $user->save();
        return GlobalFunction::sendSimpleResponse(true, 'Log out Successful!');
    }
}
