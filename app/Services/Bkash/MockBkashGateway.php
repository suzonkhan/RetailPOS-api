<?php

namespace App\Services\Bkash;

use App\Contracts\BkashGateway;
use Illuminate\Support\Str;

class MockBkashGateway implements BkashGateway
{
    /** @var array<string, array{amount: string, merchantInvoiceNumber: string, executed: bool}> */
    private static array $payments = [];

    public function createPayment(
        string $amount,
        string $merchantInvoiceNumber,
        string $callbackUrl,
    ): array {
        $paymentId = 'MOCK'.Str::upper(Str::random(12));

        self::$payments[$paymentId] = [
            'amount' => $amount,
            'merchantInvoiceNumber' => $merchantInvoiceNumber,
            'executed' => false,
        ];

        $baseUrl = rtrim(config('app.url'), '/');

        return [
            'paymentID' => $paymentId,
            'bkashURL' => $baseUrl.'/mock-bkash/checkout?paymentID='.$paymentId.'&callback='.urlencode($callbackUrl),
            'statusCode' => '0000',
            'statusMessage' => 'Successful',
        ];
    }

    public function executePayment(string $paymentId): array
    {
        if (! isset(self::$payments[$paymentId])) {
            return [
                'statusCode' => '2023',
                'statusMessage' => 'Invalid payment ID',
            ];
        }

        if (self::$payments[$paymentId]['executed']) {
            return [
                'statusCode' => '2023',
                'statusMessage' => 'Payment already executed',
            ];
        }

        self::$payments[$paymentId]['executed'] = true;

        return [
            'statusCode' => '0000',
            'statusMessage' => 'Successful',
            'paymentID' => $paymentId,
            'trxID' => 'MOCKTRX'.Str::upper(Str::random(8)),
            'transactionStatus' => 'Completed',
            'amount' => self::$payments[$paymentId]['amount'],
        ];
    }

    public function isMock(): bool
    {
        return true;
    }

    public static function reset(): void
    {
        self::$payments = [];
    }
}
