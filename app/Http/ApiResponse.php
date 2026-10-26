<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * One envelope for the JSON API — docs/desktop-mobile-plan.md A2.
 *
 * Every hand-rolled controller answered with an ad-hoc `response()->json(['success' => …])`; the shape
 * agreed by habit, not by contract. This is the contract: a success carries `success: true` and whatever
 * the endpoint returns beside it; an error carries `success: false`, a `message`, and any extra fields
 * (a validation bag, an `mfa_required` flag). The shapes are unchanged from what shipped, so a client in
 * the field keeps working — this centralises them so the next endpoint cannot drift.
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>  $payload  merged beside `success: true` — data, count, a token, …
     */
    public static function success(array $payload = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, ...$payload], $status);
    }

    /**
     * @param  array<string, mixed>  $extra  merged beside the message — `errors`, `mfa_required`, …
     */
    public static function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, ...$extra], $status);
    }
}
