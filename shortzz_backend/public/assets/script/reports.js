$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".reports").addClass("menuitem-active");

    $("#userReportTable").DataTable({
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
            url: `${domainUrl}listUserReports`,
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
    $("#postReportTable").DataTable({
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
            url: `${domainUrl}listPostReports`,
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
    $('#userReportTable').on("click", ".accept-report", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                text: "This action will freeze the user! You still want to proceed?",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var id = $(this).attr("rel");
                    var delete_url = `${domainUrl}acceptUserReport`;
                    var formData = new FormData();
                    formData.append('id', id);
                    try {
                        doAjax(delete_url, formData).then(function (response){
                            if(response.status){
                                reloadDataTables(['userReportTable']);
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
    $('#postReportTable').on("click", ".accept-report", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                text: "This action will delete the post! You still want to proceed?",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var id = $(this).attr("rel");
                    var delete_url = `${domainUrl}acceptPostReport`;
                    var formData = new FormData();
                    formData.append('id', id);
                    try {
                        doAjax(delete_url, formData).then(function (response){
                            if(response.status){
                                reloadDataTables(['postReportTable']);
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
    $('#postReportTable').on("click", ".reject-report", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                text: "This action will delete the report & post will remain!",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var id = $(this).attr("rel");
                    var delete_url = `${domainUrl}rejectPostReport`;
                    var formData = new FormData();
                    formData.append('id', id);
                    try {
                        doAjax(delete_url, formData).then(function (response){
                            if(response.status){
                                reloadDataTables(['postReportTable']);
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
    $('#userReportTable').on("click", ".reject-report", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Are you sure?",
                text: "This action will delete the report & user will not be freezed!",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var id = $(this).attr("rel");
                    var delete_url = `${domainUrl}rejectUserReport`;
                    var formData = new FormData();
                    formData.append('id', id);
                    try {
                        doAjax(delete_url, formData).then(function (response){
                            if(response.status){
                                reloadDataTables(['userReportTable']);
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

    $("#videoPostModal").on("hidden.bs.modal", function () {
        $("#video").trigger("pause");
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
