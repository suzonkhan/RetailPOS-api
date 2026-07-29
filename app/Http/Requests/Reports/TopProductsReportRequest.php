<?php

namespace App\Http\Requests\Reports;

class TopProductsReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sort_by' => ['sometimes', 'string', 'in:revenue,quantity'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->has('limit')) {
            $merge['limit'] = 10;
        }

        if (! $this->has('sort_by')) {
            $merge['sort_by'] = 'revenue';
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
