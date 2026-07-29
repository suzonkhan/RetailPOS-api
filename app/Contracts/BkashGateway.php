<?php

namespace App\Contracts;

interface BkashGateway
{
    /**
     * @return array{paymentID: string, bkashURL: string, statusCode?: string, statusMessage?: string}
     */
    public function createPayment(
        string $amount,
        string $merchantInvoiceNumber,
        string $callbackUrl,
    ): array;

    /**
     * @return array{statusCode: string, statusMessage?: string, trxID?: string, transactionStatus?: string, paymentID?: string}
     */
    public function executePayment(string $paymentId): array;

    public function isMock(): bool;
}
