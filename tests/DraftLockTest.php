<?php

namespace PurpleSpider\ElementalDraftLock\Tests;

use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementContent;
use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class DraftLockTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        DraftLockTestPage::class,
    ];

    /**
     * Elemental only creates a page's block area while the reading stage is draft, which it
     * always is in the CMS. A bare test run can start on live, leaving the page with no area.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    private function makePage(): DraftLockTestPage
    {
        $page = DraftLockTestPage::create();
        $page->Title = 'Blocks page';
        $page->write();

        return $page;
    }

    private function makeBlock(DraftLockTestPage $page, string $title, bool $hold = false): ElementContent
    {
        $block = ElementContent::create();
        $block->Title = $title;
        $block->HTML = "<p>$title</p>";
        $block->ParentID = $page->ElementalAreaID;
        $block->DraftLocked = $hold;
        $block->write();

        return $block;
    }

    private function isLive(BaseElement $block): bool
    {
        return (bool) Versioned::get_by_stage(BaseElement::class, Versioned::LIVE)->byID($block->ID);
    }

    public function testHeldBlockIsNotPublishedWithThePage()
    {
        $page = $this->makePage();
        $held = $this->makeBlock($page, 'Held', true);
        $normal = $this->makeBlock($page, 'Normal');

        $page->publishRecursive();

        $this->assertFalse($this->isLive($held), 'Held block should stay off the live stage');
        $this->assertTrue($this->isLive($normal), 'Unheld sibling should be published as normal');
    }

    private function reload(BaseElement $block): BaseElement
    {
        return Versioned::get_by_stage(BaseElement::class, Versioned::DRAFT)->byID($block->ID);
    }

    /**
     * Once a block has been published it is no longer held, so later edits go live with the page
     * even though its flag is still set: the editor said it was ready when they published it.
     */
    public function testEditsToAPublishedBlockGoLiveWithThePageEvenIfFlagged()
    {
        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Already live');
        $page->publishRecursive();

        $block = $this->reload($block);
        $block->Title = 'Changed in draft';
        $block->DraftLocked = true;
        $block->write();

        $page->publishRecursive();

        $live = Versioned::get_by_stage(BaseElement::class, Versioned::LIVE)->byID($block->ID);
        $this->assertSame('Changed in draft', $live->Title, 'A published block is not held, so its edits go live');
    }

    /**
     * The flag must only change when the editor saves it. Publishing from the block's own menu is
     * a GraphQL call after which Elemental does not re-fetch the inline form, so anything the
     * server changed here would leave the switch in the browser saying something untrue.
     */
    public function testPublishingTheBlockOnItsOwnLeavesTheFlagAlone()
    {
        $page = $this->makePage();
        $held = $this->makeBlock($page, 'Held', true);

        $page->publishRecursive();
        $this->assertFalse($this->isLive($held));

        $held->publishRecursive();

        $this->assertTrue($this->isLive($held), 'Publishing the block directly should still work');
        $this->assertTrue((bool) $this->reload($held)->DraftLocked, 'The flag must be left as the editor saved it');
    }

    /**
     * The reported bug, step for step: lock, publish the block on its own, unpublish it, publish
     * the page. The switch still shows the block as locked, so the page publish must honour that.
     */
    public function testABlockPublishedOnItsOwnThenUnpublishedIsHeldAgain()
    {
        $page = $this->makePage();
        $page->publishRecursive();

        $block = $this->makeBlock($page, 'Locked', true);

        $block->publishRecursive();
        $this->assertTrue($this->isLive($block));

        $this->reload($block)->doUnpublish();
        $this->assertFalse($this->isLive($block));
        $this->assertTrue((bool) $this->reload($block)->DraftLocked, 'Still shown as locked, and still locked');

        $page->publishRecursive();

        $this->assertFalse($this->isLive($block), 'A locked block must not go live with the page');
    }

    public function testPublishingThePageDoesNotClearAnyHold()
    {
        $page = $this->makePage();
        $held = $this->makeBlock($page, 'Held', true);
        $this->makeBlock($page, 'Normal');

        $page->publishRecursive();

        $draft = Versioned::get_by_stage(BaseElement::class, Versioned::DRAFT)->byID($held->ID);
        $this->assertTrue((bool) $draft->DraftLocked, 'A page publish should leave the hold in place');
    }

    public function testDeletingAHeldBlockStillRemovesItFromLive()
    {
        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Live then held');
        $page->publishRecursive();
        $this->assertTrue($this->isLive($block));

        $block->DraftLocked = true;
        $block->write();
        $block->doArchive();

        $this->assertFalse($this->isLive($block), 'Archiving a held block should remove it from live');
    }

    /**
     * Whether the field is *visible* is a CSS question, not a server-side one. It is built for
     * every block and hidden by the stylesheet, keyed on the draft/published class Elemental keeps
     * current on the block wrapper. Deciding it here instead would freeze the answer into the
     * form, which is never re-fetched when a block is published from its own menu, so the field
     * would stay wrong until the page was reloaded.
     *
     * What is worth pinning down, then, is the contract the stylesheet relies on: the field is
     * always there, and always carries the class the CSS hooks onto.
     */
    public function testTheFieldIsAlwaysBuiltAndCarriesTheStylesheetHook()
    {
        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Block');

        $field = fn ($el) => $el->getCMSFields()->fieldByName('Root.Main.DraftLocked');

        $this->assertNotNull($field($block), 'A draft block should offer the hold');
        $this->assertStringContainsString(
            'elemental-draft-lock',
            $field($block)->extraClass(),
            'The stylesheet needs this class to hide the field once the block is live'
        );

        $block->publishRecursive();
        $block = Versioned::get_by_stage(BaseElement::class, Versioned::DRAFT)->byID($block->ID);

        $this->assertNotNull($field($block), 'A published block keeps the field; CSS hides it');

        $block->HTML = '<p>edited</p>';
        $block->write();

        $this->assertNotNull($field($block), 'And keeps it once modified on draft too');
    }

    public function testDisablingTheModuleRemovesTheField()
    {
        DraftLock::config()->set('enabled', false);

        $page = $this->makePage();
        $block = $this->makeBlock($page, 'Block');

        $this->assertNull(
            $block->getCMSFields()->fieldByName('Root.Main.DraftLocked'),
            'Disabling the module should drop the scaffolded field as well as our own'
        );
    }

    public function testDisablingTheModulePublishesEverything()
    {
        DraftLock::config()->set('enabled', false);

        $page = $this->makePage();
        $held = $this->makeBlock($page, 'Held', true);

        $page->publishRecursive();

        $this->assertTrue($this->isLive($held), 'With the module disabled the flag should be ignored');
    }
}
