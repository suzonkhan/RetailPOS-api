<?php

namespace App\Services\Bkash;

use App\Contracts\BkashGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SandboxBkashGateway implements BkashGateway
{
    public function createPayment(
        string $amount,
        string $merchantInvoiceNumber,
        string $callbackUrl,
    ): array {
        $token = $this->grantToken();

        $response = Http::withHeaders($this->apiHeaders($token))
            ->post($this->url('create'), [
                'mode' => '0011',
                'payerReference' => $merchantInvoiceNumber,
                'callbackURL' => $callbackUrl,
                'amount' => $amount,
                'currency' => 'BDT',
                'intent' => 'sale',
                'merchantInvoiceNumber' => $merchantInvoiceNumber,
            ])
            ->throw()
            ->json();

        if (($response['statusCode'] ?? '') !== '0000') {
            throw new RuntimeException($response['statusMessage'] ?? 'bKash create payment failed');
        }

        return $response;
    }

    public function executePayment(string $paymentId): array
    {
        $token = $this->grantToken();

        $response = Http::withHeaders($this->apiHeaders($token))
            ->post($this->url('execute').'/'.$paymentId)
            ->throw()
            ->json();

        return $response;
    }

    public function isMock(): bool
    {
        return false;
    }

    private function grantToken(): string
    {
        return Cache::remember('bkash:id_token', 3500, function () {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'username' => config('retail360.bkash.username'),
                'password' => config('retail360.bkash.password'),
            ])
                ->post($this->url('grant'), [
                    'app_key' => config('retail360.bkash.app_key'),
                    'app_secret' => config('retail360.bkash.app_secret'),
                ])
                ->throw()
                ->json();

            $token = $response['id_token'] ?? null;

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('bKash grant token failed');
            }

            return $token;
        });
    }

    /**
     * @return array<string, string>
     */
    private function apiHeaders(string $token): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'authorization' => $token,
            'x-app-key' => config('retail360.bkash.app_key'),
        ];
    }

    private function url(string $endpoint): string
    {
        $base = rtrim(config('retail360.bkash.base_url'), '/');

        return match ($endpoint) {
            'grant' => $base.'/tokenized/checkout/token/grant',
            'create' => $base.'/tokenized/checkout/create',
            'execute' => $base.'/tokenized/checkout/execute',
            default => throw new RuntimeException('Unknown bKash endpoint'),
        };
    }
}
