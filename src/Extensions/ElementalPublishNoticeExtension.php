<?php

namespace PurpleSpider\ElementalDraftLock\Extensions;

use DNADesign\Elemental\Models\BaseElement;
use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;

/**
 * Shows the editor, underneath the block list, which blocks will and will not go live when they
 * publish the page.
 *
 * The notices are not added from `updateCMSFields`. `ElementalPageExtension` is declared on the
 * page class itself, so it is always applied after an extension on SiteTree and the block list
 * field does not exist yet when this extension's `updateCMSFields` would run. There is no public
 * way for an extension to defer work until after its siblings, so the insertion is driven from
 * {@see LeftAndMainDraftLockExtension::updateEditForm()} instead, once the FieldList is whole.
 *
 * @extends Extension<SiteTree>
 */
class ElementalPublishNoticeExtension extends Extension
{
    /**
     * Put a notice directly below each elemental area field that has something worth saying.
     */
    public function addElementalDraftLockNotices(FieldList $fields): void
    {
        if (!DraftLock::isEnabled()) {
            return;
        }

        $owner = $this->owner;

        if (!$owner->isInDB() || !$owner->hasMethod('getElementalRelations')) {
            return;
        }

        $relations = $owner->getElementalRelations();

        if (!$relations) {
            return;
        }

        foreach ($relations as $relationName) {
            $notice = $this->buildNotice($relationName);

            if (!$notice) {
                continue;
            }

            // Skipped rather than appended if the block list is missing: on its own, adrift at the
            // bottom of the form, the notice would not make much sense
            $fields->insertAfter($relationName, $notice, false);
        }
    }

    private function buildNotice(string $relationName): ?LiteralField
    {
        $area = $this->owner->$relationName();

        if (!$area || !$area->exists()) {
            return null;
        }

        $held = [];
        $unpublished = [];
        $heldIDs = [];
        $types = [];

        foreach ($area->Elements() as $element) {
            if (!($element instanceof BaseElement)) {
                continue;
            }

            // The block type is only in the DOM as a hover tooltip, so the script is given it
            $types[$element->ID] = $element->getType();

            if ($element->DraftLocked) {
                $heldIDs[] = $element->ID;
            }

            $published = $element->isPublished();
            $pending = !$published || !$element->isLiveVersion();

            // Nothing to say about a block that is live and up to date
            if (!$pending) {
                continue;
            }

            // The same rule the publish itself follows
            if (DraftLock::isHeld($element)) {
                $held[] = $element;
            } else {
                $unpublished[] = $element;
            }
        }

        $strings = [
            'held-heading' => _t(
                __CLASS__ . '.HELD_HEADING',
                'Locked Blocks'
            ),
            'held-intro' => _t(
                __CLASS__ . '.HELD_INTRO',
                'These blocks are locked as draft and won\'t be published automatically with the page:'
            ),
            'held-hint' => _t(
                __CLASS__ . '.HELD_HINT',
                'Publish one from its three dots menu, or all of them from the page\'s three dots menu.'
            ),
            'pending-heading' => _t(
                __CLASS__ . '.UNPUBLISHED_HEADING',
                'Unlocked Blocks'
            ),
            'pending-intro' => _t(
                __CLASS__ . '.UNPUBLISHED_INTRO',
                'These draft blocks will be published with the page:'
            ),
            'pending-hint' => _t(
                __CLASS__ . '.UNPUBLISHED_HINT',
                'Not ready? Switch on "Lock as Draft".'
            ),
            'untitled' => _t(__CLASS__ . '.UNTITLED', 'Untitled'),
            // For the header switch, which the script builds
            'toggle-title' => _t(
                __CLASS__ . '.TOGGLE_TITLE',
                'When on, publishing the page will not publish this block'
            ),
        ];

        $attributes = ' data-held-ids="' . Convert::raw2att(implode(',', $heldIDs)) . '"'
            . ' data-lock-on-unpublish="' . (DraftLock::locksOnUnpublish() ? '1' : '0') . '"'
            . ' data-block-types="' . Convert::raw2att(json_encode($types, JSON_FORCE_OBJECT)) . '"';

        foreach ($strings as $key => $value) {
            $attributes .= ' data-' . $key . '="' . Convert::raw2att($value) . '"';
        }

        // The container is rendered even when there is nothing to say, so that the script has
        // somewhere to put a notice when a block is unpublished from its own menu
        $html = '<div class="elemental-draft-lock-notices"' . $attributes . '>'
            . $this->panel('warning', 'held', $strings['held-heading'], $strings['held-intro'], $held, $strings['held-hint'])
            . $this->panel(
                'info',
                'pending',
                $strings['pending-heading'],
                $strings['pending-intro'],
                $unpublished,
                $strings['pending-hint']
            )
            . '</div>';

        return LiteralField::create('ElementalDraftLockNotice' . $relationName, $html);
    }

    /**
     * A heading, an optional line saying why, the blocks listed one per line, then what to do
     * about them.
     */
    private function panel(
        string $type,
        string $role,
        string $heading,
        string $intro,
        array $elements,
        string $hint
    ): string
    {
        $items = '';

        foreach ($elements as $element) {
            $items .= '<li>' . $this->describe($element) . '</li>';
        }

        return '<div class="alert alert-' . $type . ' elemental-draft-lock-notice"'
            . ' role="alert" data-role="' . $role . '"' . ($elements ? '' : ' hidden') . '>'
            . '<strong>' . $heading . '</strong>'
            . ($intro === '' ? '' : '<span class="elemental-draft-lock-notice__intro">' . $intro . '</span>')
            . '<ul class="elemental-draft-lock-notice__list">' . $items . '</ul>'
            . '<span class="elemental-draft-lock-notice__hint">' . $hint . '</span>'
            . '</div>';
    }

    /**
     * The block's title with its type after it, falling back to just the type when a block has
     * been left untitled. Kept in step with the same markup built by client/js/draft-lock.js.
     */
    private function describe(BaseElement $element): string
    {
        $title = trim((string) $element->Title);

        $name = $title === ''
            ? '<em>' . _t(__CLASS__ . '.UNTITLED', 'Untitled') . '</em>'
            : Convert::raw2xml($title);

        return $name . ' (' . Convert::raw2xml($element->getType()) . ')';
    }
}
