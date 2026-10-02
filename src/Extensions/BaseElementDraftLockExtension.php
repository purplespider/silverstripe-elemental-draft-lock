<?php

namespace PurpleSpider\ElementalDraftLock\Extensions;

use DNADesign\Elemental\Models\BaseElement;
use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;

/**
 * Adds the "Lock as Draft" flag to every Elemental block.
 *
 * The flag itself is versioned along with the rest of the block, so it lives on the draft
 * record. {@see ChangeSetItemDraftLockExtension} reads it from there when a page publish
 * tries to drag the block along.
 *
 * @extends Extension<BaseElement>
 */
class BaseElementDraftLockExtension extends Extension
{
    private static array $db = [
        'DraftLocked' => 'Boolean',
    ];

    /**
     * Runs only when a block is first created, so `lock_new_blocks` never touches existing blocks
     */
    public function populateDefaults()
    {
        if (DraftLock::locksNewBlocks()) {
            $this->owner->DraftLocked = true;
        }
    }

    public function updateCMSFields(FieldList $fields)
    {
        // Drop the scaffolded checkbox, we place our own version below
        $fields->removeByName('DraftLocked');

        if (!DraftLock::isEnabled()) {
            return;
        }

        // The field is always built, even for a published block where it means nothing, and is
        // hidden by CSS keyed on the live/draft class Elemental puts on the block. Deciding it
        // here instead would bake the answer into the form at render time, and the inline block
        // form is not re-fetched when a block is published or unpublished from its own menu, so
        // the field would stay wrong until the page was reloaded.
        $fields->addFieldToTab(
            'Root.Main',
            CheckboxField::create(
                'DraftLocked',
                _t(__CLASS__ . '.LOCK_AS_DRAFT', 'Lock as Draft')
            )
                ->setDescription(_t(
                    __CLASS__ . '.LOCK_AS_DRAFT_DESCRIPTION',
                    'Publishing the page will skip this block and leave it in draft.'
                ))
                ->addExtraClass('elemental-draft-lock')
        );
    }

    // Deliberately no onBeforePublish/onAfterUnpublish that changes DraftLocked.
    //
    // It used to be cleared when a block was published from its own menu. That is a GraphQL call,
    // and Elemental never re-fetches the inline block form afterwards, so the browser went on
    // showing the switch as on. Unpublishing the block then revealed a switch that said "locked"
    // over a database that said otherwise, and the next page publish sent the block live.
    //
    // So the flag only ever changes when the editor saves it, and "a published block is no longer
    // held" is expressed in ChangeSetItemDraftLockExtension instead, by the hold only applying
    // while the block has no live version.
}
