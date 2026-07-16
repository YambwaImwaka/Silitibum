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
            },
        });
    });

    $("#forgotPasswordForm").on("submit", function (event) {
        event.preventDefault();
        var formData = new FormData(this);

        var newPassword = $("#new_password").val();
        var confirmPassword = $("#confirm_password").val();

        if (newPassword !== confirmPassword) {
            showErrorToast("Passwords do not match!");
            return;
        }

        $.ajax({
            url: `${domainUrl}forgotPasswordForm`,
            type: "POST",
            data: formData,
            dataType: "json",
            contentType: false,
            processData: false,
            success: function (response) {
                if (response.status) {
                    $("#forgotPasswordModal").modal("hide");
                    resetForm("#forgotPasswordForm");
                    resetForm("#loginForm");
                    showSuccessToast(response.message);
                } else {
                    showErrorToast(response.message);
                }
            },
            error: function (err) {
                console.log(err);
            },
        });
    });
});
