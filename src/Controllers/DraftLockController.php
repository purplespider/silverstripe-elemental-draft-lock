<?php

namespace PurpleSpider\ElementalDraftLock\Controllers;

use DNADesign\Elemental\Models\BaseElement;
use PurpleSpider\ElementalDraftLock\DraftLock;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;

/**
 * Reads and saves a block's lock for the "Lock as Draft" switch in the block header.
 *
 * The switch saves the moment it is thrown, like the actions in the block's own menu, rather than
 * waiting for the block or page to be saved. The lock is not a field in the block's form at all,
 * so there is only ever one place it can be changed, and a form loaded before a change can never
 * save over it.
 *
 *   GET  admin/draft-lock/state?ID=12             {"ID": 12, "Locked": true}
 *   POST admin/draft-lock/toggle  ID, Locked, SecurityID
 */
class DraftLockController extends Controller
{
    private static array $allowed_actions = [
        'state',
        'toggle',
    ];

    /**
     * Everything here runs in the draft stage, as the CMS does. In the default live stage a block
     * on an unpublished page cannot find its page, and its permissions then fall back to allowing
     * any CMS user.
     */
    protected function handleAction($request, $action)
    {
        return Versioned::withVersionedMode(function () use ($request, $action) {
            Versioned::set_stage(Versioned::DRAFT);

            return parent::handleAction($request, $action);
        });
    }

    public function state(HTTPRequest $request): HTTPResponse
    {
        $block = $this->findBlock($request->getVar('ID'));

        if ($block instanceof HTTPResponse) {
            return $block;
        }

        if (!$block->canView()) {
            return $this->error(403, 'Not allowed');
        }

        return $this->json($block);
    }

    public function toggle(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->error(405, 'POST only');
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->error(400, 'Security token missing or expired, reload the page');
        }

        $block = $this->findBlock($request->postVar('ID'));

        if ($block instanceof HTTPResponse) {
            return $block;
        }

        if (!$block->canEdit()) {
            return $this->error(403, 'Not allowed');
        }

        $locked = (bool) $request->postVar('Locked');

        if ((bool) $block->DraftLocked !== $locked) {
            $block->DraftLocked = $locked;
            $block->write();
        }

        return $this->json($block);
    }

    /**
     * The block's draft record, or the error response to send instead
     */
    private function findBlock($id): BaseElement|HTTPResponse
    {
        if (!DraftLock::isEnabled()) {
            return $this->error(404, 'Not found');
        }

        if (!Permission::check('CMS_ACCESS')) {
            return $this->error(403, 'Not allowed');
        }

        $block = Versioned::get_by_stage(BaseElement::class, Versioned::DRAFT)->byID((int) $id);

        return $block ?: $this->error(404, 'Block not found');
    }

    private function json(BaseElement $block): HTTPResponse
    {
        return HTTPResponse::create(json_encode([
            'ID' => (int) $block->ID,
            'Locked' => (bool) $block->DraftLocked,
        ]))->addHeader('Content-Type', 'application/json');
    }

    private function error(int $code, string $message): HTTPResponse
    {
        return HTTPResponse::create(json_encode(['error' => $message]), $code)
            ->addHeader('Content-Type', 'application/json');
    }
}
