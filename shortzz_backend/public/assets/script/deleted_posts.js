$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".deletedPosts").addClass("menuitem-active");

    $("#deletedPostsTable").DataTable({
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
            url: `${domainUrl}listDeletedPosts`,
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

    $("#deletedCommentsTable").DataTable({
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
            url: `${domainUrl}listDeletedComments`,
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

    $("#deletedCommentsTable").on("click", ".restore-comment", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Restore this comment?",
                text: "It will become visible in the app again.",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var id = $(this).attr("rel");
                    var url = `${domainUrl}restoreComment`;
                    var formData = new FormData();
                    formData.append("id", id);
                    try {
                        doAjax(url, formData).then(function (response) {
                            if (response.status) {
                                reloadDataTables(["deletedCommentsTable"]);
                                showSuccessToast(response.message);
                            } else {
                                showErrorToast(response.message);
                            }
                        });
                    } catch (error) {
                        console.log("Error! : ", error.message);
                        showErrorToast(error.message);
                    }
                }
            });
        });
    });

    $("#deletedPostsTable").on("click", ".restore", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "info",
                title: "Restore this post?",
                text: "It will become visible in the app again.",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Yes",
            }).then((result) => {
                if (result.isConfirmed) {
                    var id = $(this).attr("rel");
                    var url = `${domainUrl}restorePost`;
                    var formData = new FormData();
                    formData.append("id", id);
                    try {
                        doAjax(url, formData).then(function (response) {
                            if (response.status) {
                                reloadDataTables(["deletedPostsTable"]);
                                showSuccessToast(response.message);
                            } else {
                                showErrorToast(response.message);
                            }
                        });
                    } catch (error) {
                        console.log("Error! : ", error.message);
                        showErrorToast(error.message);
                    }
                }
            });
        });
    });

    // Discards the retained copy ahead of schedule, so it is worded as the
    // irreversible action it is rather than a routine delete.
    $("#deletedPostsTable").on("click", ".purge", function (e) {
        e.preventDefault();

        checkUserType(() => {
            Swal.fire({
                icon: "warning",
                title: "Delete permanently?",
                text: "This erases the post and its media for good, before the retention period ends. It cannot be undone or recovered for a dispute.",
                showDenyButton: true,
                denyButtonText: `Cancel`,
                confirmButtonText: "Delete permanently",
            }).then((result) => {
                if (result.isConfirmed) {
                    var id = $(this).attr("rel");
                    var url = `${domainUrl}purgePost`;
                    var formData = new FormData();
                    formData.append("id", id);
                    try {
                        doAjax(url, formData).then(function (response) {
                            if (response.status) {
                                reloadDataTables(["deletedPostsTable"]);
                                showSuccessToast(response.message);
                            } else {
                                showErrorToast(response.message);
                            }
                        });
                    } catch (error) {
                        console.log("Error! : ", error.message);
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

        fetchDescriptionAndDisplay(postId, "imageDescription");
        $("#imgPostSwiperWrapper").empty();

        $.each(images, function (index, image) {
            $("#imgPostSwiperWrapper").append(
                `<div class="swiper-slide"><img src="${image.image}" alt="" class="img-fluid"></div>`
            );
        });

        new Swiper(".mySwiper", {
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
        fetchDescriptionAndDisplay($(this).data("postid"), "textDescription");
        $("#textPostModal").modal("show");
    });

    $(document).on("click", ".viewReelPost, .viewVideoPost", function (event) {
        event.preventDefault();
        var contentUrl = $(this).data("videourl");
        var postId = $(this).data("postid");

        $("#video source").attr("src", contentUrl);
        $("#video")[0].load();
        fetchDescriptionAndDisplay(postId, "videoDescription");
        $("#videoPostModal").modal("show");
        $("#video").trigger("play");
    });

    $("#videoPostModal").on("hidden.bs.modal", function () {
        $("#video").trigger("pause");
    });

    function fetchDescriptionAndDisplay(postId, placeId) {
        var url = `${domainUrl}fetchFormattedPostDesc`;
        var formdata = new FormData();
        formdata.append("postId", postId);

        try {
            doAjax(url, formdata).then(function (response) {
                if (response.status) {
                    $(`#${placeId}`).html(response.data);
                } else {
                    $(`#${placeId}`).html("");
                }
            });
        } catch (error) {
            console.log("Error! : ", error.message);
        }
    }
});
