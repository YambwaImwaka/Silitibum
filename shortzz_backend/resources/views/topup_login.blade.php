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
                <div class="col-xxl-4 col-lg-5">
                    <div class="card">
                        <div class="card-header py-4 text-center bg-primary">
                            <img src="{{ asset('assets/img/logo.png') }}" alt="logo" height="22">
                        </div>
                        <div class="card-body p-4">
                            <div class="text-center w-75 m-auto">
                                <h4 class="text-dark-50 text-center pb-0 fw-bold">{{ __('Log In') }}</h4>
                                <p class="text-muted mb-4">{{ __('Use the same phone/email and password you use in the app') }}</p>
                            </div>
                            <form id="topUpLoginForm">
                                @csrf
                                <div class="mb-3">
                                    <label for="identity" class="form-label">{{ __('Phone or Email') }}</label>
                                    <input class="form-control" type="text" id="identity" name="identity" required
                                        placeholder="Enter your phone or email">
                                </div>
                                <div class="mb-3">
                                    <label for="password" class="form-label">{{ __('Password') }}</label>
                                    <input type="password" id="password" name="password" class="form-control" required
                                        placeholder="Enter your password">
                                </div>
                                <div class="mb-0 text-center">
                                    <button class="btn btn-primary w-100" type="submit">{{ __('Log In') }}</button>
                                </div>
                            </form>
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
            $("#topUpLoginForm").on("submit", function (event) {
                event.preventDefault();
                var formData = new FormData(this);
                $.ajax({
                    url: `${domainUrl}topup/login`,
                    type: "POST",
                    data: formData,
                    dataType: "json",
                    contentType: false,
                    cache: false,
                    processData: false,
                    success: function (response) {
                        if (response.status) {
                            window.location.href = `${domainUrl}topup`;
                        } else {
                            $.NotificationApp.send("Oops", response.message, "top-right", "rgba(0,0,0,0.2)", "error", 3000);
                        }
                    },
                    error: function () {
                        $.NotificationApp.send("Oops", "Something went wrong", "top-right", "rgba(0,0,0,0.2)", "error", 3000);
                    },
                });
            });
        });
    </script>
</body>

</html>
