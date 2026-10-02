<?php

namespace PurpleSpider\ElementalDraftLock\Tests;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

class DraftLockTestPage extends SiteTree implements TestOnly
{
    private static $table_name = 'DraftLockTestPage';

    private static $extensions = [
        ElementalPageExtension::class,
    ];
}
