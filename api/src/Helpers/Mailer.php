<?php
namespace Helpers;

class Mailer {
    /**
     * Send an HTML email using SMTP socket connection to localhost,
     * or falling back to PHP native mail() if socket connection is unavailable.
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
        // Try local SMTP server on port 25
        $host = '127.0.0.1';
        $port = 25;
        $timeout = 5;
        
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            // Try port 587 as fallback
            $port = 587;
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        }
        
        if ($socket) {
            // Helper to read SMTP responses safely
            $readResponse = function($socket) {
                $response = '';
                while ($line = fgets($socket, 512)) {
                    $response .= $line;
                    if (isset($line[3]) && $line[3] === ' ') {
                        break;
                    }
                }
                return $response;
            };
            
            // SMTP Handshake
            $readResponse($socket); // 220 greeting
            
            fwrite($socket, "EHLO localhost\r\n");
            $readResponse($socket); // 250 responses
            
            fwrite($socket, "MAIL FROM:<$fromEmail>\r\n");
            $res = $readResponse($socket);
            if (strpos($res, '250') !== 0) {
                fclose($socket);
                return self::fallbackMail($to, $subject, $htmlContent, $fromEmail, $fromName);
            }
            
            fwrite($socket, "RCPT TO:<$to>\r\n");
            $res = $readResponse($socket);
            if (strpos($res, '250') !== 0 && strpos($res, '251') !== 0) {
                fclose($socket);
                return self::fallbackMail($to, $subject, $htmlContent, $fromEmail, $fromName);
            }
            
            fwrite($socket, "DATA\r\n");
            $res = $readResponse($socket);
            if (strpos($res, '354') !== 0) {
                fclose($socket);
                return self::fallbackMail($to, $subject, $htmlContent, $fromEmail, $fromName);
            }
            
            // Construct MIME headers
            $headers = [
                "MIME-Version: 1.0",
                "Content-Type: text/html; charset=UTF-8",
                "From: =?utf-8?B?" . base64_encode($fromName) . "?= <$fromEmail>",
                "Reply-To: <$fromEmail>",
                "To: <$to>",
                "Subject: =?utf-8?B?" . base64_encode($subject) . "?=",
                "Date: " . date('r'),
                "X-Mailer: GemVerify SMTP Socket"
            ];
            
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $htmlContent . "\r\n.\r\n";
            fwrite($socket, $data);
            $res = $readResponse($socket);
            
            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            
            if (strpos($res, '250') === 0) {
                return true;
            }
        }
        
        // Fallback to native PHP mail if socket connection fails or transaction errors
        return self::fallbackMail($to, $subject, $htmlContent, $fromEmail, $fromName);
    }
    
    /**
     * Fallback sending method using native PHP mail() function
     */
    private static function fallbackMail(
        string $to,
        string $subject,
        string $htmlContent,
        string $fromEmail,
        string $fromName
    ): bool {
        if (!function_exists('mail')) {
            return false;
        }
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];
        
        return @mail($to, $subject, $htmlContent, implode("\r\n", $headers));
    }
}
