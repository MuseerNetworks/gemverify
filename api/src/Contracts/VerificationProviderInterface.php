<?php
namespace Contracts;

interface VerificationProviderInterface
{
    /**
     * Submit an asynchronous or synchronous verification request.
     */
    public function submitAsync(string $serviceSlug, ?string $variantKey, array $formData): array;

    /**
     * Check the status of an ongoing request.
     */
    public function checkAsyncStatus(string $serviceSlug, ?string $variantKey, string $ticketId, ?string $trackingId = null): array;
}