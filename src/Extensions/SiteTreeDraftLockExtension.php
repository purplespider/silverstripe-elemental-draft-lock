<?php

namespace PurpleSpider\ElementalDraftLock\Extensions;

use DNADesign\Elemental\Models\BaseElement;
use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Versioned\Versioned;

/**
 * Adds "Publish page and locked blocks" to the page's more options menu.
 *
 * The action is handled by {@see CMSMainDraftLockExtension::publishwithlockedblocks()}.
 *
 * It is added whenever the page could have locked blocks, and hidden when it has none. Locking,
 * unlocking and publishing a block all happen without the page form being re-fetched, so the
 * script shows and hides it as the block list changes, the same way it keeps the notices current.
 *
 * @extends Extension<SiteTree>
 */
class SiteTreeDraftLockExtension extends Extension
{
    public const ACTION = 'publishwithlockedblocks';

    public function updateCMSActions(FieldList $actions)
    {
        $owner = $this->owner;

        if (!DraftLock::isEnabled()
            || !$owner->isInDB()
            || !$owner->hasMethod('getElementalRelations')
            || !$owner->getElementalRelations()
            || !$owner->isOnDraft()
            || !$owner->canPublish()
        ) {
            return;
        }

        // Not when viewing an old version of the page, where the CMS offers only roll back
        $draft = Versioned::get_by_stage(SiteTree::class, Versioned::DRAFT)->byID($owner->ID);

        if (!$draft || $draft->Version != $owner->Version) {
            return;
        }

        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');

        if (!$moreOptions) {
            return;
        }

        $action = FormAction::create(
            self::ACTION,
            _t(__CLASS__ . '.PUBLISH_WITH_LOCKED', 'Publish page and locked blocks')
        )
            ->addExtraClass('btn-secondary elemental-draft-lock-publish-all');

        if (!$this->hasHeldBlocks()) {
            $action->setAttribute('hidden', 'hidden');
        }

        // Next to Unpublish where there is one, otherwise at the end
        $moreOptions->insertBefore('action_unpublish', $action);
    }

    /**
     * Whether publishing the page as normal would leave any block behind
     */
    public function hasHeldBlocks(): bool
    {
        foreach ($this->owner->getElementalRelations() as $relationName) {
            $area = $this->owner->$relationName();

            if (!$area || !$area->exists()) {
                continue;
            }

            foreach ($area->Elements() as $element) {
                if ($element instanceof BaseElement && DraftLock::isHeld($element)) {
                    return true;
                }
            }
        }

        return false;
    }
}
