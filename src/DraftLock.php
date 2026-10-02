<?php

namespace PurpleSpider\ElementalDraftLock;

use SilverStripe\Core\Config\Configurable;

/**
 * Central config holder for the module, kept separate from the extension classes so that
 * setting `enabled` does not leak into the config of the classes being extended.
 */
class DraftLock
{
    use Configurable;

    /**
     * Set to false to switch the whole behaviour off for a site. The `DraftLocked` database
     * column stays in place, but it is hidden from the CMS and ignored when publishing.
     *
     * @config
     */
    private static bool $enabled = true;

    public static function isEnabled(): bool
    {
        return (bool) static::config()->get('enabled');
    }
}
