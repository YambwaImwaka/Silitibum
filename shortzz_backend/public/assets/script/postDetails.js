$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");

    var postId = $('#postId').val();
    console.log(postId);

    var commentId = -1;

    $("#commentsTable").DataTable({
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
            url: `${domainUrl}listPostComments`,
            data: function (data) {
                data.postId = postId;
            },
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

    $('#commentsTable').on("click", ".show-replies", function (e) {
        e.preventDefault();
        commentId = $(this).attr("rel");
        reloadDataTables(['commentRepliesTable'])
    });

    $("#commentRepliesTable").DataTable({
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
            url: `${domainUrl}listCommentReplies`,
            data: function (data) {
                data.commentId = commentId;
            },
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

    $("#commentsTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteComment_Admin`;
                        var formData = new FormData();
                        formData.append('id', itemId);
                        try {
                            doAjax(url, formData).then(function (response){
                                if(response.status){
                                    commentId = -1;
                                    reloadDataTables(['commentRepliesTable','commentsTable']);
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
    $("#commentRepliesTable").on("click", ".delete", function (e) {
        e.preventDefault();
        const itemId = $(this).attr("rel");

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
                        `${domainUrl}deleteCommentReply_Admin`;
                        var formData = new FormData();
                        formData.append('id', itemId);
                        try {
                            doAjax(url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['commentRepliesTable','commentsTable']);
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
