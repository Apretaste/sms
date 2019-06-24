<?php

class SmsService extends ApretasteService
{

  /**
   * Function executed when the service is called
   *
   * @param Request
   *
   * @return void
   * @author Kuma
   */
  public function _main()
  {
    $this->response->setLayout('sms.ejs');

    // get the size of the pool from the configs file
    $pool_size = $this->di()->get('config')['smsapi']['poolsize'];

    // check the total sent won't go over the pool
    $totalSMSThisWeek = $this->getTotalSMSThisWeek();
    if ($totalSMSThisWeek >= $pool_size) {

      $this->simpleMessage(
        "Su SMS no fue enviado",
        "Como seguramente conoce, en Apretaste regalamos cientos de créditos, pero pagamos por cada SMS que enviado. Para ofrecer este servicio gratuitamente, tenemos que poner un límite de $pool_size SMS diarios. Por favor, espere a mañana para seguir manando SMS. Disculpe las molestias.",
        null, 'sentiment_very_dissatisfied');

      return;
    }

    // do not allow empty sms
    if (empty($this->request->input->data->number)) {
      $this->response->setTemplate("home.ejs", []);

      return;
    }

    // message is the user has zero credit
    if (isset($this->request->person->credit)) {
      $credit = $this->request->person->credit;
    }
    else {
      $this->simpleMessage("SMS no enviado", "Su SMS no ha sido enviado porque su credito actual es insuficiente.");

      return;
    }

    // get the number and clean it
    $parts = $this->splitNumber($this->request->input->data->number);

    // message if the number passed is incorrect
    if ($parts === false) {
      $this->simpleMessage(
        "No reconocemos el n&uacute;mero de celular",
        "Verifique el n&uacute;mero de celular que introdujo. Si el problema persiste contacte con el soporte t&eacute;cnico.");

      return;
    }

    // get the final country code and number
    $code = $parts['code'];
    $number = $parts['number'];

    // get the rate to pay
    $discount = $this->getRate($code);

    // message is the user has not enought credit
    if ($credit < $discount) {
      $this->simpleMessage("Cr&eacute;dito insuficiente", "Su credito actual es $credit y es insuficiente para enviar el SMS. Usted necesita $discount.");
    }

    // clean the text from the subject
    $text = $this->request->input->data->message;
    $text = str_replace("\n", " ", $text);
    $text = str_replace("\r", " ", $text);
    $text = str_replace("  ", " ", $text);
    $text = quoted_printable_decode($text);
    $text = trim(strip_tags($text));
    $text = str_replace("  ", " ", $text);
    $text = str_replace("--", "-", $text);
    $textExtra = substr($text, 160); // the rest of the message
    $text = substr(trim($text), 0, 160); // split message

    // send an error if the message text is missing
    if (empty($text)) {
      $this->simpleMessage("Mensaje vac&iacute;o", "Usted no escribio el text del SMS que quiere enviar. Por favor escriba el texto del mensaje seguido del numero de telefono");
    }

    $code = intval($code);

    if ($code != 53 && $code != 52 && $code != 1) {
      $this->simpleMessage("SMS no enviado", "Por el momento solo se pueden enviar mensajes a Canad&aacute;, USA, M&eacute;xico y Cuba. Disculpa las molestas.");
      return;
    }

    if ($code == 53) $code = 1; // Cuba
    elseif ($code == 52) $code = 153; // Mexico
    elseif ($code == 1 && array_search(intval(substr($number,0,3)), // buscar en codigos de area para detectar canada
        [204, 226,236, 249,250,289,306,343,365,367,403,416,418,431,437,
        438,450,506,514,519,548,579,581,587,604,613,639,647,705,
        709,778,780,782,807,819,825,867,873,902,905]) !== false) $code = 45; // Canada
    elseif ($code == 1) $code = 237; // USA

    // send the SMS
    $sent = (new SMS($number, $text, $code))->send();

    // ensure the sms was sent correctly
    if (intval($sent->code) !== 200) {
      $this->simpleMessage(
        "SMS no enviado",
        "El SMS no se pudo enviar debido a problemas t&eacute;nicos. Int&eacute;ntelo m&aacute;s tarde o contacte al soporte t&eacute;nico.");
      return;
    }

    $message = str_replace("'", "", $text);
    q("
			START TRANSACTION;
			UPDATE person SET credit = credit - $discount WHERE id = '{$this->request->person->id}';
			INSERT INTO _sms_messages(person_id, `email`,`code`,`number`,`text`,`price`) VALUES ('{$this->request->person->email}', '{$this->request->person->email}','$code','$number','$message','$discount');
			COMMIT;");

    // prepare info to be sent to the view
    $responseContent = [
      "credit"     => $credit - $discount,
      "msg"        => $text,
      "bodyextra"  => $textExtra,
      "poolleft"   => $pool_size - $totalSMSThisWeek,
      "cellnumber" => "+$code$number",
    ];

    // send the OK email
    $this->response->setTemplate("basic.ejs", $responseContent);
  }

  /**
   * Send the list of international codes
   *
   * @author Kuma
   */
  public function _codigos()
  {
    // get the list of codes
    $codes = $this->getCountryCodes();
    asort($codes);

    // create the response
    $this->response->setCache();
    $this->response->setTemplate("codes.ejs", ["codes" => $codes]);
  }

  /**
   * Returns rate
   *
   * @param mixed $code
   *
   * @return number
   */
  private function getRate($code)
  {
    $code = $code * 1;
    if ($code == 53) {
      return 0.05;
    }

    return 0.1;
  }

  /**
   * Split cell number between country code and number
   *
   * @param string $number
   *
   * @return array | bool
   */
  private function splitNumber($number)
  {
    $number = trim($number);
    $number = str_replace(['(', '-', ' '], '', $number);
    $code = null;
    $codes = $this->getCountryCodes();

    if (isset($number[1]) && substr($number, 0, 2) == '00') {
      $number = substr($number, 2);
    }

    if (isset($number[0]) && $number[0] == '0') {
      $number = substr($number, 1);
    }

    if (isset($number[0]) && $number[0] == '0') {
      $number = substr($number, 1);
    }

    if (strlen($number) == '8' && $number[0] == '5') {
      $code = 53;
    } // to cuba

    // to world
    if (is_null($code)) {
      if (isset($number[0])) {
        if ($number[0] != '+') {
          $number = '+' . $number;
        }
        foreach ($codes as $xcode => $country) {
          if (substr($number, 0, strlen($xcode) + 1) == '+' . $xcode) {
            $code = $xcode;
            $number = substr($number, strlen($xcode) + 1);
            break;
          }
        }
      }

      if (is_null($code)) {
        return false;
      }
    }

    return ['code' => $code, 'number' => $number];
  }

  /**
   * Returns a list of country phone codes
   *
   * @return array
   * @author Kuma
   */
  private function getCountryCodes()
  {
    return require __DIR__ . "/codes.php";
  }

  /**
   * Get the global number of SMS sent on the current week
   *
   * @return Integer
   * @author salvipascual
   */
  private function getTotalSMSThisWeek()
  {
    // get the dates for last Monday and next Sunday
    $lastMondayTime = strtotime('last monday', strtotime('tomorrow'));
    $firstDayOfTheWeek = date('Y-m-d H:i:s', $lastMondayTime);
    $lastDayOfTheWeek = date('Y-m-d H:i:s', strtotime('+6 days', $lastMondayTime));

    // get the number of messages from the database
    $total = q("
			SELECT COUNT(id) as total
			FROM _sms_messages
			WHERE sent BETWEEN '$firstDayOfTheWeek' AND '$lastDayOfTheWeek'");

    return $total[0]->total;
  }
}