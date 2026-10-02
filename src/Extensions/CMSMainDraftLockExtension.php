<?php

namespace PurpleSpider\ElementalDraftLock\Extensions;

use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;

/**
 * Handles "Publish page and locked blocks", added by {@see SiteTreeDraftLockExtension}.
 *
 * It is the ordinary Publish action with the locks suspended, so the page and its blocks are
 * saved, permission-checked and published exactly as they would be otherwise. The CMS then
 * reloads the whole edit form, so every switch and notice on it reflects the result.
 *
 * @extends Extension<CMSMain>
 */
class CMSMainDraftLockExtension extends Extension
{
    private static array $allowed_actions = [
        SiteTreeDraftLockExtension::ACTION,
    ];

    public function publishwithlockedblocks(array $data, Form $form): HTTPResponse
    {
        $response = DraftLock::withLocksSuspended(fn () => $this->owner->publish($data, $form));

        $record = $form->getRecord();

        if ($response->getStatusCode() < 400 && $record) {
            $response->addHeader('X-Status', rawurlencode(_t(
                __CLASS__ . '.PUBLISHED_WITH_LOCKED',
                "Published '{title}' and its locked blocks",
                ['title' => $record->Title]
            )));
        }

        return $response;
    }
}
