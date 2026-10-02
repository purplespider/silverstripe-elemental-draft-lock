# Silverstripe Elemental Draft Lock

Stops a page publish from accidentally publishing blocks that were meant to stay in draft.

## The problem

In Elemental, publishing a page publishes every draft block on it.

Say a page has a couple of blocks deliberately left unpublished: a new section still being written,
or one taken down for now. Later someone fixes a typo elsewhere on the page and clicks **Publish**
without thinking. Every one of those draft blocks goes live, and there is no record of which blocks
were in draft beforehand, so no easy way to put things back.

## The fix

Switch on **Lock as Draft** for a block, and publishing the page leaves it in draft.

- The switch sits in the block's header, beside its `...` menu, while the block is expanded and
  still in draft.
- Locked blocks show a padlock on their status badge in the block list.
- A notice below the block list shows which blocks are locked, and which draft blocks will go live
  with the page.
- To publish a locked block, use **Publish** in its own `...` menu, or publish them all at once with
  **Publish page and locked blocks** in the page's `...` menu.

Once a block is live it is no longer held, so later edits go live with the page as normal.

## Installation

```bash
composer require purplespider/silverstripe-elemental-draft-lock
```

Then run `dev/build?flush=1`.

## Configuration

All optional, and all off by default apart from `enabled`:

```yaml
PurpleSpider\ElementalDraftLock\DraftLock:
  # Switch the module off for a site without removing it
  enabled: true
  # New blocks start locked, so nothing new goes live until it is unlocked or published on its own
  lock_new_blocks: false
  # Lock a block when it is unpublished from its own menu, so the next page publish keeps it off
  lock_on_unpublish: false
```

## Known limitation

Files used by a locked block, such as an image, are still published with the page. The block stays
off the site, but the file itself is reachable at its own URL.

## Requirements

- Silverstripe CMS 5
- `dnadesign/silverstripe-elemental` 5

## Licence

BSD-3-Clause
