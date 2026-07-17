$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".notifications").addClass("menuitem-active");

    $("#notificationTable").DataTable({
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
            url: `${domainUrl}listAdminNotifications`,
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


    $("#editAdminNotificationForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editAdminNotification`;
                var formId = '#editAdminNotificationForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['notificationTable']);
                        modalHide('#editNotificationModal');
                        resetForm(formId);
                        showSuccessToast(response.message);
                    }else{
                        showErrorToast(response.message);
                    }
                });
            } catch (error) {
            console.log('Error! : ', error.message);
                showErrorToast(error.message);
            }
        });
    });
    $("#addAdminNotificationForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addAdminNotification`;
                var formId = '#addAdminNotificationForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['notificationTable']);
                        modalHide('#addNotificationModal');
                        resetForm(formId);
                        showSuccessToast(response.message);
                    }else{
                        showErrorToast(response.message);
                    }
                });
            } catch (error) {
            console.log('Error! : ', error.message);
                showErrorToast(error.message);
            }
        });
    });

    $("#notificationTable").on("click", ".repeat", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                text: 'Do you really want to proceed? Repeat will resend push notification to all users!',
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var item_id = $(this).attr("rel");
                    var action_url =
                        `${domainUrl}repeatAdminNotification`;
                        var formData = new FormData();
                        formData.append('id', item_id);
                        try {
                            doAjax(action_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['notificationTable']);
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
    $("#notificationTable").on("click", ".delete", function (e) {
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
                    var item_id = $(this).attr("rel");
                    var action_url =
                        `${domainUrl}deleteAdminNotification`;
                        var formData = new FormData();
                        formData.append('id', item_id);
                        try {
                            doAjax(action_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['notificationTable']);
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

    $('#notificationTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var title = $(this).data("title");
        var description = $(this).data("description");
        console.log(description);

        $("#editId").val(id);
        $("#edit_title").val(title);
        $("#edit_description").text(description);

        modalShow('#editNotificationModal');
    });

});
