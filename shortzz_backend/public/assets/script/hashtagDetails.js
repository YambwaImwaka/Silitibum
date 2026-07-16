$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");

    var hashtag = $('#hashtag').val();


    $("#hashtagPostsTable").DataTable({
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
            url: `${domainUrl}listHashtagPosts`,
            data: function (data) {
                data.hashtag = hashtag
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

    $(document).on("click", ".delete-post", function (e) {
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
                    var id = $(this).attr("rel");
                    var delete_url = `${domainUrl}deletePost_Admin`;
                    var formData = new FormData();
                    formData.append('id', id);
                    try {
                        doAjax(delete_url, formData).then(function (response){
                            if(response.status){
                                reloadDataTables(['hashtagPostsTable']);
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
