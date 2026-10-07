$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".users").addClass("menuitem-active");

    $("#dummyUserTable").DataTable({
        autoWidth: false,
        processing: true,
        serverSide: true,
        serverMethod: "post",
        ordering: false,
        language: {
            paginate: {
                previous: "<i class='mdi mdi-chevron-left'>",
                next: "<i class='mdi mdi-chevron-right'>",
            },
        },
        ajax: {
            url: `${domainUrl}listDummyUsers`,
            data: function (data) {},
            error: (error) => {
                console.log(error);
            },
        },
        drawCallback: function () {
            $(".dataTables_paginate > .pagination").addClass(
                "pagination-rounded"
            );
        },
    });
    $("#moderatorsTable").DataTable({
        autoWidth: false,
        processing: true,
        serverSide: true,
        serverMethod: "post",
        ordering: false,
        language: {
            paginate: {
                previous: "<i class='mdi mdi-chevron-left'>",
                next: "<i class='mdi mdi-chevron-right'>",
            },
        },
        ajax: {
            url: `${domainUrl}listAllModerators`,
            data: function (data) {},
            error: (error) => {
                console.log(error);
            },
        },
        drawCallback: function () {
            $(".dataTables_paginate > .pagination").addClass(
                "pagination-rounded"
            );
        },
    });

    $("#usersTable").DataTable({
        autoWidth: false,
        processing: true,
        serverSide: true,
        serverMethod: "post",
        ordering: false,
        language: {
            paginate: {
                previous: "<i class='mdi mdi-chevron-left'>",
                next: "<i class='mdi mdi-chevron-right'>",
            },
        },
        ajax: {
            url: `${domainUrl}listAllUsers`,
            data: function (data) {},
            error: (error) => {
                console.log(error);
            },
        },
        drawCallback: function () {
            $(".dataTables_paginate > .pagination").addClass(
                "pagination-rounded"
            );
        },
    });

    $("#dummyUserTable").on("click", ".delete", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var itemId = $(this).attr("rel");
                    var url =
                        `${domainUrl}deleteDummyUser`;
                        var formData = new FormData();
                        formData.append('id', itemId);
                        try {
                            doAjax(url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['dummyUserTable']);
                                    showSuccessToast(response.message);
                                }else{
                                    showErrorToast(response.message);
                                }
                            });
                        } catch (error) {
                        console.log('Error! : ', error.message);
                            showErrorToast(error.message);
                        }
                }
            });
        });
    })

    // Permanent account deletion. Unlike every other action on this page this
    // one cannot be undone, so it asks the admin to type the username back
    // rather than offering a single "Yes" — a misplaced click on the row above
    // would otherwise destroy the wrong person's account and all their media.
    $(document).on("click", ".deleteUserAccount", function (e) {
        e.preventDefault();
        const userId = $(this).attr("rel");
        const username = $(this).data("username");
        // .data() hands back the decoded attribute — the raw username the user
        // chose — and SweetAlert's `html` renders it as markup, so escape it
        // for display. The typed confirmation still compares the raw value.
        const safeUsername = $("<div>").text(String(username)).html();

        checkUserType(() => {
            Swal.fire({
                icon: "warning",
                title: "Delete this account permanently?",
                html:
                    `This removes <b>${safeUsername}</b> and all their posts, comments, ` +
                    `likes, follows, stories, chats and uploaded files.<br><br>` +
                    `Payment records are kept for auditing, with the link to this ` +
                    `user removed.<br><br><b>This cannot be undone.</b><br><br>` +
                    `Type the username to confirm:`,
                input: "text",
                inputPlaceholder: username,
                showDenyButton: true,
                denyButtonText: "Cancel",
                confirmButtonText: "Delete permanently",
                confirmButtonColor: "#d33",
                inputValidator: (value) =>
                    value === String(username)
                        ? undefined
                        : "That does not match the username.",
            }).then((result) => {
                if (!result.isConfirmed) return;

                const formData = new FormData();
                formData.append("user_id", userId);
                doAjax(`${domainUrl}deleteUser_Admin`, formData)
                    .then(function (response) {
                        if (response.status) {
                            reloadDataTables(["usersTable", "moderatorsTable", "dummyUserTable"]);
                            showSuccessToast(response.message);
                        } else {
                            showErrorToast(response.message);
                        }
                    })
                    // Without this a rejected request (419 expired session,
                    // 500, dropped connection) never reaches .then() and the
                    // click looks like it did nothing at all. A try/catch
                    // cannot cover it — the failure is asynchronous.
                    .catch(function (xhr) {
                        console.error("deleteUser_Admin failed", xhr);
                        const status = xhr && xhr.status ? xhr.status : "network";
                        showErrorToast(
                            status === 419
                                ? "Session expired — reload the page and try again."
                                : `Delete failed (${status}). See console for details.`
                        );
                    });
            });
        });
    });

    $(document).on("change", ".freezeUser", function () {
         checkUserType(() => {
            const userId = $(this).attr("rel");
            const value = $(this).is(":checked") ? 1 : 0;
            $.ajax({
                    type: "POST",
                    url: `${domainUrl}userFreezeUnfreeze`,
                    data: {
                        user_id: userId,
                        is_freez: value,
                    },
                    success: function (response) {
                        if (response.status) {
                            showSuccessToast(response.message);
                            reloadDataTables(['usersTable','moderatorsTable', 'dummyUserTable']);
                        } else {
                            somethingWentWrongToast(response.message);
                        }
                    },
                    error: function () {
                        alert("An error occurred.");
                    },
                });
        });
    });
    $(document).on("change", ".moderatorUser", function () {
          checkUserType(() => {
        const userId = $(this).attr("rel");
        const value = $(this).is(":checked") ? 1 : 0;
        $.ajax({
            type: "POST",
            url: `${domainUrl}changeUserModeratorStatus`,
            data: {
                user_id: userId,
                is_moderator: value,
            },
            success: function (response) {
                if (response.status) {
                    showSuccessToast(response.message);
                    reloadDataTables(['usersTable','moderatorsTable','dummyUserTable']);
                } else {
                    somethingWentWrongToast(response.message);
                }
            },
            error: function () {
                alert("An error occurred.");
            },
            });
        });
    });




});
