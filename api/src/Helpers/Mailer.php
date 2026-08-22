<?php
namespace Helpers;

class Mailer {
    /**
     * Send an HTML email using PHP's native mail function
     *
     * @param string $to
     * @param string $subject
     * @param string $htmlContent
     * @param string $fromEmail
     * @param string $fromName
     * @return bool
     */
    public static function sendHTML(
        string $to,
        string $subject,
        string $htmlContent,
        string $fromEmail = 'support@gemverify.com.ng',
        string $fromName = 'GemVerify Support'
    ): bool {
        // Headers for HTML mail
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];

        // Send the email
        return mail($to, $subject, $htmlContent, implode("\r\n", $headers));
    }
}
