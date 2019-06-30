function showToast(text) {
  M.toast({html: text});
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
});