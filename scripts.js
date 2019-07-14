function showToast(text) {
  M.toast({html: text});
}

function msgLengthValidate() {
  var msg = $('#message').val().trim();
  if (msg.length <= 160) {
    $('.helper-text').html('Restante: ' + (160 - msg.length));
  }
  else {
    $('.helper-text').html('Limite excedido');
  }
}

function send() {
  var number = $('#country').val() + $('#number').val();
  var message = $('#message').val();
  var cellphone = '';

  if ($("#cellphone").length){
    cellphone = $("#cellphone").val();
    if (!cellphone) {
      showToast('Díganos su número de celular');
      return false;
    }
  }

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
      cellphone: cellphone,
      number: number,
      message: message
    },
    redirect: true
  });
}

$(function(){
  $("#country").formSelect();

  $("#cellphone").keypress(function(e){
    if (e.keyCode < 48 || e.keyCode > 57) {
      e.preventDefault();

      if ($("#cellphone").val().length >= 10)
      {
          $("#cell-number-section").show();
      } else {
          $("#cell-number-section").hide();
      }

      return false;
    }
  });


  $("#number").keypress(function(e){
    if (e.keyCode < 48 || e.keyCode > 57) {
      e.preventDefault();
      return false;
    }
  });
});
