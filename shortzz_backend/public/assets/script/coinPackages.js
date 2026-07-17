$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".coinPackages").addClass("menuitem-active");

    $("#coinPackagesTable").DataTable({
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
            url: `${domainUrl}listCoinPackages`,
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

    $("#editCoinPackageForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editCoinPackage`;
                var formId = '#editCoinPackageForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['coinPackagesTable']);
                        modalHide('#editPackageModal');
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
    $("#addCoinPackageForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addCoinPackage`;
                var formId = '#addCoinPackageForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['coinPackagesTable']);
                        modalHide('#addPackageModal');
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

    $("#coinPackagesTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteCoinPackage`;
                        var formData = new FormData();
                        formData.append('id', cat_id);
                        try {
                            doAjax(delete_cat_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['coinPackagesTable']);
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

    $('#coinPackagesTable').on("change", ".onOffCoinPackage", function () {
          checkUserType(() => {
            const id = $(this).attr("rel");
            const status = $(this).is(":checked") ? 1 : 0;
            $.ajax({
                type: "POST",
                url: `${domainUrl}changeCoinPackageStatus`,
                data: {
                    id: id,
                    status: status,
                },
                success: function (response) {
                    if (response.status) {
                        showSuccessToast(response.message);
                        reloadDataTables(['coinPackagesTable']);
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

    $('#coinPackagesTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var coinamount = $(this).data("coinamount");
        var coinprice = $(this).data("coinprice");
        var playstoreid = $(this).data("playstoreid");
        var appstoreid = $(this).data("appstoreid");
        var image = $(this).data("image");

        $("#editCoinPackageId").val(id);
        $("#edit_coin_amount").val(coinamount);
        $("#edit_coin_plan_price").val(coinprice);
        $("#edit_playstore_product_id").val(playstoreid);
        $("#edit_appstore_product_id").val(appstoreid);
        $("#imgEditCoinPackPreview").attr('src',image);

        modalShow('#editPackageModal');
    });

    previewImage('#inputAddCoinPackImage','#imgAddCoinPackPreview');
    previewImage('#inputEditCoinPackImage','#imgEditCoinPackPreview');

    $("#addPackageModal").on("hidden.bs.modal", function () {
        removeImageSource('#imgAddCoinPackPreview');
        resetForm('#addCoinPackageForm');
    });
    $("#editPackageModal").on("hidden.bs.modal", function () {
        removeImageSource('#imgEditCoinPackPreview');
        resetForm('#editCoinPackageForm');
    });

});
