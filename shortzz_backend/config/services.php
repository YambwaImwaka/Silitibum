<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Base FCM topic for admin broadcasts. The app subscribes to
     * "<topic>_android" / "<topic>_ios" — see notificationTopic in
     * lib/utilities/const_res.dart, which must stay in sync with this.
     *
     * Read through config (not a bare env() at the call site) so it survives
     * `php artisan config:cache`, and defaulted rather than left null: with
     * NOTIFICATION_TOPIC unset the controllers were broadcasting to the topic
     * "_android", which FCM accepts as valid and silently delivers to nobody.
     */
    'notification_topic' => env('NOTIFICATION_TOPIC', 'silitibum'),

];
