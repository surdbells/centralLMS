<?php

class OTPGenerator
{
    public string $otp;
    public string $input_otp;
    public string $expires_at;
    private int $length = 6;
    private int $expirySeconds = 600; // 10 minutes
    private string $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    public function generateOTP(): array{
        $otp = $this->generateRandomOTP();
        $expiresAt = time() + $this->expirySeconds;
        return [
            'otp' => $otp,
            'expires_at' => $expiresAt
        ];
    }
    public function isOTPExpired(int $expiresAt): bool
    {
        return time() > $expiresAt;
    }
    public function validateOTP(string $inputOtp, string $storedOtp, int $expiresAt): bool
    {
        return !$this->isOTPExpired($expiresAt) && $inputOtp === $storedOtp;
    }
    private function generateRandomOTP(): string
    {
        $otp = '';
        $maxIndex = strlen($this->charset) - 1;

        for ($i = 0; $i < $this->length; $i++) {
            try {
                $otp .= $this->charset[random_int(0, $maxIndex)];
            } catch (\Random\RandomException $e) {
            }
        }
        return $otp;
    }
}
