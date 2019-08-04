<?php

use Apretaste\Money;

class SmsService extends ApretasteService
{

    /**
     * Function executed when the service is called
     *
     * @param Request
     *
     * @return void
     * @throws \Exception
     * @author Kuma
     */
    public function _main()
    {
        $this->response->setLayout('sms.ejs');

        // get the size of the pool from the configs file
        $pool_size = $this->di()->get('config')['smsapi']['poolsize'];

        // check the total sent won't go over the pool
        $totalSMSThisWeek = q('SELECT COUNT(id) as total FROM _sms_messages
		WHERE WEEK(sent) = WEEK(CURRENT_TIMESTAMP)')[0]->total;

        if ($totalSMSThisWeek >= $pool_size) {
            $this->simpleMessage(
                "Su SMS no fue enviado",
                "Como seguramente conoce, en Apretaste regalamos cientos de créditos, pero pagamos por cada SMS que enviado. Para ofrecer este servicio gratuitamente, tenemos que poner un límite de $pool_size SMS diarios. Por favor, espere a mañana para seguir manando SMS. Disculpe las molestias.",
                null, 'sentiment_very_dissatisfied');

            return;
        }

        // message is the user has not enough credit
        if ($this->request->person->credit < 0.1) {
            $this->simpleMessage("Cr&eacute;dito insuficiente", "Su credito actual es {$this->request->person->credit} y es insuficiente para enviar el SMS. Usted necesita al menos &sect;0.10.");
        }

        // do not allow empty sms
        if ($this->isProfileIncomplete()) {
            $this->response->setTemplate('cellphone.ejs');

            return;
        }

        if (empty($this->request->input->data->number)) {
            $this->response->setTemplate('home.ejs', [
                'discount' => 0.05,
                'credit'   => $this->request->person->credit,
                'person'   => $this->request->person,
            ]);

            return;
        }

        // message is the user has zero credit
        if (isset($this->request->person->credit)) {
            $credit = $this->request->person->credit;
        } else {
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
            return;
        }

        $code = (int)$code;
        $cell_code = $code;
        if ($code != 53 && $code != 52 && $code != 1) {
            $this->simpleMessage("SMS no enviado", "Por el momento solo se pueden enviar mensajes a Canad&aacute;, USA, M&eacute;xico y Cuba. Disculpa las molestas.");

            return;
        }

        if ($code === 53) {
            $code = 1;
        } // Cuba
        elseif ($code === 52) {
            $code = 153;
        } // Mexico
        elseif ($code === 1
            && in_array((int)substr($number, 0, 3), [
                204,
                226,
                236,
                249,
                250,
                289,
                306,
                343,
                365,
                367,
                403,
                416,
                418,
                431,
                437,
                438,
                450,
                506,
                514,
                519,
                548,
                579,
                581,
                587,
                604,
                613,
                639,
                647,
                705,
                709,
                778,
                780,
                782,
                807,
                819,
                825,
                867,
                873,
                902,
                905,
            ], true)) {
            $code = 45;
        } // Canada
        elseif ($code === 1) {
            $code = 237;
        } // USA

        // send the SMS
        $sent = (new SMS($number,
            str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Ñ'], ['a', 'e', 'i', 'o', 'u', 'n', 'N'],
                (substr($text, 0, 160))
            ), $code))->send();

        // ensure the sms was sent correctly
        if ((int)$sent->code !== 200) {
            $this->simpleMessage(
                "SMS no enviado",
                "El SMS no se pudo enviar debido a problemas t&eacute;nicos. Int&eacute;ntelo m&aacute;s tarde o contacte al soporte t&eacute;nico.");

            return;
        }

        $message = str_replace("'", "", $text);

        //Utils::addCredit($discount * -1, 'SMS', $this->request->person->id);
        Money::transfer($this->request->person->id, Money::BANK, (float) $discount, 'SMS');

        q("INSERT INTO _sms_messages(person_id, `email`,`code`,`number`,`text`,`price`) VALUES ('{$this->request->person->id}','{$this->request->person->email}','$code','$number','$message','$discount');");

        // prepare info to be sent to the view
        $responseContent = [
            "credit"     => $credit - $discount,
            "msg"        => $text,
            "bodyextra"  => $textExtra,
            "poolleft"   => $pool_size - $totalSMSThisWeek,
            "cellnumber" => "(+$cell_code)$number",
        ];

        // send the OK email
        $this->response->setTemplate("basic.ejs", $responseContent);
    }

    /**
     * Update profile
     */
    public function _profile()
    {
        if (!empty($this->request->input->data->cellphone)) {
            q("UPDATE person SET cellphone = '{$this->request->input->data->cellphone}' where id = '{$this->request->person->id}' and (cellphone is null or cellphone = '');");
        }
        $this->_main();
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

        return 0.05;
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
                    $number = '+'.$number;
                }
                foreach ($codes as $xcode => $country) {
                    if (substr($number, 0, strlen($xcode) + 1) == '+'.$xcode) {
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
        return require __DIR__."/codes.php";
    }

    /**
     * Check profile completion
     *
     * @return bool
     */
    private function isProfileIncomplete(): bool
    {
        // ensure your profile is completed
        return empty($this->request->person->cellphone.'');
    }
}
