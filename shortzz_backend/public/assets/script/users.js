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
