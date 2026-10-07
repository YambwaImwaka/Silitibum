$(document).ready(function () {
    $("#loginForm").on("submit", function (event) {
        event.preventDefault();
        var formData = new FormData($("#loginForm")[0]);
        $.ajax({
            url: `${domainUrl}loginForm`,
            type: "POST",
            data: formData,
            dataType: "json",
            contentType: false,
            cache: false,
            processData: false,
            success: function (response) {
                console.log(response);
                if (response.status) {
                    window.location.href = `${domainUrl}dashboard`;
                } else {
                    $.NotificationApp.send(
                        "Oops",
                        response.message,
                        "top-right",
                        "rgba(0,0,0,0.2)",
                        "error",
                        3000
                    );
                }
            },
            error: function (err) {
                console.log(err);
                // loginForm is rate limited; without this a locked-out admin
                // just sees the button do nothing.
                if (err.status === 429) {
                    $.NotificationApp.send(
                        "Too many attempts",
                        "Please wait a minute and try again.",
                        "top-right",
                        "rgba(0,0,0,0.2)",
                        "error",
                        3000
                    );
                }
            },
        });
    });
});
