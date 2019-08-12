function msgLengthValidate() {
  var msgControl = $('#message');
  var msg = msgControl.val().trim();
  if (msg.length <= 160) {
    $('.helper-text').html(msg.length + '/160');
    msgControl.css('color', 'black');
    msgControl.addClass('valid');
    msgControl.removeClass('invalid');
  }
  else {
    $('.helper-text').html('Limite excedido');
    msgControl.css('color', 'red');
    msgControl.addClass('invalid');
    msgControl.removeClass('valid');
    showToast('El mensaje no puede exceder los 160 caracteres.');
  }
}

function showToast(text) {
  $(".toast").remove();
  M.toast({html: text});
}

function msgLengthValidateNumber() {
  var msg = $('#number').val().trim();
  var helper = $('.helper-text-number');
  if (msg.length < 8) {
    helper.html('Al menos ' + (8 - msg.length) + ' d&iacute;gitos');
  }
  else {
    helper.html('');
  }
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

  if (number.length < 8) {
    showToast('Díganos el número de celular. Debe tener al menos 8 d&iacute;gitos.');
    return false;
  }

  if (message.length > 160) {
    showToast('El mensaje no debe exceder los 160 caracteres');
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
