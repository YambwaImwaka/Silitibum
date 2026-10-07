<?php

namespace App\Http\Controllers;

use App\Models\AdminNotifications;
use App\Models\Constants;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Models\Livestream;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Google\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    //

    /**
     * Client-requested push. This used to forward whatever FCM payload the
     * client sent, with the server's own credentials — so any account could
     * push any text to the "silitibum_android" topic every install subscribes
     * to, i.e. to every user at once, or to any device token it held.
     *
     * Likes, comments, follows, gifts and chat are all pushed server-side
     * now (GlobalFunction::pushToUser). The shipped app still sends exactly
     * two kinds through here, and those are the only ones accepted:
     *   - its own device token (the registration-bonus notice), and
     *   - its own "<userId>_android" / "<userId>_ios" topic, i.e. its own
     *     followers, while it is hosting a live stream ("X is live").
     * The message is rebuilt from known fields rather than forwarded, so
     * nothing else in it (links, click actions, conditions) reaches FCM.
     */
    function pushNotificationToSingleUser(Request $request)
    {
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        $message = $request->json('message');
        if (!is_array($message) || isset($message['condition'])) {
            return GlobalFunction::sendSimpleResponse(false, 'invalid notification');
        }

        if (!empty($message['topic'])) {
            $topic = (string) $message['topic'];
            if (!in_array($topic, [$user->id . '_android', $user->id . '_ios'], true)) {
                return GlobalFunction::sendSimpleResponse(false, 'not allowed');
            }
            $isLive = Livestream::where('host_id', $user->id)
                ->where('status', Livestream::STATUS_LIVE)
                ->exists();
            if (!$isLive) {
                return GlobalFunction::sendSimpleResponse(false, 'not allowed');
            }
            $clean = ['topic' => $topic];
        } elseif (!empty($message['token'])) {
            if (empty($user->device_token) || !hash_equals((string) $user->device_token, (string) $message['token'])) {
                return GlobalFunction::sendSimpleResponse(false, 'not allowed');
            }
            $clean = ['token' => (string) $message['token']];
        } else {
            return GlobalFunction::sendSimpleResponse(false, 'invalid notification');
        }

        $text = function ($value, int $max) {
            return is_scalar($value) ? mb_substr((string) $value, 0, $max) : null;
        };

        if (isset($message['notification']) && is_array($message['notification'])) {
            $clean['notification'] = array_filter([
                'title' => $text($message['notification']['title'] ?? null, 200),
                'body' => $text($message['notification']['body'] ?? null, 500),
            ], fn ($v) => $v !== null);
        }

        if (isset($message['data']) && is_array($message['data'])) {
            // FCM data values must be strings. notification_data is the JSON
            // the receiving app parses, so it is passed whole, never cut —
            // FCM itself rejects data over its 4KB limit.
            $data = array_filter([
                'title' => $text($message['data']['title'] ?? null, 200),
                'body' => $text($message['data']['body'] ?? null, 500),
                'type' => $text($message['data']['type'] ?? null, 32),
                'notification_data' => $text($message['data']['notification_data'] ?? null, 4096),
            ], fn ($v) => $v !== null);
            if (!empty($data)) {
                $clean['data'] = $data;
            }
        }

        if (isset($message['apns'])) {
            // The fixed shape the app sends; nothing client-supplied.
            $clean['apns'] = [
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => ['sound' => 'default', 'content-available' => 1]],
            ];
        }

        $result = GlobalFunction::sendPushNotification(['message' => $clean]);
        return GlobalFunction::sendSimpleResponse((bool) $result, $result ? 'notification sent' : 'notification not sent');
    }

    public function fetchActivityNotifications(Request $request)
    {
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => 'this user is freezed!'];
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

        $query = UserNotification::orderBy('id', 'DESC')
            ->where('to_user_id', $user->id)
            ->with(['from_user:' . Constants::userPublicFields])
            ->limit($request->limit);

        if ($request->has('last_item_id')) {
            $query->where('id', '<', $request->last_item_id);
        }

        $items = $query->get();

        // Stale rows — the post/comment/gift they point at has since been
        // deleted — hydrate to null. Drop them instead of shipping cards the
        // app renders blank. Follow notifications legitimately carry no
        // payload (they render from from_user alone), so they are kept.
        $items = $items->filter(function ($item) use ($user) {
            $item->data = GlobalFunction::getNotificationItemData($item, $user);
            return $item->data !== null || $item->type == Constants::notify_follow_user;
        })->values();

        return GlobalFunction::sendDataResponse(true, 'activity notifications fetched successfully', $items);
    }
    public function fetchAdminNotifications(Request $request)
    {
        $token = $request->header('authtoken');
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => 'this user is freezed!'];
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

        $query = AdminNotifications::orderBy('id', 'DESC')->limit($request->limit);

        if ($request->has('last_item_id')) {
            $query->where('id', '<', $request->last_item_id);
        }
        $items = $query->get();

        return GlobalFunction::sendDataResponse(true, 'admin notifications fetched successfully', $items);
    }

    public function editAdminNotification(Request $request)
    {
        $item = AdminNotifications::find($request->id);
        if ($request->has('image')) {
            if ($item->image != null) {
                GlobalFunction::deleteFile($item->image);
            }
            $item->image = GlobalFunction::saveFileAndGivePath($request->image);
        }
        $item->title = $request->title;
        $item->description = $request->description;

        $item->save();

        return GlobalFunction::sendSimpleResponse(true, 'Notification edited successfully');
    }
    public function addAdminNotification(Request $request)
    {
        $item = new AdminNotifications();
        $item->title = $request->title;
        $item->description = $request->description;
        if ($request->has('image')) {
            $item->image = GlobalFunction::saveFileAndGivePath($request->image);
        }
        $item->save();

        // Generate Payloads
        $pushTokenAndroid = config('services.notification_topic') . '_android';
        $pushTokenIOS = config('services.notification_topic') . '_ios';
        $androidPayload = GlobalFunction::generatePushNotificationPayload(Constants::android, Constants::pushTypeTopic, $pushTokenAndroid, $item->title, $item->description, $item->image);
        $iosPayLoad = GlobalFunction::generatePushNotificationPayload(Constants::iOS, Constants::pushTypeTopic, $pushTokenIOS, $item->title, $item->description, $item->image);
        // Sending Push
        GlobalFunction::sendPushNotification($androidPayload);
        GlobalFunction::sendPushNotification($iosPayLoad);

        return GlobalFunction::sendSimpleResponse(true, 'Notification sent successfully');
    }

    public function listAdminNotifications(Request $request)
    {
        $query = AdminNotifications::query();
        $totalData = $query->count();

        $limit = $request->input('length');
        $start = $request->input('start');
        $searchValue = $request->input('search.value');

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('title', 'LIKE', "%{$searchValue}%")->orWhere('description', 'LIKE', "%{$searchValue}%");
            });
        }
        $totalFiltered = $query->count();

        $result = $query->offset($start)->limit($limit)->orderBy('id', 'DESC')->get();

        $data = $result->map(function ($item) {
            $image = '';
            if ($item->image) {
                $imgUrl = GlobalFunction::generateFileUrl($item->image);
                $image = "<img class='rounded' width='130' height='80' src='{$imgUrl}' alt=''>";
            }
            $title = "<h5>" . e($item->title) . "</h5>";
            $description = "<p class='m-0'>" . e($item->description) . "</p>";
            $notification = '<div class="reportDescription">' . $image . $title . $description . '</div>';

            $repeat = "<a href='#'
                        rel='{$item->id}'
                        class='action-btn repeat d-flex align-items-center justify-content-center btn border rounded-2 text-info ms-1'>
                        <i class='uil-repeat'></i>
                        </a>";

            $edit = "<a href='#'
                        rel='{$item->id}'
                        data-title='{$item->title}'
                        data-description='{$item->description}'
                        class='action-btn edit d-flex align-items-center justify-content-center btn border rounded-2 text-success ms-1'>
                        <i class='uil-pen'></i>
                        </a>";

            $delete = "<a href='#'
                          rel='{$item->id}'
                          class='action-btn delete d-flex align-items-center justify-content-center btn border rounded-2 text-danger ms-1'>
                            <i class='uil-trash-alt'></i>
                        </a>";
            $action = "<span class='d-flex justify-content-end align-items-center'>{$repeat}{$edit}{$delete}</span>";

            return [$notification, GlobalFunction::formateDatabaseTime($item->created_at), $action];
        });

        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => intval($totalFiltered),
            'data' => $data,
        ];

        return response()->json($json_data);
    }

    public function repeatAdminNotification(Request $request)
    {
        $item = AdminNotifications::find($request->id);
        if ($item != null) {
            // Generate Payloads
            $pushTokenAndroid = config('services.notification_topic') . '_android';
            $pushTokenIOS = config('services.notification_topic') . '_ios';
            $androidPayload = GlobalFunction::generatePushNotificationPayload(Constants::android, Constants::pushTypeTopic, $pushTokenAndroid, $item->title, $item->description, $item->image);
            $iosPayLoad = GlobalFunction::generatePushNotificationPayload(Constants::iOS, Constants::pushTypeTopic, $pushTokenIOS, $item->title, $item->description, $item->image);
            // Sending Push
            GlobalFunction::sendPushNotification($androidPayload);
            GlobalFunction::sendPushNotification($iosPayLoad);

            return GlobalFunction::sendSimpleResponse(true, 'Notification repeated successfully');
        }

        return GlobalFunction::sendSimpleResponse(true, 'Notification repeated successfully');
    }
    public function deleteAdminNotification(Request $request)
    {
        $item = AdminNotifications::find($request->id);
        if ($item->image != null) {
            GlobalFunction::deleteFile($item->image);
        }
        $item->delete();

        return GlobalFunction::sendSimpleResponse(true, 'Notification deleted successfully');
    }

    public function notifications()
    {
        return view('notifications');
    }
}
