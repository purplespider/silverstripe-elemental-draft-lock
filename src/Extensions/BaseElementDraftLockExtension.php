<?php

namespace PurpleSpider\ElementalDraftLock\Extensions;

use DNADesign\Elemental\Models\BaseElement;
use PurpleSpider\ElementalDraftLock\Controllers\DraftLockController;
use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Versioned\Versioned;

/**
 * Adds the "Lock as Draft" flag to every Elemental block.
 *
 * The flag itself is versioned along with the rest of the block, so it lives on the draft
 * record. {@see ChangeSetItemDraftLockExtension} reads it from there when a page publish
 * tries to drag the block along, and {@see DraftLockController} changes it.
 *
 * @extends Extension<BaseElement>
 */
class BaseElementDraftLockExtension extends Extension
{
    private static array $db = [
        'DraftLocked' => 'Boolean',
    ];

    /**
     * IDs of blocks being archived, which unpublishes them on the way. Kept by ID rather than as
     * a flag on this object, because one extension instance is shared by every block. Not a
     * private static, which would be treated as config.
     */
    protected static array $archiving = [];

    /**
     * Runs only when a block is first created, so `lock_new_blocks` never touches existing blocks
     */
    public function populateDefaults()
    {
        if (DraftLock::locksNewBlocks()) {
            $this->owner->DraftLocked = true;
        }
    }

    /**
     * The lock is not a form field. It is changed only by the switch in the block header, which
     * saves straight away through {@see DraftLockController}, so a block form loaded before a
     * change can never save over it.
     */
    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName('DraftLocked');
    }

    public function onBeforeArchive()
    {
        static::$archiving[$this->owner->ID] = true;
    }

    public function onAfterArchive()
    {
        unset(static::$archiving[$this->owner->ID]);
    }

    /**
     * With `lock_on_unpublish`, a block taken off the live site on its own is locked, so the next
     * page publish does not quietly put it back.
     *
     * Unpublishing a page does not unpublish its blocks, so this only runs for a block unpublished
     * directly, or one being archived, which is skipped: it is about to leave the draft stage too.
     *
     * Elemental does not tell the page about this, so the script re-reads the block's lock when it
     * sees the block go back to draft; see client/js/draft-lock.js.
     */
    public function onAfterUnpublish()
    {
        if (!DraftLock::locksOnUnpublish() || isset(static::$archiving[$this->owner->ID])) {
            return;
        }

        $owner = $this->owner;

        Versioned::withVersionedMode(function () use ($owner) {
            Versioned::set_stage(Versioned::DRAFT);

            $draft = Versioned::get_by_stage(get_class($owner), Versioned::DRAFT)->byID($owner->ID);

            if ($draft && !$draft->DraftLocked) {
                $draft->DraftLocked = true;
                $draft->write();
                $owner->DraftLocked = true;
            }
        });
    }

    // Deliberately no onBeforePublish that clears DraftLocked. A published block is not held
    // because the hold only applies while a block has no live version (see
    // ChangeSetItemDraftLockExtension), so the switch can go on saying what the editor set, and
    // comes back into force if the block is unpublished again.
}
