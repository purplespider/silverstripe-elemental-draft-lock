<?php

namespace PurpleSpider\ElementalDraftLock\Extensions;

use DNADesign\Elemental\Models\BaseElement;
use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\Core\Extension;
use SilverStripe\Versioned\ChangeSetItem;
use SilverStripe\Versioned\Versioned;

/**
 * Holds a flagged block back when it has only been pulled into the changeset by ownership.
 *
 * Publishing a page builds an inferred ChangeSet containing the page explicitly and everything
 * it owns (its ElementalArea, and in turn every block) implicitly. ChangeSetItem::publish()
 * does nothing when the change type is CHANGE_NONE, so reporting CHANGE_NONE for an implicit,
 * held block is all that is needed to leave it in draft.
 *
 * Publishing a block directly adds it to the changeset explicitly, which is left alone so the
 * per-block Publish action still works. Deletes are left alone too, so archiving a held block
 * still removes it from the live site.
 *
 * Only a block with no live version is held, which is what CHANGE_CREATED means here. Once a
 * block has been published, later edits to it go live with the page as normal, even though its
 * flag is still set: the editor said it was ready when they published it. Unpublish it again and
 * the hold comes back into force, matching the switch the editor can see. This is done here
 * rather than by clearing the flag on publish; see BaseElementDraftLockExtension for why.
 *
 * @extends Extension<ChangeSetItem>
 */
class ChangeSetItemDraftLockExtension extends Extension
{
    public function updateChangeType(&$type, $draftVersion, $liveVersion)
    {
        if (!DraftLock::isEnabled()) {
            return;
        }

        // Only a block that is not on the live site at all can be held. Modified blocks, deletions
        // and items with nothing to publish are all left alone.
        if ($type !== ChangeSetItem::CHANGE_CREATED) {
            return;
        }

        // Publishing the block on its own must still work
        if ($this->owner->Added !== ChangeSetItem::IMPLICITLY) {
            return;
        }

        $objectClass = $this->owner->ObjectClass;

        if (!$objectClass || !is_a($objectClass, BaseElement::class, true)) {
            return;
        }

        $draft = Versioned::get_by_stage($objectClass, Versioned::DRAFT)
            ->byID($this->owner->ObjectID);

        if ($draft && $draft->DraftLocked) {
            $type = ChangeSetItem::CHANGE_NONE;
        }
    }
}
