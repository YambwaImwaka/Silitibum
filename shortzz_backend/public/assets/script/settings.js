$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".settings").addClass("menuitem-active");

    $("#deepARFiltersTable").DataTable({
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
            url: `${domainUrl}listDeepARFilters`,
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
    $("#userLevelTable").DataTable({
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
            url: `${domainUrl}listUserLevels`,
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
    $("#withdrawalGatewayTable").DataTable({
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
            url: `${domainUrl}listWithdrawalGateways`,
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
    $("#reportReasonsTable").DataTable({
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
            url: `${domainUrl}listReportReasons`,
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

    $("#onboardingScreenTable").DataTable({
        autoWidth: false,
        processing: true,
        serverSide: true,
        serverMethod: "post",
        ordering: false,
        bPaginate: false,
        language: {
            paginate: {
                previous: "<i class='mdi mdi-chevron-left'>",
                next: "<i class='mdi mdi-chevron-right'>",
            },
        },
        ajax: {
            url: `${domainUrl}onboardingScreensList`,
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

    $(document).on("change", "#switchAdmobiOSStatus", function (event) {
        event.preventDefault();

        checkUserType(() => {

            if ($(this).prop("checked") == true) {
                var value = 1;
            } else {
                value = 0;
            }

            var updateEventStatusUrl =
                `${domainUrl}changeiOSAdmobStatus` + "/" + value;

            $.getJSON(updateEventStatusUrl).done(function (data) {
                if (data.status) {
                    showSuccessToast(data.message);
                } else {
                    somethingWentWrongToast();
                }
                });
        });
    });
    $(document).on("change", "#switchAdmobAndroidStatus", function (event) {
        event.preventDefault();
        checkUserType(() => {
            if ($(this).prop("checked") == true) {
                var value = 1;
            } else {
                value = 0;
            }

            var updateEventStatusUrl =
                `${domainUrl}changeAndroidAdmobStatus` + "/" + value;

            $.getJSON(updateEventStatusUrl).done(function (data) {
                if (data.status) {
                    showSuccessToast(data.message);
                } else {
                    somethingWentWrongToast();
                }
            });
        });
    });

    $("#limitSettingForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#limitSettingForm';
            var formdata = collectFormData(formId);
            var url = `${domainUrl}saveLimitSettings`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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
    $("#livestreamSettingForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#livestreamSettingForm';
            var formdata = collectFormData(formId);
            var live_battle = $("#switchPKBattle").prop("checked") == true ? 1 : 0;
            var live_dummy_show = $("#switchDummyLiveShow").prop("checked") == true ? 1 : 0;

            formdata.append('live_battle', live_battle);
            formdata.append('live_dummy_show', live_dummy_show);

            var url = `${domainUrl}saveLiveStreamSettings`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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
    $("#addDeepARFilterModal").on("hidden.bs.modal", function () {
        removeImageSource('#imgDeepARFilterPreview');
    });
    $("#basicSettingForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#basicSettingForm';
            var formdata = collectFormData(formId);
            var isCompress = $("#switchCompressVideosStatus").prop("checked") == true ? 1 : 0;
            var is_withdrawal_on = $("#switchWithdrawal").prop("checked") == true ? 1 : 0;
            var watermark_status = $("#switchWatermarkStatus").prop("checked") == true ? 1 : 0;
            var registration_bonus_status = $("#switcRegistrationBonusStatus").prop("checked") == true ? 1 : 0;

            formdata.append('is_compress', isCompress);
            formdata.append('is_withdrawal_on', is_withdrawal_on);
            formdata.append('watermark_status', watermark_status);
            formdata.append('registration_bonus_status', registration_bonus_status);

            var url = `${domainUrl}saveBasicSettings`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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
    $("#deepARSettingsForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#deepARSettingsForm';
            var formdata = collectFormData(formId);
            var value = $("#switchDeepARCamera").prop("checked") == true ? 1 : 0;
            formdata.append('is_deepAR', value);

            var url = `${domainUrl}saveDeepARSettings`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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
    $("#gifSettingForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#gifSettingForm';
            var formdata = collectFormData(formId);
            var value = $("#switchGifSupport").prop("checked") == true ? 1 : 0;
            formdata.append('gif_support', value);

            var url = `${domainUrl}saveGIFSettings`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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

    $("#deepLinkingForm").on("submit", function (e) {
        e.preventDefault();
        checkUserType(() => {
            var url = `${domainUrl}saveDeeplinkSettings`;
            var formId = "#deepLinkingForm";
            var formData = collectFormData(formId);
            showFormSpinner(formId);
            try {
                doAjax(url, formData).then(function (response) {
                    hideFormSpinner(formId);
                    if (response.status) {
                        showSuccessToast(response.message);
                    } else {
                        showErrorToast(response.message);
                    }
                });
            } catch (error) {
                console.log("Error! : ", error.message);
                showErrorToast(error.message);
            }
        });
    });

    $("#androidDeepLinkingForm").on("submit", function (e) {
        e.preventDefault();
        checkUserType(() => {
            var url = `${domainUrl}androidDeepLinking`;
            var formId = "#androidDeepLinkingForm";
            var formData = collectFormData(formId);

            let shaValues = [];
            $(".sha-input").each(function () {
                let val = $(this).val().trim();
                if (val) shaValues.push(val);
            });
            formData["sha_256"] = shaValues.join(",");

            showFormSpinner(formId);
            try {
                doAjax(url, formData).then(function (response) {
                    hideFormSpinner(formId);
                    if (response.status) {
                        showSuccessToast(response.message);
                    } else {
                        showErrorToast(response.message);
                    }
                });
            } catch (error) {
                console.log("Error! : ", error.message);
                showErrorToast(error.message);
            }
        });
    });

    $("#iOSDeepLinkingForm").on("submit", function (e) {
        e.preventDefault();
        checkUserType(() => {
            var url = `${domainUrl}iOSDeepLinking`;
            var formId = "#iOSDeepLinkingForm";
            var formData = collectFormData(formId);
            showFormSpinner(formId);
            try {
                doAjax(url, formData).then(function (response) {
                    hideFormSpinner(formId);
                    if (response.status) {
                        showSuccessToast(response.message);
                    } else {
                        showErrorToast(response.message);
                    }
                });
            } catch (error) {
                console.log("Error! : ", error.message);
                showErrorToast(error.message);
            }
        });
    });

    $("#contentModerationSettingForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#contentModerationSettingForm';
            var formdata = collectFormData(formId);
            var is_content_moderation = $("#switchContentModeration").prop("checked") == true ? 1 : 0;
            formdata.append('is_content_moderation', is_content_moderation);

            var url = `${domainUrl}saveContentModerationSettings`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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
    $("#brandSettingForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#brandSettingForm';
            var formdata = collectFormData(formId);

            var url = `${domainUrl}saveSettings`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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

    $("#changePasswordForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#changePasswordForm';
            var formdata = collectFormData(formId);
            var url = `${domainUrl}changePassword`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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

    $("#admobForm").on("submit", function (event) {
        event.preventDefault();
        checkUserType(function () {
            var formId = '#admobForm';
            var formdata = collectFormData(formId);
            var url = `${domainUrl}admobSettingSave`;
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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

    $("#addDeepARFilterForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addDeepARFilter`;
                var formId = '#addDeepARFilterForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['deepARFiltersTable']);
                        modalHide('#addDeepARFilterModal');
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

    var toolbarOptions = [
        [{ font: [] }, { size: [] }],
        ["bold", "italic", "underline", "strike"],
        [{ color: [] }, { background: [] }],
        [{ script: "super" }, { script: "sub" }],
        [{ header: [!1, 1, 2, 3, 4, 5, 6] }, "blockquote", "code-block"],
        [
            { list: "ordered" },
            { list: "bullet" },
            { indent: "-1" },
            { indent: "+1" },
        ],
        ["direction", { align: [] }],
        ["link", "image"],
        ["clean"],
    ];

    var quillPrivacy = new Quill("#privacyEditor", {
        theme: "snow",
        modules: {
            toolbar: {
                container: toolbarOptions,
                handlers: {
                    image: () => imageHandler(quillPrivacy),
                },
            },
        },
    });

    var quillTerms = new Quill("#termsOfUsesEditor", {
        theme: "snow",
        modules: {
            toolbar: {
                container: toolbarOptions,
                handlers: {
                    image: () => imageHandler(quillTerms),
                },
            },
        },
    });

    function imageHandler(editorInstance) {
        const input = document.createElement("input");
        var domainUrl = $("#appUrl").val();
        input.setAttribute("type", "file");
        input.setAttribute("accept", "image/*");
        input.click();

        input.onchange = async () => {
            const file = input.files[0];
            if (file) {
                const formData = new FormData();
                formData.append("image", file);

                $.ajax({
                    type: "POST",
                    url: `${domainUrl}imageUploadInEditor`,
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function ({ imagePath }) {
                        const url = `${domainUrl}storage/${imagePath}`;
                        if (url) {
                            const range = editorInstance.getSelection();
                            editorInstance.insertEmbed(
                                range.index,
                                "image",
                                url
                            );

                            const img = editorInstance.container.querySelector(
                                `img[src="${url}"]`
                            );
                            if (img) {
                                img.setAttribute("width", "100%");
                            }
                        }
                    },
                    error: function (error) {
                        console.error("Error uploading image:", error);
                    },
                });
            }
        };
    }

    function addWidthToExistingImages(editorInstance) {
        const imgs = editorInstance.container.querySelectorAll("img");
        imgs.forEach((img) => {
            img.setAttribute("width", "100%");
        });
    }

    addWidthToExistingImages(quillPrivacy);
    addWidthToExistingImages(quillTerms);

    function handleFormSubmission(
        formId,
        apiUrl,
        editorInstance,
        editorKey,
        successMessage
    ) {
        $(formId).on("submit", function (event) {
            event.preventDefault();
            const editorContent = editorInstance.root.innerHTML;
            if (!editorContent.trim()) {
                showSomethingWentWrongToast();
                return;
            }
            checkUserType(function () {
                var formData = new FormData($(formId)[0]);
                formData.append(editorKey, editorContent);
                $.ajax({
                    url: apiUrl,
                    type: "POST",
                    data: formData,
                    dataType: "json",
                    contentType: false,
                    cache: false,
                    processData: false,
                    success: function (response) {
                        if (response.status) {
                            showSuccessToast(successMessage);
                        } else {
                            showSomethingWentWrongToast();
                        }
                    },
                    error: function (err) {
                        console.log(err);
                    },
                });
            });
        });
    }

    handleFormSubmission(
        "#privacyPolicyForm",
        `${domainUrl}updatePrivacyAndTerms`,
        quillPrivacy,
        "privacy_policy",
        "Privacy Policy updated successfully."
    );

    handleFormSubmission(
        "#termsOfUsesForm",
        `${domainUrl}updatePrivacyAndTerms`,
        quillTerms,
        "terms_of_uses",
        "Terms of Use updated successfully."
    );

    $("#editDeepARFilterForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editDeepARFilter`;
                var formId = '#editDeepARFilterForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['deepARFiltersTable']);
                        modalHide('#editDeepARFilterModal');
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
    $("#editUserLevelForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editUserLevel`;
                var formId = '#editUserLevelForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['userLevelTable']);
                        modalHide('#editUserLevelModal');
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
    $("#addUserLevelForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addUserLevel`;
                var formId = '#addUserLevelForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['userLevelTable']);
                        modalHide('#addUserLevelModal');
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
    $("#addWithdrawalGatewayForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addWithdrawalGateway`;
                var formId = '#addWithdrawalGatewayForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['withdrawalGatewayTable']);
                        modalHide('#addWithdrawalGatewayModal');
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
    $("#addReportReasonForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addReportReason`;
                var formId = '#addReportReasonForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['reportReasonsTable']);
                        modalHide('#addReportReasonModal');
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
    $("#addOnBoardingScreenForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addOnBoardingScreen`;
                var formId = '#addOnBoardingScreenForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['onboardingScreenTable']);
                        modalHide('#addOnBoardingScreenModal');
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

    $("#deepARFiltersTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteDeepARFilter`;
                        var formData = new FormData();
                        formData.append('id', id);
                        try {
                            doAjax(delete_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['deepARFiltersTable']);
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
    $("#userLevelTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteUserLevel`;
                        var formData = new FormData();
                        formData.append('id', id);
                        try {
                            doAjax(delete_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['userLevelTable']);
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
    $("#withdrawalGatewayTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteWithdrawalGateway`;
                        var formData = new FormData();
                        formData.append('id', id);
                        try {
                            doAjax(delete_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['withdrawalGatewayTable']);
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
    $("#reportReasonsTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteReportReason`;
                        var formData = new FormData();
                        formData.append('id', id);
                        try {
                            doAjax(delete_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['reportReasonsTable']);
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
    $("#onboardingScreenTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteOnboardingScreen`;
                        var formData = new FormData();
                        formData.append('id', id);
                        try {
                            doAjax(delete_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['onboardingScreenTable']);
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

    $('#deepARFiltersTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var title = $(this).data("title");
        var image = $(this).data("image");

        $("#editDeepARFilterId").val(id);
        $("#editDeepARFilterTitle").val(title);
        $("#imgEditDeepARFilterPreview").attr('src',image);

        modalShow('#editDeepARFilterModal');
    });
    $('#userLevelTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var level = $(this).data("level");
        var coinscollection = $(this).data("coinscollection");

        $("#editUserLevelId").val(id);
        $("#edit_coins_collection").val(coinscollection);
        $("#edit_level").val(level);

        modalShow('#editUserLevelModal');
    });
    $('#withdrawalGatewayTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var title = $(this).data("title");

        $("#editWithdrawalGatewayId").val(id);
        $("#editWithdrawalGatewayTitle").val(title);

        modalShow('#editWithdrawalGatewayModal');
    });
    $('#reportReasonsTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var title = $(this).data("title");

        $("#editReportReasonId").val(id);
        $("#editReportReasonTitle").val(title);

        modalShow('#editReportReasonModal');
    });
    $('#onboardingScreenTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var title = $(this).data("title");
        var description = $(this).data("description");
        var image = $(this).data("image");

        $("#editOnboardingScreenId").val(id);
        $("#editOnboardingTitle").val(title);
        $("#editOnboardingDesc").val(description);
        $("#imgEditOnBoradingPreview").attr('src',image);
        modalShow('#editOnBoardingScreenModal');
    });

    $("#editWithdrawalGatewayForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editWithdrawalGateway`;
                var formId = '#editWithdrawalGatewayForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['withdrawalGatewayTable']);
                        modalHide('#editWithdrawalGatewayModal');
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
    $("#editReportReasonForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editReportReason`;
                var formId = '#editReportReasonForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['reportReasonsTable']);
                        modalHide('#editReportReasonModal');
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
    $("#editOnBoardingScreenForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}updateOnboardingScreen`;
                var formId = '#editOnBoardingScreenForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['onboardingScreenTable']);
                        modalHide('#editOnBoardingScreenModal');
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

    $("#onboardingScreenTable tbody").sortable({
        handle: ".sort-handler",
        update: function () {
            checkUserType(function () {
            console.log("update");
            sendOnBoardingOrderToServer();
             });
        },
    });

    function sendOnBoardingOrderToServer() {
        var order = [];
        $("div.sort-handler").each(function (index, element) {
            order.push({
                id: $(this).attr("data-id"),
                position: index + 1,
            });
        });

        // console.log(order);
        var url =  `${domainUrl}updateOnboardingOrder`;


        $.ajax({
            type: "POST",
            dataType: "json",
            url: `${domainUrl}updateOnboardingOrder`,
            data: {
                order: order,
            },
            success: function (response) {
                reloadDataTables(['onboardingScreenTable']);
                if (response.status) {
                    showSuccessToast(response.message);
                } else {
                    console.log(response);
                }
            },
        });
    }
        let activeTab = localStorage.getItem("activeTab-settings");
        if (activeTab) {
            $('.main-nav-link[href="' + activeTab + '"]').tab("show");
        } else {
            $('.first-nav-link').addClass("show active");
            $('.first-tab-pane').addClass("show active");
        }

        $(".main-nav-link").on("click", function () {
            let tabId = $(this).attr("href");
            localStorage.setItem("activeTab-settings", tabId);
        });

        previewImage('#inputeditDeepARFilterImage','#imgEditDeepARFilterPreview');
        previewImage('#inputaddDeepARFilterImage','#imgDeepARFilterPreview');
        previewImage('#inputAddOnboardingImage','#imgAddOnBoradingPreview');
        previewImage('#inputEditOnboardingImage','#imgEditOnBoradingPreview');
        $("#addOnBoardingScreenModal").on("hidden.bs.modal", function () {
            removeImageSource('#imgAddOnBoradingPreview');
            resetForm('#addOnBoardingScreenForm');
        });
        $("#editOnBoardingScreenModal").on("hidden.bs.modal", function () {
            removeImageSource('#imgEditOnBoradingPreview');
            resetForm('#editOnBoardingScreenForm');
        });

});
