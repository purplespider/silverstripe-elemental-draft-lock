<?php

namespace PurpleSpider\ElementalDraftLock;

use DNADesign\Elemental\Models\BaseElement;
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

    /**
     * Set to true to have every newly created block start with "Lock as Draft" switched on, so
     * nothing new goes live with the page until the editor says it is ready. Existing blocks are
     * left as they are.
     *
     * @config
     */
    private static bool $lock_new_blocks = false;

    /**
     * Whether locks are being ignored for the publish in progress. Deliberately not a private
     * static, which Configurable would treat as config.
     */
    protected static bool $suspended = false;

    public static function isEnabled(): bool
    {
        return (bool) static::config()->get('enabled');
    }

    public static function locksNewBlocks(): bool
    {
        return static::isEnabled() && static::config()->get('lock_new_blocks');
    }

    /**
     * Whether publishing the page will leave this block behind: it is locked, and has no live
     * version. A published block with draft edits goes live with the page whatever its flag says.
     */
    public static function isHeld(BaseElement $element): bool
    {
        return (bool) $element->DraftLocked && !$element->isPublished();
    }

    public static function isSuspended(): bool
    {
        return static::$suspended;
    }

    /**
     * Runs $callback with every lock ignored, so a page publish inside it takes locked blocks
     * live too. Used by the "Publish page and locked blocks" action.
     */
    public static function withLocksSuspended(callable $callback): mixed
    {
        $previous = static::$suspended;
        static::$suspended = true;

        try {
            return $callback();
        } finally {
            static::$suspended = $previous;
        }
    }
}
