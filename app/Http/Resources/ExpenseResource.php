<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'amount' => (float) $this->amount,
            'expense_date' => $this->expense_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'expense_category_id' => $this->expense_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'is_system' => $this->category->is_system,
            ] : null),
            'staff_id' => $this->staff_id,
            'staff' => $this->whenLoaded('staff', fn () => $this->staff ? [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
            ] : null),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
            'purchase_id' => $this->purchase_id,
            'purchase' => $this->whenLoaded('purchase', fn () => $this->purchase ? [
                'id' => $this->purchase->id,
                'purchase_number' => $this->purchase->purchase_number,
            ] : null),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
