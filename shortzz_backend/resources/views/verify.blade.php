<!DOCTYPE html>
<html lang="en" dir="ltr" class="light">

<head>
    <meta charset="utf-8" />
    <title>{{ __('Get Verified') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('assets/img/favicon.png') }}">
    <script src="{{ asset('assets/js/hyper-config.js') }}"></script>
    <link href="{{ asset('assets/css/vendor.min.css') }}" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/jquery-toast-plugin/jquery.toast.min.css') }}">
    <link href="{{ asset('assets/css/app-saas.min.css') }}" rel="stylesheet" type="text/css" id="app-style" />
    <link href="{{ asset('assets/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <!-- Brand theme — must stay AFTER app-saas.min.css to override its tokens -->
    <link href="{{ asset('assets/css/brand.css') }}" rel="stylesheet" type="text/css" />
</head>

@php
    $currency = $status['currency'];
    $price = number_format((float) $status['price'], 2);
    $days = $status['days'];
    $period = $days > 0 ? __(':days days', ['days' => $days]) : __('for good');
    $until = $status['verified_until'] ? \Illuminate\Support\Carbon::parse($status['verified_until']) : null;
@endphp

<body class="authentication-bg position-relative">
    <div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5 position-relative">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xxl-5 col-lg-6">
                    <div class="card">
                        <div class="card-header py-4 text-center bg-primary d-flex align-items-center justify-content-between px-4">
                            <img src="{{ asset('assets/img/logo.png') }}" alt="logo" height="22">
                            <a href="{{ route('topup.logout') }}" class="text-white">{{ __('Log Out') }}</a>
                        </div>
                        <div class="card-body p-4">
                            <div class="text-center mb-3">
                                <img src="{{ asset('assets/img/ic_verify.png') }}" alt="" width="56" height="56">
                            </div>
                            <h4 class="text-center fw-bold mb-1">{{ __('Hi') }}, {{ $user->fullname }}</h4>

                            @if($status['verified'] && $status['source'] === 'admin')
                                <p class="text-center text-muted mb-0">{{ __('You have the blue tick. It was given by Silitibum and never expires.') }}</p>
                            @elseif(!$status['enabled'] || (float) $status['price'] <= 0)
                                <p class="text-center text-muted mb-0">{{ __('Blue ticks are not on sale right now. Please check back later.') }}</p>
                            @elseif(!$providerAvailable)
                                <div class="alert alert-warning mt-3 mb-0">{{ __('Mobile money payments are temporarily unavailable. Please try again later.') }}</div>
                            @elseif($status['verified'] && $status['source'] === 'paid' && $until === null)
                                <p class="text-center text-muted mb-0">{{ __('You have the blue tick, and it never expires.') }}</p>
                            @else
                                <div id="pickStep">
                                    @if($status['verified'] && $until !== null)
                                        <p class="text-center text-muted mb-4">
                                            {{ __('Your blue tick is active until :date.', ['date' => $until->format('j M Y')]) }}
                                            {{ __('Paying now adds :period to it.', ['period' => $period]) }}
                                        </p>
                                    @else
                                        <p class="text-center text-muted mb-4">
                                            {{ __('Show people it is really you. The blue tick appears next to your name everywhere on Silitibum.') }}
                                        </p>
                                    @endif

                                    <div class="card border border-primary mb-4">
                                        <div class="card-body text-center p-3">
                                            <h3 class="mb-0">{{ $currency }}{{ $price }}</h3>
                                            <p class="text-muted mb-0">{{ $days > 0 ? __('for :days days', ['days' => $days]) : __('once, never expires') }}</p>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="phone" class="form-label">{{ __('Mobile Money Phone Number') }}</label>
                                        <input class="form-control" type="text" id="phone" placeholder="{{ __('e.g. 0971234567') }}">
                                        <div class="form-text">{{ __('Airtel Money, MTN MoMo or Zamtel Kwacha. With or without the country code.') }}</div>
                                    </div>
                                    <div class="mb-0 text-center">
                                        <button class="btn btn-primary w-100" id="payBtn">{{ __('Pay :amount', ['amount' => $currency . $price]) }}</button>
                                    </div>
                                </div>

                                <div id="waitStep" class="text-center d-none">
                                    <div class="spinner-border text-primary mb-3" role="status"></div>
                                    <h5 id="waitMessage">{{ __('Check your phone to approve the payment') }}</h5>
                                    <p class="text-muted">{{ __('This page will update automatically once the payment is confirmed.') }}</p>
                                </div>

                                <div id="doneStep" class="text-center d-none">
                                    <h4 id="doneMessage"></h4>
                                    <p class="text-muted" id="doneHint"></p>
                                </div>
                            @endif
                        </div>
                    </div>
                    <p class="text-center text-muted">
                        <a href="{{ route('topup.show') }}" class="text-muted">{{ __('Top up coins') }}</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <input type="hidden" value="{{ env('APP_URL') }}" id="appUrl">
    <script src="{{ asset('assets/js/vendor.min.js') }}"></script>
    <script src="{{ asset('assets/js/app.min.js') }}"></script>
    <script src="{{ asset('assets/js/app.js') }}"></script>
    <script src="{{ asset('assets/vendor/jquery-toast-plugin/jquery.toast.min.js') }}"></script>
    <script>
        $(document).ready(function () {
            var pollTimer = null;

            $("#payBtn").on("click", function () {
                var phone = $("#phone").val().trim();
                if (!phone) {
                    $.NotificationApp.send("Oops", "Enter your mobile money number", "top-right", "rgba(0,0,0,0.2)", "error", 3000);
                    return;
                }
                $("#payBtn").prop("disabled", true);
                $.ajax({
                    url: `${domainUrl}verify/charge`,
                    type: "POST",
                    data: {
                        _token: $('meta[name="csrf-token"]').attr('content'),
                        phone: phone,
                    },
                    dataType: "json",
                    success: function (response) {
                        if (response.status) {
                            $("#pickStep").addClass("d-none");
                            $("#waitStep").removeClass("d-none");
                            $("#waitMessage").text(response.message);
                            pollStatus(response.data.topup_id);
                        } else {
                            $("#payBtn").prop("disabled", false);
                            $.NotificationApp.send("Oops", response.message, "top-right", "rgba(0,0,0,0.2)", "error", 3000);
                        }
                    },
                    error: function () {
                        $("#payBtn").prop("disabled", false);
                        $.NotificationApp.send("Oops", "Something went wrong", "top-right", "rgba(0,0,0,0.2)", "error", 3000);
                    },
                });
            });

            function pollStatus(topupId) {
                pollTimer = setInterval(function () {
                    $.ajax({
                        url: `${domainUrl}topup/status`,
                        type: "POST",
                        data: {
                            _token: $('meta[name="csrf-token"]').attr('content'),
                            topup_id: topupId,
                        },
                        dataType: "json",
                        success: function (response) {
                            if (!response.status) return;
                            var status = response.data.status;
                            if (status === "completed" || status === "failed") {
                                clearInterval(pollTimer);
                                $("#waitStep").addClass("d-none");
                                $("#doneStep").removeClass("d-none");
                                if (status === "completed") {
                                    $("#doneMessage").text("{{ __('You are verified!') }}");
                                    $("#doneHint").text("{{ __('Go back to the Silitibum app to see your blue tick.') }}");
                                } else {
                                    $("#doneMessage").text("{{ __('The payment did not go through.') }}");
                                    $("#doneHint").html('<a href="{{ route('verify.show') }}">{{ __('Try again') }}</a>');
                                }
                            }
                        },
                    });
                }, 4000);
            }
        });
    </script>
</body>

</html>
