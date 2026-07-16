$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".music").addClass("menuitem-active");

    $("#editMusicForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editMusic`;
                var formId = '#editMusicForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['musicTable','musicCategoryTable']);
                        modalHide('#editMusicModal');
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
    $("#addMusicForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addMusic`;
                var formId = '#addMusicForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['musicTable','musicCategoryTable']);
                        modalHide('#addMusicModal');
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
    $("#addMusicCategoryForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addMusicCategory`;
                var formId = '#addMusicCategoryForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['musicTable','musicCategoryTable']);
                        modalHide('#addMusicCategoryModal');
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

    $("#editMusicCategoryForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}editMusicCategory`;
                var formId = '#editMusicCategoryForm';
                var formdata = collectFormData(formId);
                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
                        reloadDataTables(['musicTable','musicCategoryTable']);
                        modalHide('#editMusicCategoryModal');
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

    $("#musicCategoryTable").DataTable({
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
            url: `${domainUrl}listMusicCategories`,
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
    $("#musicTable").DataTable({
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
            url: `${domainUrl}listMusics`,
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

    $("#musicCategoryTable").on("click", ".delete", function (e) {
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
                    var cat_id = $(this).attr("rel");
                    var delete_cat_url =
                        `${domainUrl}deleteMusicCategory`;
                        var formData = new FormData();
                        formData.append('id', cat_id);
                        try {
                            doAjax(delete_cat_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['musicTable','musicCategoryTable']);
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

    $("#musicTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteMusic`;
                        var formData = new FormData();
                        formData.append('id', cat_id);
                        try {
                            doAjax(delete_cat_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['musicTable','musicCategoryTable']);
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

    $('#musicCategoryTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var name = $(this).data("name");

        $("#editMusicCatId").val(id);
        $("#editMusicCatName").val(name);

        modalShow('#editMusicCategoryModal');
    });

    $('#musicTable').on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var title = $(this).data("title");
        var category_id = $(this).data("category");
        var duration = $(this).data("duration");
        var artist = $(this).data("artist");
        var image = $(this).data("image");
        var music = $(this).data("music");
        console.log(music);

        $("#editMusicId").val(id);
        $("#editMusicTitle").val(title);
        $("#editMusicDuration").val(duration);
        $("#editMusicArtist").val(artist);
        $("#editMusicCategory").val(category_id).trigger("change");
        $("#imgEditMusicPreview").attr('src', image);
        $('#audioEditMusicPreview').find('source').attr('src', music);
        $('#audioEditMusicPreview')[0].load();

        modalShow('#editMusicModal');
    });

    previewImage('#inputEditMusicImage', '#imgEditMusicPreview');
    previewImage('#inputAddMusicImage', '#imgAddMusicPreview');

    previewMusic('#inputAddMusicFile','#audioAddMusicPreview');
    previewMusic('#inputEditMusicFile','#audioEditMusicPreview');

    $("#editMusicModal").on("hidden.bs.modal", function () {
        $("#audioEditMusicPreview").trigger("pause");
        removeAudioSource('#audioEditMusicPreview');
        removeImageSource('#imgEditMusicPreview');
        resetForm('#editMusicForm');
    });
    $("#addMusicModal").on("hidden.bs.modal", function () {
        $("#audioAddMusicPreview").trigger("pause");
        removeAudioSource('#audioAddMusicPreview');
        removeImageSource('#imgAddMusicPreview');
        resetForm('#addMusicForm');
    });





});
