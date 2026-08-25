<?php
require '../vendor/autoload.php';
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class EmailHandler{
    private Client $client;
    private string $apiKey;
    private string $system_email;
    private string $r_template;
    private string $current_year;
    private string $otp_template;
    private string $generic_template;
    private string $course_enrollment;
    private string $onboarding_template;
    private string $register_subject;
    private string $enrollment_subject;
    private string $otp_subject;
    private string $password_change_subject;
    private string $get_started_subject;
    private string $generic_subject;
    private string $password_change_message;
    private string $new_login_message;
    private string $get_started_message;
    private string $onboarding_message;


    public function __construct(){
        $this->apiKey = 'Zoho-enczapikey wSsVR610+ULzC6x4nmb/Lr0xnwsGAFP3FEl921Wl6CSvTPDC9sdpxkPMAw+gSvFMEWdsRTMSpLgvkE0H0WELj90vyVwDCSiF9mqRe1U4J3x17qnvhDzPXWpblheOLI8JwA5okmJpFsgi+g==';
        $this->system_email = 'CentraLMS@centrallms.com';
        $this->client = new Client([
            'base_uri' => 'https://api.zeptomail.com/v1.1/',
            'timeout'  => 30,
        ]);
        $this->r_template = file_get_contents('emails/account-confirmation.html', true);
        $this->otp_template = file_get_contents('emails/account-validation.html', true);
        $this->generic_template = file_get_contents('emails/generic.html', true);
        $this->onboarding_template = file_get_contents('emails/onboarding.html', true);
        $this->course_enrollment = file_get_contents('emails/course_enrollment.html', true);
        $this->register_subject = 'Registration confirmation';
        $this->enrollment_subject = 'Course Enrollment:';
        $this->get_started_subject = 'Welcome to Central LMS – Let’s Get You Started!';
        $this->otp_subject = 'Your One-Time Password (OTP) for Verification';
        $this->password_change_subject = 'Your Password Has Been Changed Successfully';
        $this->generic_subject = 'Central LMS Notification';
        $this->new_login_message = 'Please be informed that your account was accessed at <strong>' . $this->getDubaiDateTime() . "</strong> If you did not log on to your profile at the time detailed above, please reset your password immediately or contact our support team.";
        $this->current_year = $this->getDubaiCurrentYear();
        $this->password_change_message = 'This is to confirm that your account password was successfully changed. If you did not authorize this change, please reset your password immediately or contact our support team.';
        $this->get_started_message ="Welcome to Central LMS! 🎉<br><br>" .
            "Your account has been successfully created, and you're now part of our growing learning community.<br>" .
            "To get the best experience, please take a moment to complete your profile information. This helps personalize your learning environment and makes it easier for your instructors or administrators to support you effectively.<br><br>" .
            "🚀 Next Step:<br><br>" .
            "Head over to your Settings → Profile Information to update your name, photo, contact details, and any other required information.<br><br>" .
            "If you ever need help, our support team is always available to assist you — just reach out!<br><br>" .
            "We’re excited to have you here and can’t wait to see you enjoy your learning journey!<br><br>" .
            "Warm regards,<br>" .
            "<strong>The Central LMS Team</strong>";

        $this->onboarding_message ="Welcome to Central LMS! 🎉<br><br>" .
            "Your account has been successfully created, and you're now part of our growing learning community.<br>" .
            "To get the best experience, please take a moment to complete your profile information. This helps personalize your learning environment and makes it easier for your instructors or administrators to support you effectively.<br><br>" .
            "🚀 Next Step:<br><br>" .
            "Head over to your Settings → Profile Information to update your name, photo, contact details, and any other required information.<br><br>" .
            "If you ever need help, our support team is always available to assist you — just reach out!<br><br>" .
            "We’re excited to have you here and can’t wait to see you enjoy your learning journey!<br><br>" .
            "Warm regards,<br>" .
            "<strong>The Central LMS Team</strong>";
        }

    private function sendEmail($fromAddress, $toEmail, $subject, $htmlBody): void{
        $postData = [
            'from' => ['address' => $fromAddress],
            'to' => [ [ 'email_address' => [ 'address' => $toEmail ] ] ],
            'subject' => $subject,
            'htmlbody' => $htmlBody
        ];

        try {
            $response = $this->client->post('email', [
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => $this->apiKey,
                    'cache-control' => 'no-cache',
                    'content-type' => 'application/json',
                ],
                'json' => $postData
            ]);

            $body = $response->getBody()->getContents();
            error_log('Email sent successfully: ' . $body);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                error_log('Email failed: ' . $e->getResponse()->getBody()->getContents());
            } else {
                error_log('Email failed: ' . $e->getMessage());
            }
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {

        }
    }
    public function Registration($firstname, $otp, $userEmail): void{
        $fromAddress = $this->system_email;
        $message = $this->r_template;
        $message = str_replace('{{NAME_PH}}', $firstname, $message);
        $message = str_replace('{{OTP_PH}}', $otp, $message);
        $message = str_replace('{{CURRENT_YEAR}}', $this->current_year, $message);

        $this->sendEmail($fromAddress, $userEmail, $this->register_subject, $message);
    }
    public function send_otp($firstname, $otp, $userEmail): void{
        $fromAddress = $this->system_email;
        $message = $this->otp_template;
        $message = str_replace('{{NAME_PH}}', $firstname, $message);
        $message = str_replace('{{OTP_PH}}', $otp, $message);
        $message = str_replace('{{CURRENT_YEAR}}', $this->current_year, $message);
        $this->sendEmail($fromAddress, $userEmail, $this->otp_subject, $message);
    }
    public function password_changed($firstname, $userEmail): void{
        $fromAddress = $this->system_email;
        $message = $this->generic_template;
        $message = str_replace('{{NAME_PH}}', $firstname, $message);
        $message = str_replace('{{MESSAGE}}', $this->password_change_message, $message);
        $message = str_replace('{{CURRENT_YEAR}}', $this->current_year, $message);
        $this->sendEmail($fromAddress, $userEmail, $this->password_change_subject, $message);
    }
    public function login_notification($firstname, $userEmail): void{
        $fromAddress = $this->system_email;
        $message = $this->generic_template;
        $message = str_replace('{{NAME_PH}}', $firstname, $message);
        $message = str_replace('{{MESSAGE}}', $this->new_login_message, $message);
        $message = str_replace('{{CURRENT_YEAR}}', $this->current_year, $message);
        $this->sendEmail($fromAddress, $userEmail, $this->generic_subject, $message);
    }
    public function get_started_notification($firstname, $userEmail): void{
        $fromAddress = $this->system_email;
        $message = $this->generic_template;
        $message = str_replace('{{NAME_PH}}', $firstname, $message);
        $message = str_replace('{{MESSAGE}}', $this->get_started_message, $message);
        $message = str_replace('{{CURRENT_YEAR}}', $this->current_year, $message);
        $this->sendEmail($fromAddress, $userEmail, $this->get_started_subject, $message);
    }
    public function course_enrollment($firstname, $course_title,$due,$mandatory,$support, $userEmail): void{
        $fromAddress = $this->system_email;
        $message = $this->course_enrollment;
        $message = str_replace('{{FIRST_NAME}}', $firstname, $message);
        $message = str_replace('{{COURSE_TITLE}}', $course_title, $message);
        $message = str_replace('{{DUE_DATE}}', $due, $message);
        $message = str_replace('{{IS_MANDATORY}}', $mandatory, $message);
        $message = str_replace('{{SUPPORT_EMAIL}}', 'info@kodekinnovations.com', $message);
        $message = str_replace('{{COURSE_URL}}', 'https://web.centrallms.com/login', $message);
        $message = str_replace('{{CURRENT_YEAR}}', $this->current_year, $message);
        $this->sendEmail($fromAddress, $userEmail, $this->enrollment_subject . " " . $course_title , $message);
    }
    public function onboarding_notification($firstname, $userEmail, $password): void{

        $fromAddress = $this->system_email;
        $message = $this->onboarding_template;
        $message = str_replace('{{NAME_PH}}', $firstname, $message);
        $message = str_replace('{{EMAIL}}', $userEmail, $message);
        $message = str_replace('{{PASSWORD}}', $password, $message);
        $message = str_replace('{{MESSAGE}}', $this->onboarding_message, $message);
        $message = str_replace('{{CURRENT_YEAR}}', $this->current_year, $message);
        $this->sendEmail($fromAddress, $userEmail, $this->get_started_subject, $message);
    }
    function getDubaiDateTime(): string{
        $current_date  = "";
        $timezone = new DateTimeZone('Asia/Dubai');
        try {
            $date = new DateTime('now', $timezone);
            return $date->format('F j, Y \a\t g:i A');
        } catch (Exception $e) {
        }
        return $current_date;
    }
    function getDubaiCurrentYear(): string{
        $current_year  = "";
        $timezone = new DateTimeZone('Asia/Dubai');
        try {
            $date = new DateTime('now', $timezone);
            return $date->format('Y');
        } catch (Exception $e) {
        }
        return $current_year;
    }
}
