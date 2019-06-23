<?php

class Service
{

  /**
   * Function executed when the service is called
   *
   * @param Request
   *
   * @return Response
   * @author Kuma
   */
  public function _main(Request $request)
  {
    // get the size of the pool from the configs file
    $di = \Phalcon\DI\FactoryDefault::getDefault();
    $poolsize = $di->get('config')['smsapi']['poolsize'];

    // check the total sent won't go over the pool
    $totalSMSThisWeek = $this->getTotalSMSThisWeek();
    if ($totalSMSThisWeek >= $poolsize) {
      $content = [
        "header" => "Su SMS no fue enviado",
        "icon"   => "sentiment_very_dissatisfied",
        "text"   => "Como seguramente conoce, en Apretaste regalamos cientos de créditos, pero pagamos por cada SMS que enviado. Para ofrecer este servicio gratuitamente, tenemos que poner un límite de $poolsize SMS diarios. Por favor, espere a mañana para seguir manando SMS. Disculpe las molestias.",
        "button" => ["href" => "PIROPAZO EDITAR", "caption" => "Editar perfil"],
      ];

      $response->setLayout('piropazo.ejs');

      return $response->setTemplate('message.ejs', $content);
    }

    // do not allow empty sms
    if (empty($request->query)) {
      $response->setCache();
      $response->setTemplate("home.ejs", []);
    }

    // get the person Object of the email
    $email = $request->email;
    $person = $this->utils->getPerson($email);

    // message is the user has zero credit
    if (isset($person->credit)) {
      $credit = $person->credit;
    }
    else {
      $response->createFromText("Su SMS no ha sido enviado porque su credito actual es insuficiente.");
    }

    // get the number and clean it
    $pieces = explode(" ", $request->query);
    $number = isset($pieces[0]) ? preg_replace('/[^0-9.]+/', '', $pieces[0]) : "";
    $parts = $this->splitNumber($number);

    // message if the number passed is incorrect
    if ($parts === false) {
      $response->createFromText("No reconocemos el numero de celular");
    }

    // get the final country code and number
    $code = $parts['code'];
    $number = $parts['number'];

    // get the rate to pay
    $discount = $this->getRate($code);

    // message is the user has not enought credit
    if ($credit < $discount) {
      $response->createFromText("Su credito actual es $credit y es insuficiente para enviar el SMS. Usted necesita $discount.");
    }

    // clean the text from the subject
    unset($pieces[0]);
    $text = implode(" ", $pieces);
    if (empty($text)) {
      $text = $request->body;
    }
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
      $response->createFromText("Usted no escribio el text del SMS que quiere enviar. Por favor escriba el texto del mensaje seguido del numero de telefono");
    }

    // send the SMS
    $sent = $this->sendSMS($code, $number, $email, $text, $discount);

    // ensure the sms was sent correctly
    if (!$sent) {
      $response->createFromText("El SMS no se pudo enviar debido a problemas t&eacute;nicos. Int&eacute;ntelo m&aacute;s tarde o contacte al soporte t&eacute;nico.");
    }

    // prepare info to be sent to the view
    $responseContent = [
      "credit"     => $credit - $discount,
      "msg"        => $text,
      "bodyextra"  => $textExtra,
      "poolleft"   => $poolsize - $totalSMSThisWeek,
      "cellnumber" => "+$code$number",
    ];

    // send the OK email
    $response = new Response();
    $response->setTemplate("basic.ejs", $responseContent);
  }

  /**
   * Send the list of international codes
   *
   * @param Request
   *
   * @return Response
   * @author Kuma
   */
  public function _codigos(Request $request)
  {
    // get the list of codes
    $codes = $this->getCountryCodes();
    asort($codes);

    // create the response
    $response = new Response();
    $response->setCache();
    $response->setTemplate("codes.ejs", ["codes" => $codes]);
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
   * Send an SMS using the API
   *
   * @author Kuma
   */
  private function sendSMS($prefix, $number, $sender, $message, $cost)
  {
    $contactos = json_encode(["nuevos" => "$number"]);
    $data = [
      "token"     => "eecf71cf111047d56047f4af100512cda89687cbad7a7f569e0a7e05f9a65a2f",
      "mensaje"   => $message,
      "ruta"      => "9",
      "pais"      => "53",
      "contactos" => $contactos,
    ];
    $url = "https://www.freesmscuba.com/index.php/api/createpub";
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.9; rv:28.0) Gecko/20100101 Firefox/28.0");
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($curl);

    if ($response === false) {
      $response = curl_error($curl);
    }

    // cierra la conexión
    curl_close($curl);

    $responseObj = json_decode($response);
    if ($responseObj->status !== 1) {
      return false;
    }

    // send an alert if the balance is depleted
    /*if ($response == 'SALDO INSUFICIENTE') {
      $this->utils->createAlert("Balance depleted on the SMS provider", "ERROR");

      return false;
    }

    // check if the SMS was sent correctly
    if (stripos($response, 'SMS ENVIADO') === false) {
      return false;
    }
*/
    // if the message was sent, save into the database
    $message = str_replace("'", "", $message);
    $connection = new Connection();
    $connection->deepQuery("
			START TRANSACTION;
			UPDATE person SET credit = credit - $cost WHERE email='$sender';
			INSERT INTO _sms_messages(`email`,`code`,`number`,`text`,`price`) VALUES ('$sender','$prefix','$number','$message','$cost');
			COMMIT;");

    return true;
  }

  /**
   * Split cell number between country code and number
   *
   * @param string $number
   *
   * @return array
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
    include_once $this->pathToService . "/codes.php";

    return $countryCodes;
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
    $connection = new Connection();
    $total = $connection->deepQuery("
			SELECT COUNT(id) as total
			FROM _sms_messages
			WHERE sent BETWEEN '$firstDayOfTheWeek' AND '$lastDayOfTheWeek'");

    return $total[0]->total;
  }
}