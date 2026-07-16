$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".hashtags").addClass("menuitem-active");



    $("#hashtagsTable").DataTable({
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
            url: `${domainUrl}listAllHashtags`,
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

    $("#addHashtagForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addHashtag_Admin`;
                var formId = '#addHashtagForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['hashtagsTable']);
                        modalHide('#addHashtagModal');
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

    $("#hashtagsTable").on("click", ".delete", function (e) {
        e.preventDefault();
        var postCount = $(this).data("postcount");
        if(postCount > 0){
            showErrorToast('This hashtag has post attached to it, you can not delete this hashtag!');
            return;
        }

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var cat_id = $(this).attr("rel");
                    var delete_cat_url =
                        `${domainUrl}deleteHashtag`;
                        var formData = new FormData();
                        formData.append('id', cat_id);
                        try {
                            doAjax(delete_cat_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['hashtagsTable']);
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



});
