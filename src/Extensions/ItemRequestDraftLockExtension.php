<?php

namespace PurpleSpider\ElementalDraftLock\Extensions;

use DNADesign\Elemental\Models\BaseElement;
use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\LiteralField;

/**
 * Puts the "Lock as Draft" switch in the action bar of a block's full edit screen, which is
 * where a block that is not edited inline is opened. It saves straight away through
 * {@see \PurpleSpider\ElementalDraftLock\Controllers\DraftLockController}, like the one in the
 * block header.
 *
 * Unlike the header, this screen is reloaded after Save, Publish and Unpublish, so whether the
 * switch is shown, and how it is set, can simply be decided here.
 *
 * @extends Extension<GridFieldDetailForm_ItemRequest>
 */
class ItemRequestDraftLockExtension extends Extension
{
    public function updateFormActions(FieldList $actions)
    {
        $record = $this->owner->getRecord();

        if (!DraftLock::isEnabled()
            || !($record instanceof BaseElement)
            || !$record->isInDB()
            || $record->isPublished()
            || !$record->canEdit()
        ) {
            return;
        }

        $toggle = LiteralField::create('DraftLockToggle', $this->toggleHTML($record));

        // At the start of the group on the right, beside the previous and next arrows
        $rightGroup = $actions->fieldByName('RightGroup');

        $first = $rightGroup ? $rightGroup->getChildren()->first() : null;

        if ($first) {
            $rightGroup->insertBefore($first->getName(), $toggle);
        } elseif ($rightGroup) {
            $rightGroup->push($toggle);
        } else {
            $actions->push(CompositeField::create($toggle)->setName('DraftLockGroup')->addExtraClass('ml-auto'));
        }
    }

    private function toggleHTML(BaseElement $record): string
    {
        $locked = (bool) $record->DraftLocked;
        $attributes = [
            'type' => 'button',
            'class' => 'elemental-draft-lock-header elemental-draft-lock-form-toggle',
            'role' => 'switch',
            'aria-checked' => $locked ? 'true' : 'false',
            'title' => _t(
                ElementalPublishNoticeExtension::class . '.TOGGLE_TITLE',
                'When on, publishing the page will not publish this block'
            ),
            'data-id' => $record->ID,
            'data-toggle-url' => Director::baseURL() . 'admin/draft-lock/toggle',
            'data-toggle-failed' => _t(
                ElementalPublishNoticeExtension::class . '.TOGGLE_FAILED',
                'The lock could not be changed. Reload the page and try again.'
            ),
        ];

        $html = '<button';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . Convert::raw2att((string) $value) . '"';
        }

        return $html . '>'
            . '<span class="elemental-draft-lock-header__track" aria-hidden="true"></span>'
            . '<span class="elemental-draft-lock-header__label">'
            . Convert::raw2xml(_t(BaseElementDraftLockExtension::class . '.LOCK_AS_DRAFT', 'Lock as Draft'))
            . '</span></button>';
    }
}
