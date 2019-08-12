function msgLengthValidate() {
    var msg = $('#message').val().trim();
    if (msg.length <= 160) {
        $('.helper-text').html('Restante: ' + (160 - msg.length));
    }
    else {
        $('.helper-text').html('Limite excedido');
    }
}

function showToast(text) {
    M.toast({html: text});
}

function msgLengthValidateCellphone() {
    var msg = $('#cellphone').val().trim();
    var helper = $('.helper-text-cellphone');
    if (msg.length < 8) {
        helper.html('Al menos ' + (8 - msg.length) + ' d&iacute;gitos');
        return false;
    }

    helper.html('');
    return true;

}

function sendCellphone() {
    var cellphone = $("#cellphone").val();
    if (!cellphone) {
        showToast('Díganos su número de celular');
        return false;
    }

    apretaste.send({
        command: "SMS_PROFILE",
        data: {
            cellphone: cellphone
        },
        redirect: true
    });
}

function send() {
    var number = $('#number').val();
    var message = $('#message').val();

    if (!number || ! msgLengthValidateCellphone()) {
        showToast('Díganos el número de celular. Debe tener al menos 8 d&iacute;gitos.');
        return false;
    }

    number = $('#country').val() + number;

    if (!message) {
        showToast('Escriba un mensaje');
        return false;
    }

    apretaste.send({
        command: "SMS",
        data: {
            number: number,
            message: message
        },
        redirect: true
    });
}

$(function () {
    $("#country").formSelect();

    $("#cellphone")
        .keypress(function (e) {
            if (e.keyCode < 48 || e.keyCode > 57) {
                e.preventDefault();
                return false;
            }
        });

    $("#number").keypress(function (e) {
        if (e.keyCode < 48 || e.keyCode > 57) {
            e.preventDefault();
            return false;
        }
    });
});
