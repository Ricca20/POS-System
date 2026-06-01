<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\VariationTemplate` exposed to the desktop SPA.
 *
 * Validates: R8.1, R8.2 (JSON-only).
 *
 * A variation template (e.g. "T-Shirt sizes") owns a list of value
 * templates (e.g. "S", "M", "L") via the `variation_value_templates`
 * table — modeled as `VariationTemplate::values()`. The legacy product
 * editor sends both the template and its value list together, so the
 * resource embeds the values when the relation is eager-loaded by the
 * controller. Using `whenLoaded` keeps the projection lazy: callers
 * that don't need the values pay no SQL cost.
 */
class VariationTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,

            // Embedded value list — only emitted when the controller has
            // eager-loaded the `values` relation. Each row is projected
            // via the inline closure rather than a dedicated resource
            // class so the value templates remain a thin appendage.
            'variation_value_templates' => $this->whenLoaded('values', function () {
                return $this->values->map(function ($value) {
                    return [
                        'id' => $value->id,
                        'name' => $value->name,
                        'variation_template_id' => $value->variation_template_id,
                        'created_at' => optional($value->created_at)->toIso8601String(),
                        'updated_at' => optional($value->updated_at)->toIso8601String(),
                    ];
                })->all();
            }),

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
