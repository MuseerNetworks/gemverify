<?php
namespace Services;

use RuntimeException;

final class ZenithPayService
{
    /** @return array<string,mixed> */
    public function assignDedicatedAccount(string $bvn, string $accountName, string $firstName, string $lastName, string $email): array
    {
        if (ZENITHPAY_BEARER_TOKEN === '') {
            throw new RuntimeException('ZenithPay is not configured.');
        }

        $ch = curl_init(ZENITHPAY_ACCOUNT_ASSIGN_URL);
        $body = http_build_query([
            'bvn' => $bvn,
            'account_name' => $accountName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ]);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => ZENITHPAY_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . ZENITHPAY_BEARER_TOKEN,
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('ZenithPay request failed: ' . ($error ?: 'network error'));
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('ZenithPay returned an invalid response.');
        }
        if ($code < 200 || $code >= 300 || empty($data['status'])) {
            throw new RuntimeException((string) ($data['message'] ?? $data['error'] ?? 'ZenithPay could not create the account.'));
        }
        return $data;
    }
}
