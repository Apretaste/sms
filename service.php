<?php

class Sms extends Service
{
	/**
	 * Function executed when the service is called
	 *
	 * @author Kuma
	 * @param Request
	 * @return Response
	 */
	public function _main(Request $request)
	{
		// get the size of the pool from the configs file
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$poolsize = $di->get('config')['smsapi']['poolsize'];

		// check the total sent won't go over the pool
		$totalSMSThisWeek = $this->getTotalSMSThisWeek();

		if($totalSMSThisWeek >= $poolsize)
		{
			$response = new Response();
			$response->setResponseSubject("El banco semanal se ha vaciado");
			$response->createFromText("<p>Sentimos decir que su SMS no fue enviado.</p><p>Como usted seguramente sabe, cada semana regalamos cientos de creditos de Apretate gratuitamente, pero tenemos que pagar por cada SMS que mandamos. Para seguir ofreciendo este servicio gratuitamente, cada semana creamos un banco de $poolsize SMS gratis, y esta numero ya se ha agotado. El proximo Lunes volvera el banco a llenarse y usted podra seguir manando SMS.</p><p>Disculpe las molestias.</p>");
			return $response;
		}

		// do not allow empty sms
		if(empty($request->query))
		{
			$response = new Response();
			$response->setResponseSubject("A que numero desea mandar?");
			$response->createFromTemplate("home.tpl", array());
			return $response;
		}

		// get the person Object of the email
		$email = $request->email;
		$person = $this->utils->getPerson($email);

		// message is the user has zero credit
		if(isset($person->credit)) $credit = $person->credit;
		else
		{
			$response = new Response();
			$response->setResponseSubject("Credito insuficiente");
			$response->createFromText("Su SMS no ha sido enviado porque su credito actual es insuficiente.");
			return $response;
		}

		// clean the number passed by the user
		$number = $request->query;
		$number = preg_replace('/[^0-9.]+/', '', $number);
		$parts = $this->splitNumber($number);

		// message if the number passed is incorrect
		if($parts === false)
		{
			$response = new Response();
			$response->setResponseSubject("Numero de celular incorrecto");
			$response->createFromText("No reconocemos el numero de celular");
			return $response;
		}

		// get the final country code and number
		$code = $parts['code'];
		$number = $parts['number'];

		// get the rate to pay
		$discount = $this->getRate($code);

		// message is the user has not enought credit
		if($credit < $discount)
		{
			$response = new Response();
			$response->setResponseSubject("SMS: Credito insuficiente");
			$response->createFromText("Su credito actual es $credit y es insuficiente para enviar el SMS. Usted necesita $discount.");
			return $response;
		}

		// clean the body
		$text = $request->body;
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
		if(trim($text) == "")
		{
			$response = new Response();
			$response->setResponseSubject("Falta el texto del SMS");
			$response->createFromText("Usted no escribio el text del SMS que quiere enviar. Por favor escriba el texto del mensaje en el cuerpo del email");
			return $response;
		}

		// send the SMS
		$sent = $this->sendSMS($code, $number, $email, $text, $discount);

		// ensure the sms was sent correctly
		if( ! $sent)
		{
			$response = new Response();
			$response->setResponseSubject("El SMS no se pudo enviar");
			$response->createFromText("El SMS no se pudo enviar debido a problemas t&eacute;nicos. Int&eacute;ntelo m&aacute;s tarde o contacte al soporte t&eacute;nico.");
			return $response;
		}

		// prepare info to be sent to the view
		$responseContent = array(
			"credit" => $credit - $discount,
			"msg" => $text,
			"bodyextra" => $textExtra,
			"poolleft" => $poolsize - $totalSMSThisWeek,
			"cellnumber" => "+$code$number");

		// send the OK email
		$response = new Response();
		$response->setResponseSubject("SMS enviado correctamente");
		$response->createFromTemplate("basic.tpl", $responseContent);
		return $response;
	}

	/**
	 * Send the list of international codes
	 *
	 * @author Kuma
	 * @param Request
	 * @return Response
	 */
	public function _codigos(Request $request)
	{
		// get the list of codes
		$codes = $this->getCountryCodes();
		asort($codes);

		// create the response
		$response = new Response();
		$response->setResponseSubject("Codigos internacionales");
		$response->createFromTemplate("codes.tpl", array("codes" => $codes));
		return $response;
	}

	/**
	 * Returns rate
	 *
	 * @param mixed $code
	 * @return number
	 */
	private function getRate($code)
	{
		$code = $code * 1;
		if($code == 53) return 0.05;
		return 0.1;
	}

	/**
	 * Send an SMS using the API
	 *
	 * @author Kuma
	 */
	private function sendSMS($prefix, $number, $sender, $message, $cost)
	{
		// get the api credentials from the config file
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$login = $di->get('config')['smsapi']['login'];
		$password = $di->get('config')['smsapi']['password'];

		// call the api and send the SMS
		$URL = "http://api.smsacuba.com/api10allcountries.php?";
		$URL .= "login=" . $login . "&password=" . $password . "&prefix=" . $prefix . "&number=" . $number . "&sender=" . $sender . "&msg=" . urlencode($message);
		$response = strtoupper(trim(file_get_contents($URL)));

		// send an alert if the balance is depleted
		if($response == 'SALDO INSUFICIENTE')
		{
			$this->utils->createAlert("Balance depleted on the SMS provider", "ERROR");
			return false;
		}

		// check if the SMS was sent correctly
		if($response != 'SMS ENVIADO') return false;

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
	 * @return array
	 */
	private function splitNumber($number)
	{
		$number = trim($number);
		$number = str_replace(array('(','-',' '), '', $number);
		$code = null;
		$codes = $this->getCountryCodes();

		if(isset($number[1]) && substr($number, 0, 2) == '00') $number = substr($number, 2);
		if(isset($number[0]) && $number[0] == '0') $number = substr($number, 1);
		if(isset($number[0]) && $number[0] == '0') $number = substr($number, 1);
		if(strlen($number) == '8' && $number[0] == '5') $code = 53; // to cuba

		// to world
		if(is_null($code))
		{
			if(isset($number[0]))
			{
				if($number[0] != '+') $number = '+' . $number;
				foreach($codes as $xcode => $country)
				{
					if(substr($number, 0, strlen($xcode) + 1) == '+' . $xcode)
					{
						$code = $xcode;
						$number = substr($number, strlen($xcode) + 1);
						break;
					}
				}
			}

			if(is_null($code)) return false;
		}

		return array('code' => $code, 'number' => $number);
	}

	/**
	 * Returns a list of country phone codes
	 *
	 * @author Kuma
	 * @return array
	 */
	private function getCountryCodes()
	{
		include_once $this->pathToService . "/codes.php";
		return $countryCodes;
	}

	/**
	 * Get the global number of SMS sent on the current week
	 *
	 * @author salvipascual
	 * @return Integer
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
