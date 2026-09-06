<?php

namespace App\Http\Resources;

use App\Models\StoreUomConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StoreUomConversion */
class StoreUomConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_uom_id' => $this->from_uom_id,
            'to_uom_id' => $this->to_uom_id,
            'from_code' => $this->whenLoaded('fromUom', fn () => $this->fromUom?->code),
            'to_code' => $this->whenLoaded('toUom', fn () => $this->toUom?->code),
            'from_label' => $this->whenLoaded('fromUom', fn () => $this->fromUom?->label),
            'to_label' => $this->whenLoaded('toUom', fn () => $this->toUom?->label),
            'factor' => (float) $this->factor,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
