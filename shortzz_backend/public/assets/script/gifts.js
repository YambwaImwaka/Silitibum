$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".gifts").addClass("menuitem-active");

    $("#editGiftForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var formId = '#editGiftForm';
                var url =  `${domainUrl}editGift`;
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        location.reload();
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
    $("#addGiftForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var formId = '#addGiftForm';
                var url =  `${domainUrl}addGift`;
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        location.reload();
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

    $("#languageTable").DataTable({
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
            url: `${domainUrl}languageList`,
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

    $("#gift-list").on("click", ".delete", function (e) {
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
                    var delete_url =
                        `${domainUrl}deleteGift`;
                        var formData = new FormData();
                        formData.append('id', id);
                        try {
                            doAjax(delete_url, formData).then(function (response){
                                if(response.status){
                                    location.reload();
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


    $('#gift-list').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var coinPrice = $(this).data("coinprice");
        var gifturl = $(this).data("gifturl");
        console.log(coinPrice);

        $("#editGiftId").val(id);
        $("#imgEditGiftPreview").attr('src',gifturl);
        $("#editGiftCoinPrice").val(coinPrice);

        modalShow('#editGiftModal');
    })


    previewImage('#inputAddGiftImage','#imgAddGiftPreview');
    previewImage('#inputEditGiftImage','#imgEditGiftPreview');

    $("#addGiftModal").on("hidden.bs.modal", function () {
        removeImageSource('#imgAddGiftPreview');
        resetForm('#addGiftForm');
    });
    $("#editGiftModal").on("hidden.bs.modal", function () {
        removeImageSource('#imgEditGiftPreview');
        resetForm('#editGiftForm');
    });

});
