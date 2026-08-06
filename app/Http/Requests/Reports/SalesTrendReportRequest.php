<?php

namespace App\Http\Requests\Reports;

class SalesTrendReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'period' => ['sometimes', 'string', 'in:hour,day,week,month'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('period')) {
            $this->merge(['period' => 'day']);
        }
    }
}
