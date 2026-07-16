$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".languages").addClass("menuitem-active");

    $("#addLanguageForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var formId = '#addLanguageForm';
                var url =  `${domainUrl}addLanguage`;
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['languageTable']);
                        modalHide('#addLanguageModal');
                        resetForm(formId);
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

    $("#languageTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteLanguage`;
                        var formData = new FormData();
                        formData.append('id', id);
                        try {
                            doAjax(delete_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['languageTable']);
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

    $('#languageTable').on("change", ".languageEnableDisableSwitch", function (e) {
        e.preventDefault();
        const itemId = $(this).attr("rel");
        if ($(this).prop("checked") == true) {
            var value = 1;
        } else {
            var value = 0;
        }

        checkUserType(() => {
            var url =
                `${domainUrl}languageEnableDisable`;
                var formData = new FormData();
                formData.append('id', itemId);
                formData.append('value', value);
                try {
                    doAjax(url, formData).then(function (response){
                        if(response.status){
                            reloadDataTables(['languageTable']);
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
    $('#languageTable').on("change", ".makeDefaultLanguage", function (e) {
        e.preventDefault();
        const itemId = $(this).attr("rel");

        checkUserType(() => {
            var url =
                `${domainUrl}makeDefaultLanguage`;
                var formData = new FormData();
                formData.append('id', itemId);
                try {
                    doAjax(url, formData).then(function (response){
                        if(response.status){
                            reloadDataTables(['languageTable']);
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

    $('#languageTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var title = $(this).data("title");
        var code = $(this).data("code");
        var localized_title = $(this).data("localized_title");

        $("#language_id").val(id);
        $("#edit_localized_title").val(localized_title);
        $("#edit_title").val(title);
        $("#edit_code").val(code);

        modalShow('#editLanguageModal');
    });

    $("#editLanguageForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}updateLanguage`;
                var formId = '#editLanguageForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['languageTable']);
                        modalHide('#editLanguageModal');
                        resetForm(formId);
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

});
