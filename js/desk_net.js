jQuery(document).ready(function ($) {
    var modal = $('#consentModal'),
        close = $('#consentModal .close, #consentModal #cancel'),
        confirmButton = $('#consentModal #confirm');

    // When the user clicks on the submit button, open the modal
    $('#submitForm').on('click',function ( ) {
        modal.css("display","block");
    });

    // When the user clicks on (x), close the modal
    $.each( close, function (){
        $( this ).on( 'click', function () {
            modal.css("display","none");
        });
    });

    confirmButton.on('click', function () {
        modal.css("display","none");
        $('#submit').click();
    });
});