$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".shop-categories").addClass("menuitem-active");

    initializeDataTable("#shopCategoryTable", "shopCategoryList");

    submitForm("#addShopCategoryForm", `${domainUrl}addShopCategoryForm`);
    $("#addShopCategoryForm").on("submit", function (e) {
        setTimeout(function () {
            window.location.href = domainUrl + "shopCategory/index";
        }, 500);
    });

    submitForm("#editShopCategoryForm", `${domainUrl}updateShopCategory`);

    $("#shopCategoryTable").on("click", ".edit", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");
        var key = $(this).data("key");
        $("#shop_category_id").val(id);
        $("#edit_key").val(key);
        $("#edit_old_key").val(key);
        $("#editShopCategoryModal").modal("show");
    });

    deleteRecord(
        "#shopCategoryTable",
        `${domainUrl}deleteShopCategory`,
        "shop_category_id"
    );
});
