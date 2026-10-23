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

    $("#processingWithdrawalsTable").DataTable({
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
            url: `${domainUrl}listProcessingWithdrawals`,
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
    $("#failedWithdrawalsTable").DataTable({
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
            url: `${domainUrl}listFailedWithdrawals`,
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

    const ALL_TABLES = ['pendingWithdrawalsTable', 'rejectedWithdrawalsTable', 'completedWithdrawalsTable', 'processingWithdrawalsTable', 'failedWithdrawalsTable'];

    // One way to post an action: reload every tab on success, and surface
    // server errors (including a 403 for tester accounts) instead of leaving
    // the button looking like nothing happened.
    function postWithdrawalAction(action, itemId, extra) {
        var formData = new FormData();
        formData.append('id', itemId);
        Object.keys(extra || {}).forEach(function (key) {
            formData.append(key, extra[key]);
        });
        doAjax(`${domainUrl}${action}`, formData).then(function (response) {
            if (response.status) {
                reloadDataTables(ALL_TABLES);
                showSuccessToast(response.message);
            } else {
                showErrorToast(response.message);
            }
        }).catch(function (error) {
            showErrorToast((error.responseJSON && error.responseJSON.message) || 'Something went wrong.');
        });
    }

    function confirmThen(options, onConfirm) {
        checkUserType(() => {
            Swal.fire(Object.assign({
                icon: "info",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }, options)).then((result) => {
                if (result.isConfirmed) {
                    onConfirm(result);
                }
            });
        });
    }

    // Manual payouts: the admin has sent the money by hand. Shown on Pending
    // rows (manual provider) and on Processing rows parked by ManualGateway.
    $("#pendingWithdrawalsTable, #processingWithdrawalsTable").on("click", ".complete", function (e) {
        e.preventDefault();
        var itemId = $(this).attr("rel");
        confirmThen({
            title: "Mark as paid?",
            text: "Only confirm once the mobile money transfer has been sent to the number shown.",
        }, function () {
            postWithdrawalAction('completeWithdrawal', itemId);
        });
    });

    // The amount goes back to the creator's earnings; the reason is shown
    // to them in the app.
    $("#pendingWithdrawalsTable, #processingWithdrawalsTable").on("click", ".reject", function (e) {
        e.preventDefault();
        var itemId = $(this).attr("rel");
        confirmThen({
            title: "Reject this withdrawal?",
            text: "The amount will be returned to the creator's earnings.",
            input: "text",
            inputPlaceholder: "Reason shown to the creator (optional)",
        }, function (result) {
            postWithdrawalAction('rejectWithdrawal', itemId, { reason: result.value || '' });
        });
    });

    // Automated payouts: sends the money through the active provider now.
    $("#pendingWithdrawalsTable").on("click", ".approve", function (e) {
        e.preventDefault();
        var itemId = $(this).attr("rel");
        confirmThen({
            title: "Approve and send?",
            text: "The payout is sent immediately through the active payout provider.",
        }, function () {
            postWithdrawalAction('approveWithdrawal', itemId);
        });
    });

    $("#processingWithdrawalsTable").on("click", ".recheck", function (e) {
        e.preventDefault();
        var itemId = $(this).attr("rel");
        checkUserType(() => postWithdrawalAction('recheckWithdrawal', itemId));
    });

    $("#failedWithdrawalsTable").on("click", ".retry", function (e) {
        e.preventDefault();
        var itemId = $(this).attr("rel");
        confirmThen({
            title: "Retry this withdrawal?",
            text: "The amount is taken from the creator's earnings again and sent through the currently active provider.",
        }, function () {
            postWithdrawalAction('retryWithdrawal', itemId);
        });
    });

});
