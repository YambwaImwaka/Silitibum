const domainUrl = $("#appUrl").val();
const user_type = $("#user_type").val();

$.ajaxSetup({
    headers: {
        "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
    },
});

$("#app_name").keyup(function () {
    let appName = $(this).val();
    document.title = appName;
});

function reloadDataTables(tableIds) {
    if (Array.isArray(tableIds)) {
        tableIds.forEach(function (tableId) {
            try {
                $(`#${tableId}`).DataTable().ajax.reload(null, false);
            } catch (error) {
                console.error(`Error reloading DataTable with ID: ${tableId}`, error.message);
            }
        });
    } else {
        console.error("The provided input is not an array. Please pass an array of table IDs.");
    }
}

function checkUserType(callback) {
    if (user_type == 1) {
        callback();
    } else {
        testerToast();
    }
}
function testerToast() {
    $.NotificationApp.send(
        "Oops",
        "You are Tester. You can't make this change!",
        "top-right",
        "rgba(0,0,0,0.2)",
        "error",
        2000
    );
}
function showErrorToast(message) {
    $.NotificationApp.send(
        "Oops",
        message || "Something went wrong!",
        "top-right",
        "rgba(0,0,0,0.2)",
        "error",
        3000
    );
}

function showSuccessToast(message) {
    $.NotificationApp.send(
        "Success",
        message || "Your Action Completed Successfully!",
        "top-right",
        "rgba(0,0,0,0.2)",
        "success",
        3000
    );
}

function showFormSpinner(formId){
    var form = $(formId);
    const spinner = $(form).find(".spinner");
    spinner.addClass("show"); // Show spinner
    spinner.removeClass("hide"); // Show spinner
}

function hideFormSpinner(formId){
    var form = $(formId);
    const spinner = $(form).find(".spinner");
    spinner.addClass("hide"); // Show spinner
    spinner.removeClass("show"); // Show spinner
}

function collectFormData(formId){
    return new FormData($(formId)[0]);
}


function printLog(data){
    console.log(data);
}
function modalHide(modelId) {
    $(modelId).modal("hide");
}
function modalShow(modelId) {
    $(modelId).modal("show");
}
function resetForm(formId) {
    $(formId).trigger("reset");
}

function dataTableReload() {
    $(".table").DataTable().ajax.reload();
}

async function doAjax(url, formData = {}, method = 'POST') {
    return $.ajax({
      url: url,
      type: method,
      dataType: 'json',
      data: formData,
      dataType: "json",
      contentType: false,
      cache: false,
      processData: false,
    });
}

function previewImage(inputSelector, imageSelector) {
    $(document).on('change', inputSelector, function(event) {
        var file = event.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                $(imageSelector).attr('src', e.target.result);
            };
            reader.readAsDataURL(file);
        }
    });
}

function removeAudioSource(audioSelector) {
    var audioElement = $(audioSelector);
    audioElement.find('source').attr('src', ''); // Remove source
    audioElement[0].load(); // Reload to apply changes
}



function previewMusic(inputSelector, audioSelector) {
    $(document).on('change', inputSelector, function(event) {
        var file = event.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                // Set the audio source dynamically
                $(audioSelector).find('source').attr('src', e.target.result);
                $(audioSelector)[0].load(); // Reload the audio element to apply changes
            };
            reader.readAsDataURL(file);
        }
    });
}

function removeImageSource(imageSelector) {
    var placeholder =  `${domainUrl}assets/img/placeholder.png`;
    $(imageSelector).attr('src', placeholder);
}


 let typingTimer;
