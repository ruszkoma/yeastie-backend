<?php

namespace App\Services\Sync;

/**
 * Document types of `/sync` (API contract, `EntityType`). Anything else is rejected, so a client
 * cannot write other server data such as entitlements.
 */
final class EntityTypes
{
    /** Whole documents: the newer `updated_at` wins. */
    public const DOCUMENTS = [
        'user_profile', 'setting', 'brewhouse', 'fermenter', 'water_profile', 'beer_style', 'recipe',
        'batch', 'inventory_item',
    ];

    /** Append-only rows: inserted once, later only `deleted_at` can be set. Never stale. */
    public const APPEND_ONLY = ['inventory_movement', 'batch_measurement', 'batch_step_log'];

    /** Counters (`payload.value`): the larger value wins. */
    public const COUNTER = 'usage_counter';

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::DOCUMENTS, true)
            || in_array($type, self::APPEND_ONLY, true)
            || $type === self::COUNTER;
    }
}
