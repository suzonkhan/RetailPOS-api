<?php

namespace App\Services\Reports;

use App\Models\Store;
use App\Models\User;
use App\Services\Sales\SalesScopeService;
use Carbon\Carbon;

class ReportScopeService
{
    public function __construct(
        private readonly SalesScopeService $salesScope,
    ) {}

    public function storeFor(User $user): Store
    {
        return $this->salesScope->resolveStore($user);
    }

    /**
     * @return array{from: Carbon, to: Carbon, from_date: string, to_date: string}
     */
    public function resolveDateRange(array $input): array
    {
        $to = isset($input['to'])
            ? Carbon::parse($input['to'])->endOfDay()
            : now()->endOfDay();

        $from = isset($input['from'])
            ? Carbon::parse($input['from'])->startOfDay()
            : $to->copy()->startOfMonth()->startOfDay();

        if ($from->gt($to)) {
            $from = $to->copy()->startOfDay();
        }

        return [
            'from' => $from,
            'to' => $to,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
        ];
    }
}
