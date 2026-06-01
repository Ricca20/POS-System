<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\User` exposed to the desktop SPA.
 *
 * Validates: R10.2, R10.3 (Sanctum login envelope + `auth/me` parity).
 *
 * Field selection rationale
 * -------------------------
 * The legacy `users` table stores far more than the SPA needs: hashed
 * password, opaque API tokens, tax/banking metadata, payroll details,
 * personal data (DOB), Passport remember-me state, etc. This resource
 * exposes a deliberately minimal *safe* subset sufficient to render the
 * SPA shell, gate UI by permissions, and round-trip identity through
 * `auth/me`.
 *
 * Deliberately NOT exposed
 * ------------------------
 *   - `password`, `remember_token`, `api_token` — credential material;
 *     never leaves the server.
 *   - `bank_details` — legacy column with banking info.
 *   - `dob`, `gender`, `essentials_pay`, `essentials_salary`,
 *     `essentials_*` payroll fields — out of scope for the SPA shell;
 *     dedicated HR endpoints handle these with their own permission
 *     gating.
 *   - Address blob (`address`, `permanent_address`, `family_number`) —
 *     not needed for shell render.
 *
 * The `permissions` projection
 * ----------------------------
 * Spatie's `getAllPermissions()` issues SELECTs against
 * `model_has_permissions`, `permissions`, and `roles` and may fail in
 * environments where those tables are absent (e.g. lightweight test
 * fixtures without the full migration set). The legacy
 * `SetSessionDataApi` middleware adopts the same defensive pattern —
 * wrap the Spatie call in `try { } catch (\Throwable) { return [] }` —
 * so the resource never crashes a request just because permission
 * tables are missing. With the tables present, the array contains the
 * deduplicated names of every permission the user has via direct
 * assignment OR through any of their roles.
 */
class UserResource extends JsonResource
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
            // Identity
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'surname' => $this->surname,

            // Profile / locale
            'language' => $this->language,
            'user_type' => $this->user_type,
            'business_id' => $this->business_id,

            // Auth state
            'status' => $this->status,
            'allow_login' => (bool) $this->allow_login,

            // Timestamps as ISO8601 so the SPA never has to guess timezone.
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),

            // Permission projection. Resolution is best-effort: the SPA
            // treats an empty list as "no permissions yet" rather than
            // erroring out, mirroring `SetSessionDataApi::resolvePermissions`.
            'permissions' => $this->resolvePermissions(),
        ];
    }

    /**
     * Resolve the user's effective permission name list, swallowing any
     * driver / schema errors so the resource degrades gracefully.
     *
     * @return array<int, string>
     */
    private function resolvePermissions(): array
    {
        try {
            return $this->getAllPermissions()->pluck('name')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
