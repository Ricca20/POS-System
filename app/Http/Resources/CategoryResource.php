<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\Category` exposed to the desktop SPA.
 *
 * Validates: R8.1, R8.2 (JSON-only).
 *
 * Field selection rationale
 * -------------------------
 * Mirrors the columns the SPA needs to populate the product/category and
 * tax-category dropdowns and the category management screens. The
 * `category_type` column distinguishes 'product' categories from 'tax'
 * categories — both cases are surfaced by the API and filtered via the
 * `?type=` query param on the index endpoint.
 *
 * Hidden columns
 * --------------
 * `deleted_at` is intentionally omitted — soft-deleted rows never appear
 * in this resource because the controller's query relies on the model's
 * `SoftDeletes` global scope. `module_category_data` (a denormalised JSON
 * blob historically attached by the legacy `TaxonomyController`) is also
 * omitted since module integrations consume it through dedicated
 * endpoints in their own module's API surface.
 */
class CategoryResource extends JsonResource
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
            'name' => $this->name,
            'business_id' => $this->business_id,

            // Hierarchy + classification.
            'parent_id' => $this->parent_id,
            'category_type' => $this->category_type,
            'short_code' => $this->short_code,
            'description' => $this->description,
            'slug' => $this->slug,

            // Optional WooCommerce mapping (Modules/Woocommerce surfaces it).
            'woocommerce_cat_id' => $this->woocommerce_cat_id,

            // Metadata.
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
