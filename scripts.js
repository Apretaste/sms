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
    if (msg.length < 8) {
        $('.helper-text-cellphone').html('Al menos ' + (8 - msg.length) + ' d&iacute;gitos');
    }
    else {
        $('.helper-text-cellphone').html('');
    }
}

function sendCellphone() {
    var cellphone = '';

    if ($("#cellphone").length) {
        cellphone = $("#cellphone").val();
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

    } else
        showToast('Escribe un numero de celuar');
}

function send() {
    var number = $('#country').val() + $('#number').val();
    var message = $('#message').val();

    if (!number) {
        showToast('Díganos el número de celular');
        return false;
    }

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
