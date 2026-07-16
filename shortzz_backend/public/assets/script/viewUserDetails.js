$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    var user_id = $('#user_id').val();

    $("#userStoriesTable").DataTable({
        autoWidth: false,
        processing: true,
        serverSide: true,
        serverMethod: "post",
        ordering: false,
        searching: false,
        language: {
            paginate: {
                previous: "<i class='mdi mdi-chevron-left'>",
                next: "<i class='mdi mdi-chevron-right'>",
            },
        },
        ajax: {
            url: `${domainUrl}listUserStories`,
            data: function (data) {
                data.user_id = user_id;
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
    $("#userPostsTable").DataTable({
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
            url: `${domainUrl}listUserPosts`,
            data: function (data) {
                data.user_id = user_id;
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

        $("#addCoinsForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var formId = '#addCoinsForm';
                var url =  `${domainUrl}addCoinsToUserWallet_FromAdmin`;
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
                                reloadDataTables(['userPostsTable']);
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

    $('#switchFreezeStatus').on("change", function () {
          checkUserType(() => {

        const value = $(this).is(":checked") ? 1 : 0;
        $.ajax({
            type: "POST",
            url: `${domainUrl}userFreezeUnfreeze`,
            data: {
                user_id: user_id,
                is_freez: value,
            },
            success: function (response) {
                if (response.status) {
                    showSuccessToast(response.message);
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
    $('#switchModeratorStatus').on("change", function () {
          checkUserType(() => {
        const value = $(this).is(":checked") ? 1 : 0;
            $.ajax({
                type: "POST",
                url: `${domainUrl}changeUserModeratorStatus`,
                data: {
                    user_id: user_id,
                    is_moderator: value,
                },
                success: function (response) {
                    if (response.status) {
                        showSuccessToast(response.message);
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

    $("#link-space").on("click", ".delete-link", function (e) {
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
                        `${domainUrl}deleteUserLink_Admin`;
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

    $("#userStoriesTable").on("click", ".delete", function (e) {
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
                        `${domainUrl}deleteStory_Admin`;
                        var formData = new FormData();
                        formData.append('id', id);
                        try {
                            doAjax(delete_url, formData).then(function (response){
                                if(response.status){
                                    reloadDataTables(['userStoriesTable']);
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

    $(document).on("click", ".viewImageStory", function (event) {
        event.preventDefault();
        var content = $(this).data("content");
        $('#imgStorySwiperWrapper').empty();

        $('#imgStorySwiperWrapper').append(
            `
            <div class="swiper-slide">
                <img src="${content}" alt="" class="img-fluid">
            </div>
            `
        );
        const swiper = new Swiper(".mySwiper", {
            spaceBetween: 30,
            pagination: { el: ".swiper-pagination", type: "fraction" },
            navigation: {
              nextEl: ".swiper-button-next",
              prevEl: ".swiper-button-prev",
            },
          });

        $("#imageStoryModal").modal("show");
    });
    $(document).on("click", ".viewImagePost", function (event) {
        event.preventDefault();
        var postId = $(this).data("postid");
        var images = $(this).data("images");

        fetchDescriptionAndDisplay(postId, 'imageDescription');
        $('#imgPostSwiperWrapper').empty();

        $.each(images, function(index, image) {

            $('#imgPostSwiperWrapper').append(
                `
                <div class="swiper-slide">
                            <img src="${image.image}" alt="" class="img-fluid">
                </div>
                `
            );

        });

        const swiper = new Swiper(".mySwiper", {
            spaceBetween: 30,
            pagination: { el: ".swiper-pagination", type: "fraction" },
            navigation: {
              nextEl: ".swiper-button-next",
              prevEl: ".swiper-button-prev",
            },
          });


        $("#imagePostModal").modal("show");
    });
    $(document).on("click", ".viewTextPost", function (event) {
        event.preventDefault();
        var postId = $(this).data("postid");

        fetchDescriptionAndDisplay(postId, 'textDescription');
        $("#textPostModal").modal("show");
    });
    $(document).on("click", ".viewReelPost", function (event) {
        event.preventDefault();
        var contentUrl = $(this).data("videourl");
        var postId = $(this).data("postid");

        $("#video source").attr("src", contentUrl);
        $("#video")[0].load();
        fetchDescriptionAndDisplay(postId, 'videoDescription');
        $("#videoPostModal").modal("show");
        $("#video").trigger("play");
    });
    $(document).on("click", ".viewVideoPost", function (event) {
        event.preventDefault();
        var contentUrl = $(this).data("videourl");
        var postId = $(this).data("postid");

        $("#video source").attr("src", contentUrl);
        $("#video")[0].load();
        fetchDescriptionAndDisplay(postId, 'videoDescription');
        $("#videoPostModal").modal("show");

        $("#video").trigger("play");
    });
    $(document).on("click", ".viewVideoStory", function (event) {
        event.preventDefault();
        var content = $(this).data("content");

        $("#videoStory source").attr("src", content);
        $("#videoStory")[0].load();
        $("#videoStoryModal").modal("show");

        $("#videoStory").trigger("play");
    });

    $("#videoPostModal").on("hidden.bs.modal", function () {
        $("#video").trigger("pause");
    });
    $("#videoStoryModal").on("hidden.bs.modal", function () {
        $("#videoStory").trigger("pause");
    });

    function fetchDescriptionAndDisplay(postId, placeId){
        var url =  `${domainUrl}fetchFormattedPostDesc`;
        var formdata = new FormData();
        formdata.append('postId', postId);

            try {
                doAjax(url, formdata).then(function (response){
                    if(response.status){
                        $('#'+placeId).html(response.data);
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
