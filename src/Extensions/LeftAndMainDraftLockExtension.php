<?php

namespace PurpleSpider\ElementalDraftLock\Extensions;

use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\View\Requirements;

/**
 * The CMS-side wiring for the module: the stylesheet, and placing the publish notices.
 *
 * @extends Extension<LeftAndMain>
 */
class LeftAndMainDraftLockExtension extends Extension
{
    /**
     * Loads the module's small stylesheet and script into the CMS.
     *
     * They are inlined rather than exposed as public assets deliberately. Together they are only
     * a few kilobytes, and inlining means the module needs no `expose` step and works the same
     * whether it is installed into vendor/ or wired up as a local path repository, where the
     * exposed URL and the one the module manifest reports do not agree.
     */
    public function onAfterInit()
    {
        if (!DraftLock::isEnabled()) {
            return;
        }

        $css = file_get_contents(__DIR__ . '/../../client/styles/draft-lock.css');

        if ($css !== false) {
            Requirements::customCSS($css, 'elemental-draft-lock');
        }

        $js = file_get_contents(__DIR__ . '/../../client/js/draft-lock.js');

        if ($js !== false) {
            Requirements::customScript($js, 'elemental-draft-lock');
        }
    }

    /**
     * Adds the notices underneath the block list.
     *
     * This runs from `CMSMain::getEditForm()`, after every `updateCMSFields` extension has had
     * its turn, which is the only point at which the block list field is guaranteed to exist.
     * {@see ElementalPublishNoticeExtension} for why that matters.
     */
    public function updateEditForm(Form $form)
    {
        if (!DraftLock::isEnabled()) {
            return;
        }

        $record = $form->getRecord();

        if (!$record || !$record->hasMethod('addElementalDraftLockNotices')) {
            return;
        }

        $record->addElementalDraftLockNotices($form->Fields());
    }
}
