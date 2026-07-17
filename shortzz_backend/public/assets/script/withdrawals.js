$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".withdrawals").addClass("menuitem-active");

    $("#rejectedWithdrawalsTable").DataTable({
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
            url: `${domainUrl}listRejectedWithdrawals`,
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
    $("#completedWithdrawalsTable").DataTable({
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
            url: `${domainUrl}listCompletedWithdrawals`,
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
    $("#pendingWithdrawalsTable").DataTable({
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
            url: `${domainUrl}listPendingWithdrawals`,
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

    $("#pendingWithdrawalsTable").on("click", ".complete", function (e) {
        e.preventDefault();
        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                text: 'Do you really want to complete this withdrawal?',
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var item_id = $(this).attr("rel");
                    var action_url =
                        `${domainUrl}completeWithdrawal`;
                        var formData = new FormData();
                        formData.append('id', item_id);
                        try {
                            doAjax(action_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['pendingWithdrawalsTable','rejectedWithdrawalsTable','completedWithdrawalsTable']);
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
    });
    $("#pendingWithdrawalsTable").on("click", ".reject", function (e) {
        e.preventDefault();
        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                text: 'The coins will be returned to user wallet! Do you really want to reject this withdrawal? ',
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var item_id = $(this).attr("rel");
                    var action_url =
                        `${domainUrl}rejectWithdrawal`;
                        var formData = new FormData();
                        formData.append('id', item_id);
                        try {
                            doAjax(action_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['pendingWithdrawalsTable','rejectedWithdrawalsTable','completedWithdrawalsTable']);
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
    });



});
