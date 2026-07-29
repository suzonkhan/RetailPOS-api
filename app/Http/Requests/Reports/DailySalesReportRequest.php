<?php

namespace App\Http\Requests\Reports;

class DailySalesReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
