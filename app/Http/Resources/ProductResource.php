<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\Product` exposed to the desktop SPA via the
 * `/api/v1/products` resource (task 4.1).
 *
 * Validates: R8.1, R8.2 (JSON-only).
 *
 * Field selection rationale
 * -------------------------
 * The legacy `App\Http\Controllers\ProductController` is a 2000+ line
 * surface that handles variations, image upload, modifiers, kits,
 * opening stock, location attachments, expiration, etc. This leaf task
 * deliberately exposes only the core/most-used columns the SPA needs to
 * render a product list, view a product, and create/update simple
 * "single" products. Variations, image uploads, location attachments,
 * modifiers, weighing-scale config, repair fields, and similar features
 * are deferred to follow-up tasks (4.2 and beyond).
 *
 * Specifically excluded (and why):
 *   - `image` / `image_url`: file paths and accessors are tied to the
 *     uploads pipeline, which lives in a later task. Exposing the path
 *     here would mislead the SPA into trying to render an image that
 *     this endpoint cannot accept on write.
 *   - `expiry_period`, `expiry_period_type`: belong with the lot/batch
 *     workflow, not the basic CRUD surface.
 *   - `secondary_unit_id`, `sub_unit_ids`: secondary unit handling is
 *     entangled with conversion ratios that aren't yet wired through
 *     the API; kept out so the SPA doesn't depend on partial state.
 *   - `weighing_scale_*`, `repair_*`, `essential_*`: feature-flagged
 *     module fields. They will be exposed by their owning module's
 *     dedicated endpoint when that module is ported.
 *   - `is_inactive`: distinct flag from `not_for_selling`; a separate
 *     activate/deactivate endpoint will manage it (mirrors the legacy
 *     `ProductController::activate` / `massDeactivate` flow).
 *   - `bank_details`-style legacy fields: not used by the SPA and would
 *     leak operational metadata.
 *
 * `enable_stock` and `not_for_selling` are stored as `tinyint(1)` and
 * are explicitly cast to `bool` so the SPA can use strict comparisons
 * (`=== true` / `=== false`) without coercion guards.
 *
 * `category`, `unit`, and `brand` relations are exposed only when
 * `whenLoaded()` resolves them — the controller eager-loads exactly
 * those three relations, but if a future caller forgoes them this
 * resource degrades to absent keys without an N+1.
 */
class ProductResource extends JsonResource
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
            // Identity / scoping
            'id' => $this->id,
            'name' => $this->name,
            'business_id' => $this->business_id,
            'type' => $this->type,

            // Identification / classification
            'sku' => $this->sku,
            'barcode_type' => $this->barcode_type,

            // Stock / sale flags
            'enable_stock' => (bool) $this->enable_stock,
            'alert_quantity' => $this->alert_quantity,
            'not_for_selling' => (bool) $this->not_for_selling,

            // Foreign keys (note `tax` column rather than `tax_rate_id`)
            'unit_id' => $this->unit_id,
            'category_id' => $this->category_id,
            'sub_category_id' => $this->sub_category_id,
            'brand_id' => $this->brand_id,
            'tax' => $this->tax,
            'tax_type' => $this->tax_type,

            // Misc descriptive
            'weight' => $this->weight,
            'product_description' => $this->product_description,

            // Audit
            'created_by' => $this->created_by,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),

            // Embedded relations — only when the controller eager-loaded them.
            //
            // Lightweight inline projections live here rather than in a
            // dedicated resource class because the full `CategoryResource`
            // / `BrandResource` / `UnitResource` classes are owned by
            // task 4.3. The closure form of `whenLoaded` keeps the keys
            // entirely absent (rather than `null`) when the relation
            // wasn't eager-loaded, so an unrelated caller never gets
            // misleading nulls.
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit === null ? null : [
                'id' => $this->unit->id,
                'actual_name' => $this->unit->actual_name ?? null,
                'short_name' => $this->unit->short_name ?? null,
            ]),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand === null ? null : [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ]),
        ];
    }
}
