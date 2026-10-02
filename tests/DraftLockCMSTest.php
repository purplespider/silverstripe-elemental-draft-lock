<?php

namespace PurpleSpider\ElementalDraftLock\Tests;

use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementContent;
use PurpleSpider\ElementalDraftLock\DraftLock;
use PurpleSpider\ElementalDraftLock\Extensions\SiteTreeDraftLockExtension;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;

/**
 * The "Publish page and locked blocks" action, end to end through the CMS
 */
class DraftLockCMSTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        DraftLockTestPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        // Whatever the site running the tests has configured
        DraftLock::config()->set('enabled', true);
        DraftLock::config()->set('lock_new_blocks', false);
        SecurityToken::disable();
        $this->logInWithPermission('ADMIN');
    }

    private function makePage(): DraftLockTestPage
    {
        $page = DraftLockTestPage::create();
        $page->Title = 'Blocks page';
        $page->write();

        return $page;
    }

    private function makeBlock(DraftLockTestPage $page, string $title, bool $lock = false): ElementContent
    {
        $block = ElementContent::create();
        $block->Title = $title;
        $block->ParentID = $page->ElementalAreaID;
        $block->DraftLocked = $lock;
        $block->write();

        return $block;
    }

    private function isLive(BaseElement $block): bool
    {
        return (bool) Versioned::get_by_stage(BaseElement::class, Versioned::LIVE)->byID($block->ID);
    }

    private function action(DraftLockTestPage $page)
    {
        return $page->getCMSActions()->dataFieldByName('action_' . SiteTreeDraftLockExtension::ACTION);
    }

    public function testActionIsHiddenUntilThereIsALockedBlock()
    {
        $page = $this->makePage();
        $this->makeBlock($page, 'Normal');

        $this->assertNotNull($this->action($page), 'The action is always there for the script to show');
        $this->assertSame('hidden', $this->action($page)->getAttribute('hidden'), 'Nothing locked, so hidden');

        $this->makeBlock($page, 'Locked', true);

        $this->assertNull($this->action($page)->getAttribute('hidden'), 'Shown once a block is locked');
    }

    public function testActionIsNotOfferedWhenViewingAnOldVersion()
    {
        $page = $this->makePage();
        $this->makeBlock($page, 'Locked', true);
        $oldVersion = $page->Version;

        $page->Title = 'Renamed';
        $page->write();

        $old = Versioned::get_version(DraftLockTestPage::class, $page->ID, $oldVersion);

        $this->assertNull($this->action($old), 'An old version can only be rolled back, not published');
    }

    public function testPublishWithLockedBlocksPublishesEverything()
    {
        $page = $this->makePage();
        $locked = $this->makeBlock($page, 'Locked', true);
        $normal = $this->makeBlock($page, 'Normal');

        $response = $this->post('admin/pages/edit/EditForm/' . $page->ID, [
            'ID' => $page->ID,
            'Title' => 'Blocks page',
            'URLSegment' => $page->URLSegment,
            'action_' . SiteTreeDraftLockExtension::ACTION => 1,
        ]);

        $this->assertLessThan(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue($this->isLive($normal), 'Unlocked blocks go live as usual');
        $this->assertTrue($this->isLive($locked), 'Locked blocks go live too');
        $this->assertTrue(
            (bool) Versioned::get_by_stage(BaseElement::class, Versioned::DRAFT)->byID($locked->ID)->DraftLocked,
            'The switch is left as the editor set it, as with publishing the block on its own'
        );
    }

    public function testOrdinaryPublishStillHoldsLockedBlocks()
    {
        $page = $this->makePage();
        $locked = $this->makeBlock($page, 'Locked', true);

        $this->post('admin/pages/edit/EditForm/' . $page->ID, [
            'ID' => $page->ID,
            'Title' => 'Blocks page',
            'URLSegment' => $page->URLSegment,
            'action_publish' => 1,
        ]);

        $this->assertFalse($this->isLive($locked), 'The suspension must not outlive its own request');
    }
}
