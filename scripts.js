function showToast(text) {
  M.toast({html: text});
}

function send() {
  var number = $('#number').val();
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
