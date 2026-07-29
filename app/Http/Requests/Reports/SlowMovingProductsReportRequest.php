<?php

namespace App\Http\Requests\Reports;

class SlowMovingProductsReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('limit')) {
            $this->merge(['limit' => 50]);
        }
    }
}
