$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");

    $("#editDummyUserForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}updateDummyUser`;
                var formId = '#editDummyUserForm';
                var formdata = collectFormData(formId);
                var is_verify = $("#switchIsVerify").prop("checked") == true ? 1 : 0;
                formdata.append('is_verify', is_verify);

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

    previewImage('#profile_photo', '#imgUserProfile-userDetails');

});
