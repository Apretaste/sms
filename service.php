<?php

class Sms extends Service
{
	
	static $sms_user = '';
	static $sms_pass = '';
	
	/**
	 * Function executed when the service is called
	 *
	 * @param Request
	 * @return Response
	 * */
	public function _main(Request $request)
	{
		
		$email = $request->email;
		$person = $this->utils->getPerson($email);
		
		if (isset($person->credit))
			$credit = $person->credit;
		else {
			$credit = 0;
			$response = new Response();
			$response->setResponseSubject("SMS: Credito insuficiente");
			$response->createFromText("Su credito actual es ".$credit." y es insuficiente para enviar el SMS");
			return $response;
		}
		
		$argument = $request->query;
		$body = $request->body;
		$body = str_replace( "\n", " ", $body );
		$body = str_replace( "\r", " ", $body );
		$body = str_replace( "  ", " ", $body );
		$body = quoted_printable_decode ( $body );
		$body = trim ( strip_tags ( $body ) );
		$body = $this->replaceRecursive ( "  ", " ", $body );
		$body = $this->replaceRecursive ( "--", "-", $body );
		$body = trim ( $body );
				
		$codes = $this->getCountryCodes ();
	
		asort ( $codes );
	
		if (trim($body) == ''){
			$response = new Response();
			$response->setResponseSubject("SMS: Falta el texto del mensaje");
			$response->createFromText("Escriba el texto del mensaje en el cuerpo del correo");
			return $response;
		}

		$without_answer = false;
	
		$dont_replyme = array (
				'no responder',
				'sin responder',
				'no contestar',
				'sin contestar',
				'sin notificacion',
				'solo enviar' 
		);
		
		$argument = strtolower ($this->replaceRecursive ( "  ", " ", $argument ) );
		
		foreach ( $dont_replyme as $dr ) {
			if (stripos ( $argument, $dr ) !== false) {
				$without_answer = true;
				$argument = str_replace ( $dr, "", $argument );
			}
		}
		
		// Remove ugly chars
	
		$n = '';
		$l = strlen ( $argument );
		for($i = 0; $i < $l; $i ++)
			if (strpos ( '1234567890', $argument [$i] ) !== false)
				$n .= $argument [$i];
		
		$argument = $n;
		
		// Get country code
	
		$parts = $this->splitNumber ( $argument );
	
		if ($parts === false) {
			$response = new Response();
			$response->setResponseSubject("SMS: Numero de celular incorrecto");
			$response->createFromText("No reconocemos el numero de celular");
			return $response;
		}
		
		$code = $parts ['code'];
		$number = $parts ['number'];
	
		// Split message
		$msg = trim ($body);
		$msg = substr($msg, 0, 160);
		$bodyextra = substr($msg, 160);
	
		// Get rate
		$discount = $this->getRate ( $code );
		
		if ($credit < $discount) {
			$response = new Response();
			$response->setResponseSubject("SMS: Credito insuficiente");
			$response->createFromText("Su credito actual es ".$credit." y es insuficiente para enviar el SMS");
			return $response;
		}
	
		$r = $this->sendSMS($code,$number,$email,$msg,$discount);

		if ($r !== 'sms enviado') {
			$response = new Response();
			$response->setResponseSubject("SMS: El SMS no se pudo enviar");
			$response->createFromText("El SMS no se pudo enviar debido a problemas t&eacute;nicos. Int&eacute;ntelo m&aacute;s tarde o contacte a al soporte t&eacute;nico.");
			return $response;
		}
		
		// make the transfer
		// TODO: Check the correct float - float operation
		$sql = "UPDATE person SET credit = credit - {$discount} WHERE email='{$email}';";
		$connection = new Connection();
		$connection->deepQuery($sql);
		
		$person = $this->utils->getPerson($email);
		$newcredit = $person->credit;
		
		if ($without_answer === false){
			$response = new Response();
			$response->setResponseSubject("SMS enviado");
			$response->createFromTemplate("basic.tpl", array("credit" => $newcredit, "msg"=>$msg, "bodyextra" => $bodyextra, "cellnumber" => "(+$code)$number"));
			
			return $response;
		}
	}
	
	/**
	 * Sub-service codigos
	 * @param Request
	 * @return Response
	 */
	public function _codigos(Request $request){
		$codes = $this->getCountryCodes ();
		asort ( $codes );
		$response = new Response();
		$response->setResponseSubject("SMS: Codigos internacionales");
		$response->createFromTemplate("codes.tpl", array("codes" => $codes));
		return $response;
	}
	
	/**
	 * Returns rate
	 *
	 * @param mixed $code
	 * @return number
	 */
	private function getRate($code){
		$code = $code * 1;
		
		if ($code == 53)
			return 0.05;
		
		return 0.1;
	}
	
	
	/**
	 * Send SMS
	 *
	 */
	private function sendSMS($prefix, $number, $sender, $message, $discount){
		if ($this->getCredit() >= $discount * 1) {
			
			$di = \Phalcon\DI\FactoryDefault::getDefault();
			$login = $di->get('config')['smsapi']['login'];
			$password = $di->get('config')['smsapi']['password'];
			
			$URL = "http://api.smsacuba.com/api10allcountries.php?";
			$URL .= "login=" . $login . "&password=" . $password . "&prefix=" . $prefix . "&number=" . $number . "&sender=" . $sender . "&msg=" . urlencode($message);
			
			$r = file_get_contents($URL);
			
			$message = str_replace("'", "''", $message);
			
			$r = strtolower(trim("$r"));
			
			if ($r == 'sms enviado') {				
				$connection = new Connection();
				$connection->deepQuery("INSERT INTO __sms_messages (id, email, cellphone, message, discount)
										VALUES ('".uniqid("",true)."','$sender', '(+$prefix)$number', '$message', $discount);");
				return $r;
			}
		} 
		
		return false;
	}
	
	/**
	 * Replace recursively in string
	 */
	private function replaceRecursive($from, $to, $s)
	{
		if ($from == $to)
			return $s;
		
		$p = 0;
		$max = 100;
		$i = 0;
		do {
			$i++;
			$p = strpos($s, $from, $p);
			if ($p !== false)
			$s = str_replace($from, $to, $s);
			if ($i >= $max)
			break;
		} while ($p !== false);
		
		return $s;
	}
	
	/**
	 * Split cell number
	 *
	 * @param string $number
	 * @return array
	 */
	private function splitNumber($number){
		$number = trim($number);
		
		$number = str_replace(array(
				'(',
				'-',
				' '
		), '', $number);
		
		$code = null;
		$codes = $this->getCountryCodes();
		
		if (isset($number[1]))
			if (substr($number, 0, 2) == '00')
				$number = substr($number, 2);
		
		if (isset($number[0]))
			if ($number[0] == '0')
				$number = substr($number, 1);
		
		if (isset($number[0]))
			if ($number[0] == '0')
				$number = substr($number, 1);
		
		if (strlen($number) == 8 && $number[0] == '5')
			$code = 53; // to cuba
		
		if (is_null($code)) { // to world
			
			if (isset($number[0])) {
				if ($number[0] != '+')
					$number = '+' . $number;
			
				foreach ( $codes as $xcode => $country ) {
					if (substr($number, 0, strlen($xcode) + 1) == '+' . $xcode) {
						$code = $xcode;
						$number = substr($number, strlen($xcode) + 1);
						break;
					}
				}
			}
			
			if (is_null($code))
				return false;
		}
		
		return array(
				'code' => $code,
				'number' => $number
		);
	}
	
	/**
	 * Returns a list of country phone codes
	 *
	 * @return array
	 */
	private function getCountryCodes(){
		return array(
				"2449" => "Angola",
				"3556" => "Albania",
				"1264" => "Anguila",
				"1268" => "Antigua y Barbuda",
				"1242" => "Bahamas",
				"8801" => "Bangladesh",
				"1246" => "Barbados",
				"5016" => "Belize",
				"2299" => "Benin",
				"1441" => "Bermuda",
				"3876" => "Bosnia y Herzegovina",
				"2677" => "Botswana",
				"1284" => "Islas Virgenes Britanicas",
				"2267" => "Burkina Faso",
				"2577" => "Burundi",
				"2389" => "Cabo Verde",
				"1345" => "Islas Cayman",
				"5068" => "Costa Rica",
				"3859" => "Croatia",
				"2693" => "Comoros",
				"1767" => "Dominica",
				"3725" => "Estonia",
				"2519" => "Etiopia",
				"5946" => "Guiana Francesa",
				"3505" => "Gibraltar",
				"3069" => "Grecia",
				"9769" => "Mongolia",
				"3826" => "Montenegro",
				"2126" => "Morocco",
				"2588" => "Mozambique",
				"2648" => "Namibia",
				"9779" => "Nepal",
				"1473" => "Grenada",
				"5926" => "Guyana",
				"9647" => "Iraq",
				"3538" => "Ireland",
				"1876" => "Jamaica",
				"9627" => "Jordan",
				"7300" => "Kazakhstan Beeline",
				"7300" => "Kazakhstan K-Cell",
				"2547" => "Kenya",
				"3897" => "Macedonia",
				"2613" => "Madagascar",
				"8562" => "Laos",
				"8536" => "Macau",
				"3706" => "Lithuania",
				"2189" => "Libyan Arab Jamahiriya",
				"9639" => "Syria",
				"8869" => "Taiwan",
				"9929" => "Tajikistan",
				"1868" => "Trinidad y Tobago",
				"5989" => "Uruguay",
				"1340" => "Islas Virgenes",
				"9677" => "Yemen",
				"2609" => "Zambia",
				"2507" => "Rwanda",
				"1869" => "Santa Kitts y Nevis",
				"1758" => "Santa Lucia",
				"6857" => "Samoa",
				"9665" => "Arabia Saudita",
				"2217" => "Senegal",
				"3816" => "Serbia",
				"9689" => "Oman",
				"5076" => "Panama",
				"6757" => "Papua Nueva Guinea",
				"5959" => "Paraguay",
				"3519" => "Portugal",
				"4219" => "Slovakia",
				"2499" => "Sudan",
				"5978" => "Suriname",
				"2686" => "Suiza",
				"9715" => "Emiratos Arabes Unidos",
				"9936" => "Turkmenistan",
				"1649" => "Islas Turcas y el Cairo",
				"855" => "Cambodia",
				"237" => "Cameroon",
				"591" => "Bolivia",
				"937" => "Afghanistan",
				"213" => "Algeria",
				"376" => "Andorra",
				"374" => "Armenia",
				"297" => "Aruba",
				"436" => "Austria",
				"994" => "Azerbaijan",
				"375" => "Belarus",
				"324" => "Belgium",
				"973" => "Bahrain",
				"673" => "Brunei Darussalam",
				"359" => "Bulgaria",
				"235" => "Chad",
				"243" => "Congo DR",
				"242" => "Republica del Congo",
				"682" => "Islas Cook",
				"861" => "China",
				"573" => "Colombia",
				"357" => "Chipre",
				"420" => "Republica Checa",
				"593" => "Ecuador",
				"201" => "Egipto",
				"503" => "El Salvador",
				"240" => "Guinea Equatorial",
				"253" => "Djibouti",
				"298" => "Islas Faroe",
				"679" => "Fiji",
				"358" => "Finland",
				"336" => "Francia",
				"689" => "Polinesia Francesa",
				"241" => "Republica de Gabonese",
				"220" => "Gambia",
				"995" => "Georgia",
				"233" => "Gana",
				"299" => "Groenlancia",
				"590" => "Guadalupe",
				"502" => "Guatemala",
				"509" => "Haiti",
				"504" => "Honduras",
				"852" => "Hong Kong",
				"354" => "Islandia",
				"628" => "Indonesia",
				"989" => "Iran",
				"393" => "Italy",
				"225" => "Costa de Marfil",
				"965" => "Kuwait",
				"996" => "Kirguistan",
				"371" => "letonia",
				"961" => "Libano",
				"266" => "lesoto",
				"231" => "Liberia",
				"423" => "Liechtenstein",
				"352" => "Luxemburgo",
				"265" => "Malawi",
				"601" => "Malasia",
				"960" => "Maldivas",
				"223" => "Mali",
				"356" => "Malta",
				"596" => "Martinica",
				"222" => "Mauritania",
				"230" => "Mauritius",
				"262" => "Mayotte y Reunion",
				"373" => "Moldova",
				"377" => "Monaco",
				"316" => "Holanda",
				"599" => "Antillas Holandesas",
				"642" => "Nueva Zelanda",
				"505" => "Nicaragua",
				"227" => "Niger",
				"234" => "Nigeria",
				"248" => "Seychelles",
				"232" => "Sierra Leone",
				"923" => "Pakistan",
				"974" => "Qatar",
				"407" => "Romania",
				"386" => "Slovenia",
				"998" => "Uzbekistan",
				"678" => "Vanuatu",
				"584" => "Venezuela",
				"947" => "Sri Lanka",
				"255" => "Tanzania",
				"228" => "Togo",
				"216" => "Tunisia",
				"905" => "Turquia",
				"256" => "Uganda",
				"380" => "Ucrania",
				"263" => "Zimbabwe",
				"54" => "Argentina",
				"56" => "Chile",
				"53" => "Cuba",
				"55" => "Brasil",
				"45" => "Dinamarca",
				"18" => "Republica Dominicana",
				"49" => "Alemania",
				"36" => "Hungria",
				"91" => "India",
				"97" => "Israel",
				"81" => "Japan",
				"52" => "Mexico",
				"82" => "Korea del Sur",
				"97" => "Palestina",
				"51" => "Peru",
				"63" => "Filipinas",
				"48" => "Polonia",
				"47" => "Noruega",
				"65" => "Singapur",
				"27" => "Sudafrica",
				"34" => "España",
				"46" => "Suecia",
				"41" => "Switzerland",
				"66" => "Tailandia",
				"44" => "Reino Unido",
				"84" => "VietNam",
				"6" => "Australia",
				"1" => "Guam",
				"1" => "Canada",
				"1" => "Estados Unidos de America",
				"1" => "Puerto Rico",
				"7" => "Federacion Rusa"
		);
	}
		
	private function getCredit(){
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$login = $di->get('config')['smsapi']['login'];
		$password = $di->get('config')['smsapi']['password'];
		
		$URL = "http://api.smsacuba.com/saldo.php?";
		$URL .= "login=" . $login . "&password=" . $password;

		$r = file_get_contents($URL);
		
		if ($r !== false) {
			return $r * 1;
		}
		
		return 0;
	}
}
