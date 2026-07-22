<!DOCTYPE html>
<html lang="en" dir="ltr" class="light">

<head>
    <meta charset="utf-8" />
    <title>{{ __('Top Up Coins') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('assets/img/favicon.png') }}">
    <script src="{{ asset('assets/js/hyper-config.js') }}"></script>
    <link href="{{ asset('assets/css/vendor.min.css') }}" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/jquery-toast-plugin/jquery.toast.min.css') }}">
    <link href="{{ asset('assets/css/app-saas.min.css') }}" rel="stylesheet" type="text/css" id="app-style" />
    <link href="{{ asset('assets/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
</head>

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
                            @if(!$providerAvailable)
                                <div class="alert alert-warning">{{ __('Coin top-up is temporarily unavailable. Please try again later.') }}</div>
                            @else
                                <div id="pickStep">
                                    <h4 class="text-dark-50 pb-0 fw-bold">{{ __('Hi') }}, {{ $user->fullname }}</h4>
                                    <p class="text-muted mb-4">{{ __('Wallet balance') }}: {{ $user->coin_wallet ?? 0 }} {{ __('coins') }}</p>

                                    <div class="row" id="packageList">
                                        @foreach($packages as $package)
                                            <div class="col-6 mb-3">
                                                <div class="card border package-card" data-id="{{ $package->id }}" style="cursor:pointer;">
                                                    <div class="card-body text-center p-3">
                                                        <h4 class="mb-0">{{ $package->coin_amount }}</h4>
                                                        <p class="text-muted mb-0">{{ __('coins') }}</p>
                                                        <p class="text-primary fw-bold mb-0">{{ $settings->currency }}{{ $package->coin_plan_price }}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mb-3">
                                        <label for="phone" class="form-label">{{ __('Mobile Money Phone Number') }}</label>
                                        <input class="form-control" type="text" id="phone" placeholder="e.g. 260971234567">
                                    </div>
                                    <div class="mb-0 text-center">
                                        <button class="btn btn-primary w-100" id="buyBtn" disabled>{{ __('Buy Coins') }}</button>
                                    </div>
                                </div>

                                <div id="waitStep" class="text-center d-none">
                                    <div class="spinner-border text-primary mb-3" role="status"></div>
                                    <h5 id="waitMessage">{{ __('Check your phone to approve the payment') }}</h5>
                                    <p class="text-muted">{{ __('This page will update automatically once the payment is confirmed.') }}</p>
                                </div>

                                <div id="doneStep" class="text-center d-none">
                                    <h4 id="doneMessage"></h4>
                                    <button class="btn btn-outline-primary mt-3" onclick="window.location.reload()">{{ __('Buy More Coins') }}</button>
                                </div>
                            @endif
                        </div>
                    </div>
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
            var selectedPackageId = null;
            var pollTimer = null;

            $(".package-card").on("click", function () {
                $(".package-card").removeClass("border-primary bg-light");
                $(this).addClass("border-primary bg-light");
                selectedPackageId = $(this).data("id");
                $("#buyBtn").prop("disabled", false);
            });

            $("#buyBtn").on("click", function () {
                var phone = $("#phone").val().trim();
                if (!selectedPackageId || !phone) {
                    $.NotificationApp.send("Oops", "Pick a package and enter your phone number", "top-right", "rgba(0,0,0,0.2)", "error", 3000);
                    return;
                }
                $("#buyBtn").prop("disabled", true);
                $.ajax({
                    url: `${domainUrl}topup/charge`,
                    type: "POST",
                    data: {
                        _token: $('meta[name="csrf-token"]').attr('content'),
                        coin_package_id: selectedPackageId,
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
                            $("#buyBtn").prop("disabled", false);
                            $.NotificationApp.send("Oops", response.message, "top-right", "rgba(0,0,0,0.2)", "error", 3000);
                        }
                    },
                    error: function () {
                        $("#buyBtn").prop("disabled", false);
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
                                $("#doneMessage").text(status === "completed" ? "{{ __('Coins added to your wallet!') }}" : "{{ __('The payment did not go through. Please try again.') }}");
                            }
                        },
                    });
                }, 4000);
            }
        });
    </script>
</body>

</html>
