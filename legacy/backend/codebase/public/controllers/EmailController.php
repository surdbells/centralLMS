<?php
if (str_replace(DIRECTORY_SEPARATOR, "/", __FILE__) == $_SERVER['SCRIPT_FILENAME']) {
    exit();
}

    function sendVerificationEmail($userEmail, $token, $firstname){
        $fromAddress = SYSTEM_EMAIL;
        $subject = 'i-Fitness Learning - Registration Confirmation';
        $message = WELCOME_EMAIL;
        $message = str_replace('{{first_name}}', $firstname, $message);
        $message = str_replace('{{confirmation_link}}', $token, $message);
        $postData = array(
            'from' => array('address' => $fromAddress),
            'to' => array(array(
                'email_address' => array(
                    'address' => $userEmail
                )
            )
            ),
            'subject' => $subject,
            'htmlbody' => $message
        );
        $jsonData = json_encode($postData);
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.zeptomail.com/v1.1/email",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => array(
            "accept: application/json",
            "authorization: Zoho-enczapikey wSsVR61y+BH3C/grnjOrcuw8mFQHAl2kExt731f3vSP+GPnH/cc+wRHHAw+jHvAcRGI8E2NBoLJ9nxcA1WEL3Ngsm1hSWyiF9mqRe1U4J3x17qnvhDzNW21dkBKBKIMOxQpjn2BoE8Aq+g==",
            "cache-control: no-cache",
            "content-type: application/json",
        ),
    ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            error_log('Message not sent ' . $response);
        } else {
            error_log('Your message has been sent successfully ' . $response);
        }
    }

    function sendActivate($userEmail, $firstname){
        $fromAddress = SYSTEM_EMAIL;
        $subject = 'i-Fitness Learning - Password Reset Confirmation.';
        $message = ACTIVATION_EMAIL;
        $message = str_replace('{{first_name}}', $firstname, $message);
        $postData = array(
            'from' => array('address' => $fromAddress),
            'to' => array(array(
                'email_address' => array(
                    'address' => $userEmail
                )
            )
            ),
            'subject' => $subject,
            'htmlbody' => $message
        );
        $jsonData = json_encode($postData);
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.zeptomail.com/v1.1/email",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => array(
            "accept: application/json",
            "authorization: Zoho-enczapikey wSsVR61y+BH3C/grnjOrcuw8mFQHAl2kExt731f3vSP+GPnH/cc+wRHHAw+jHvAcRGI8E2NBoLJ9nxcA1WEL3Ngsm1hSWyiF9mqRe1U4J3x17qnvhDzNW21dkBKBKIMOxQpjn2BoE8Aq+g==",
            "cache-control: no-cache",
            "content-type: application/json",
        ),
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            error_log('Message not sent ' . $response);
        } else {
            error_log('Your message has been sent successfully ' . $response);
        }
  
    }

    function sendResetConfirmation($userEmail, $token, $firstname){
        $fromAddress = SYSTEM_EMAIL;
        $subject = 'i-Fitness Learning - Password Reset Confirmation.';
        $message = RESET_PASSWORD;
        $message = str_replace('{{first_name}}', $firstname, $message);
        $message = str_replace('{{confirmation_link}}', $token, $message);
        $postData = array(
            'from' => array('address' => $fromAddress),
            'to' => array(array(
                'email_address' => array(
                    'address' => $userEmail
                )
            )
            ),
            'subject' => $subject,
            'htmlbody' => $message
        );
        $jsonData = json_encode($postData);
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.zeptomail.com/v1.1/email",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => array(
            "accept: application/json",
            "authorization: Zoho-enczapikey wSsVR61y+BH3C/grnjOrcuw8mFQHAl2kExt731f3vSP+GPnH/cc+wRHHAw+jHvAcRGI8E2NBoLJ9nxcA1WEL3Ngsm1hSWyiF9mqRe1U4J3x17qnvhDzNW21dkBKBKIMOxQpjn2BoE8Aq+g==",
            "cache-control: no-cache",
            "content-type: application/json",
        ),
    ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            error_log('Message not sent ' . $response);
        } else {
            error_log('Your message has been sent successfully ' . $response);
        }
  
    }

    function sendMessage($firstname, $userEmail, $sender_name, $subject, $internal_message){
        $fromAddress = SYSTEM_EMAIL;
        $subject = 'i-Fitness Learning - You have a new message.';
        $message = INTERNAL_MESSAGE;
        $message = str_replace('{{first_name}}', $firstname, $message);
        $message = str_replace('{{sender_name}}', $sender_name, $message);
        $message = str_replace('{{subject}}', $subject, $message);
        $message = str_replace('{{message}}', $internal_message, $message);
        $postData = array(
            'from' => array('address' => $fromAddress),
            'to' => array(array(
                'email_address' => array(
                    'address' => $userEmail
                )
            )
            ),
            'subject' => $subject,
            'htmlbody' => $message
        );
        $jsonData = json_encode($postData);
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.zeptomail.com/v1.1/email",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => array(
            "accept: application/json",
            "authorization: Zoho-enczapikey wSsVR61y+BH3C/grnjOrcuw8mFQHAl2kExt731f3vSP+GPnH/cc+wRHHAw+jHvAcRGI8E2NBoLJ9nxcA1WEL3Ngsm1hSWyiF9mqRe1U4J3x17qnvhDzNW21dkBKBKIMOxQpjn2BoE8Aq+g==",
            "cache-control: no-cache",
            "content-type: application/json",
        ),
    ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            error_log('Message not sent ' . $response);
        } else {
            error_log('Your message has been sent successfully ' . $response);
        }
    }

    function sendNewsletter($firstname, $userEmail, $subject, $newsletter){
        $fromAddress = SYSTEM_EMAIL;
        $message = $newsletter;
        $message = str_replace('{{first_name}}', $firstname, $message);
        $postData = array(
            'from' => array('address' => $fromAddress),
            'to' => array(array(
                'email_address' => array(
                    'address' => $userEmail
                )
            )
            ),
            'subject' => "i-Fitness Learning - " .  $subject,
            'htmlbody' => $message
        );
        $jsonData = json_encode($postData);
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.zeptomail.com/v1.1/email",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => array(
            "accept: application/json",
            "authorization: Zoho-enczapikey wSsVR61y+BH3C/grnjOrcuw8mFQHAl2kExt731f3vSP+GPnH/cc+wRHHAw+jHvAcRGI8E2NBoLJ9nxcA1WEL3Ngsm1hSWyiF9mqRe1U4J3x17qnvhDzNW21dkBKBKIMOxQpjn2BoE8Aq+g==",
            "cache-control: no-cache",
            "content-type: application/json",
        ),
    ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            error_log('Message not sent ' . $response);
        } else {
            error_log('Your message has been sent successfully ' . $response);
        }
}

?>