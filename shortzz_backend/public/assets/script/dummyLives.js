$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".dummyLives").addClass("menuitem-active");

    $("#dummyLivesTable").DataTable({
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
            url: `${domainUrl}listDummyLives`,
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
    $("#addDummyLiveForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addDummyLive`;
                var formId = '#addDummyLiveForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['dummyLivesTable']);
                        modalHide('#addDummyLiveModal');
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



    $("#dummyLivesTable").on("click", ".delete", function (e) {
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
                    var cat_id = $(this).attr("rel");
                    var delete_cat_url =
                        `${domainUrl}deleteDummyLive`;
                        var formData = new FormData();
                        formData.append('id', cat_id);
                        try {
                            doAjax(delete_cat_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['dummyLivesTable']);
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

    $('#dummyLivesTable').on("change", ".onOffDummyLive", function () {
          checkUserType(() => {
        const id = $(this).attr("rel");
        const status = $(this).is(":checked") ? 1 : 0;
        $.ajax({
            type: "POST",
            url: `${domainUrl}changeDummyLiveStatus`,
            data: {
                id: id,
                status: status,
            },
            success: function (response) {
                if (response.status) {
                    showSuccessToast(response.message);
                    reloadDataTables(['dummyLivesTable']);
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

    $('#dummyLivesTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var link = $(this).data("link");
        var title = $(this).data("title");

        $("#editDummyLiveId").val(id);
        $("#edit_dummy_live_title").val(title);
        $("#edit_dummy_live_link").val(link);

        modalShow('#editDummyLiveModal');
    });


    $("#editDummyLiveForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editDummyLive`;
                var formId = '#editDummyLiveForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['dummyLivesTable']);
                        modalHide('#editDummyLiveModal');
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

    $("#addDummyLiveModal").on("hidden.bs.modal", function () {
        resetForm('#addDummyLiveForm');
    });
    $("#editDummyLiveModal").on("hidden.bs.modal", function () {
        resetForm('#editDummyLiveForm');
    });

});
