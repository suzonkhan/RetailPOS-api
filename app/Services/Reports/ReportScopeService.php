<?php

namespace App\Services\Reports;

use App\Models\Store;
use App\Models\User;
use App\Services\Sales\SalesScopeService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ReportScopeService
{
    public function __construct(
        private readonly SalesScopeService $salesScope,
    ) {}

    public function storeFor(User $user): Store
    {
        return $this->salesScope->resolveStore($user);
    }

    public function hasFullReportAccess(User $user): bool
    {
        return $user->can('reports.view');
    }

    public function hasLimitedReportAccess(User $user): bool
    {
        return ! $this->hasFullReportAccess($user) && $user->can('dashboard.view');
    }

    /**
     * @return array{from: Carbon, to: Carbon, from_date: string, to_date: string}
     */
    public function resolveDateRange(array $input, ?User $user = null): array
    {
        $to = isset($input['to'])
            ? AppTimezone::endOfDay($input['to'])
            : AppTimezone::now()->endOfDay();

        $from = isset($input['from'])
            ? AppTimezone::startOfDay($input['from'])
            : $to->copy()->startOfMonth()->startOfDay();

        if ($from->gt($to)) {
            $from = $to->copy()->startOfDay();
        }

        if ($user !== null && $this->hasLimitedReportAccess($user)) {
            [$from, $to] = $this->clampToYesterdayAndToday($from, $to);
        }

        return [
            'from' => $from,
            'to' => $to,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function resolveDayBounds(User $user, mixed $date): array
    {
        $from = AppTimezone::startOfDay($date);
        $to = AppTimezone::endOfDay($date);

        if ($this->hasLimitedReportAccess($user)) {
            [$from, $to] = $this->clampToYesterdayAndToday($from, $to);
        }

        return [$from, $to];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function clampToYesterdayAndToday(CarbonInterface $from, CarbonInterface $to): array
    {
        $todayStart = AppTimezone::now()->startOfDay();
        $yesterdayStart = $todayStart->copy()->subDay();
        $todayEnd = AppTimezone::now()->endOfDay();

        if ($from->lt($yesterdayStart)) {
            $from = $yesterdayStart->copy();
        }
        if ($to->gt($todayEnd)) {
            $to = $todayEnd->copy();
        }
        if ($from->gt($to)) {
            $from = $to->copy()->startOfDay();
        }

        return [$from, $to];
    }
}
