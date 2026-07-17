$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");

    $("#createDummyUserForm").on("submit", function (e) {
        e.preventDefault();
            checkUserType(() => {
                var url =  `${domainUrl}addDummyUser`;
                var formId = '#createDummyUserForm';
                var formdata = collectFormData(formId);
                var is_verify = $("#switchIsVerify").prop("checked") == true ? 1 : 0;
                formdata.append('is_verify', is_verify);

                showFormSpinner(formId);
            try {
                doAjax(url, formdata).then(function (response){
                    hideFormSpinner(formId);
                    if(response.status){
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

    previewImage('#profile_photo', '#img-profile');

});
