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

    private function toggle(BaseElement $block, bool $locked, ?string $token = null)
    {
        return $this->post('admin/draft-lock/toggle', array_filter([
            'ID' => $block->ID,
            'Locked' => $locked ? 1 : 0,
            'SecurityID' => $token,
        ], fn ($value) => $value !== null));
    }

    private function draft(BaseElement $block): BaseElement
    {
        return Versioned::get_by_stage(BaseElement::class, Versioned::DRAFT)->byID($block->ID);
    }

    public function testTheSwitchSavesTheLockStraightAway()
    {
        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Block');

        $response = $this->toggle($block, true);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(['ID' => $block->ID, 'Locked' => true], json_decode($response->getBody(), true));
        $this->assertTrue((bool) $this->draft($block)->DraftLocked);
        $this->assertFalse($this->isLive($block), 'Saving the lock must not publish the block');

        $this->toggle($block, false);

        $this->assertFalse((bool) $this->draft($block)->DraftLocked, 'And it unlocks again');
    }

    public function testTheSwitchLeavesTheRestOfTheBlockAlone()
    {
        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Original title');

        $this->toggle($block, true);

        $this->assertSame('Original title', $this->draft($block)->Title);
    }

    public function testStateReportsTheLock()
    {
        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Locked', true);

        $response = $this->get('admin/draft-lock/state?ID=' . $block->ID);

        $this->assertSame(['ID' => $block->ID, 'Locked' => true], json_decode($response->getBody(), true));
    }

    public function testTheSwitchNeedsTheSecurityToken()
    {
        SecurityToken::enable();

        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Block');

        $this->assertSame(400, $this->toggle($block, true)->getStatusCode());
        $this->assertFalse((bool) $this->draft($block)->DraftLocked);

        $this->assertSame(200, $this->toggle($block, true, SecurityToken::inst()->getValue())->getStatusCode());
        $this->assertTrue((bool) $this->draft($block)->DraftLocked);
    }

    public function testTheSwitchOnlyAcceptsPost()
    {
        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Block');

        $response = $this->get('admin/draft-lock/toggle?ID=' . $block->ID . '&Locked=1');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertFalse((bool) $this->draft($block)->DraftLocked);
    }

    public function testTheSwitchNeedsPermissionToEditTheBlock()
    {
        $page = $this->makePage();
        // Any CMS user may edit pages by default, so this page is restricted to administrators
        $page->CanEditType = 'OnlyTheseUsers';
        $page->write();
        $block = $this->makeBlock($page, 'Block');

        $this->logOut();
        $this->assertSame(403, $this->toggle($block, true)->getStatusCode(), 'Not logged in');

        // A CMS user who may not edit the page, whose permissions its blocks follow
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $this->assertSame(403, $this->toggle($block, true)->getStatusCode(), 'Cannot edit the block');

        $this->assertFalse((bool) $this->draft($block)->DraftLocked);
    }

    public function testUnknownBlocksAndADisabledModuleAreNotFound()
    {
        $this->assertSame(404, $this->get('admin/draft-lock/state?ID=999999')->getStatusCode());

        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Block');

        DraftLock::config()->set('enabled', false);

        $this->assertSame(404, $this->toggle($block, true)->getStatusCode());
        $this->assertFalse((bool) $this->draft($block)->DraftLocked);
    }
}
