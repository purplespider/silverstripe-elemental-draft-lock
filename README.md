# Silverstripe Elemental Draft Lock

Lets an editor keep an individual Elemental block in draft, so that publishing the page publishes
everything else but leaves that block alone.

## The problem

Publishing a page in Silverstripe publishes every draft block on it. The cascade is not optional:
`CMSMain::save()` calls `publishRecursive()`, which builds an inferred `ChangeSet` containing the
page, and `ChangeSet::calculateImplicit()` pulls in everything `findOwned()` returns (page →
`ElementalArea` → all `Elements`).

So an editor who parks a new block in draft, comes back a day later to fix a typo in a different,
already-live block and hits Publish, sends the parked block live too. Elemental sets
`ElementalArea::$hide_in_campaigns = true`, so the campaign/changeset UI cannot be used to hold one
block back either.

## What this module does

- Adds a **Lock as Draft** switch to a block's header, beside its three dots menu, while the block
  is expanded and still in draft. On, it fills amber. Once the block is live the switch
  disappears, since there is nothing left to hold.

  Elemental draws the header with React and has no slot for it, so the header switch is a stand-in
  added by script: clicking it clicks a real checkbox at the bottom of the block's Content tab,
  which is hidden while the header switch is there and remains the value that is saved. If the
  script cannot place it, that checkbox is shown in the form instead, with its help text.
- Blocks with the switch on are skipped when the page is published.
- The block's own three dots menu → **Publish** still publishes it. From then on the block is
  live, so it is no longer held and later edits go live with the page. The switch is left exactly
  as the editor set it, so if the block is unpublished again it is held again, as the switch shows.
- A locked block gets a padlock on its status badge in the block list, so the hold is visible
  without opening anything.
- A notice below the block list on the page tells the editor which blocks will be held back and
  which draft blocks are about to go live. Blocks that are live with nothing pending appear in
  neither list, since nothing is being held back.
- **Publish page and locked blocks**, in the page's more options menu, publishes the page with
  every locked block included. It only appears while the page has a locked block waiting. Like
  publishing a block from its own menu, it leaves the switches as they are.

By default nothing is held back automatically: the editor has to turn the switch on. Set
`lock_new_blocks` (below) to have new blocks start locked instead.

## How the hold works

`ChangeSetItem::getChangeType()` ends in an `updateChangeType` extension point, and
`ChangeSetItem::publish()` does nothing when the type is `CHANGE_NONE`. Each item also records how it
got into the changeset: `EXPLICITLY` (the thing the editor actually published) or `IMPLICITLY`
(dragged in by ownership).

That gives the rule: **a flagged block with no live version is skipped whenever it is an implicit
item, and published normally when it is the explicit item.** "No live version" is what
`CHANGE_CREATED` means, so only that change type is ever suppressed. A modified block, one that is
already live and has draft edits, goes live with the page whatever its flag says.

Deletes are untouched, so archiving a held block still removes it from the live site.

### Why the flag is never changed on publish

An earlier version cleared the flag when a block was published from its own menu. That call is a
GraphQL mutation, and Elemental never re-fetches the inline block form afterwards, so the browser
went on showing the switch as on. Unpublishing the block then showed a locked switch over a database
that said otherwise, and because the form's value had not changed from what it loaded with, Elemental
did not resend it. The next page publish sent the block live.

So the flag only changes when the editor saves it, and "a published block is no longer held" is
expressed by the change-type rule above instead. What the switch shows is always what happens.

The one exception is `lock_on_unpublish` (below), which locks a block on the server when it is
unpublished. The script switches on the checkbox in any block form already loaded to match, so the
form and the database still agree and a later save does not unlock it again.

## Styling

The CMS stylesheet is inlined via `Requirements::customCSS()` from a `LeftAndMain` extension rather
than exposed as a public asset. It is about a kilobyte, and inlining means there is no `expose` step
and the module behaves the same whether it is installed into `vendor/` or wired up as a local path
repository, where the exposed URL and the one the module manifest reports do not agree.

## Installation

```bash
composer require purplespider/silverstripe-elemental-draft-lock
```

Then run `dev/build?flush=1` to add the `DraftLocked` column to `BaseElement` and its `_Live` and
`_Versions` tables.

## Configuration

The behaviour can be switched off per site. The database column stays, but the checkbox is hidden
and the flag is ignored when publishing:

```yaml
PurpleSpider\ElementalDraftLock\DraftLock:
  enabled: false
```

To have every new block start with **Lock as Draft** switched on, so nothing new goes live with the
page until the editor says it is ready:

```yaml
PurpleSpider\ElementalDraftLock\DraftLock:
  lock_new_blocks: true
```

Only blocks created after it is turned on are affected; existing blocks keep whatever they had.
Editors then publish blocks one at a time from their own menus, or all at once with **Publish page
and locked blocks**.

To lock a block whenever it is unpublished on its own, so the next page publish does not quietly put
it back on the live site:

```yaml
PurpleSpider\ElementalDraftLock\DraftLock:
  lock_on_unpublish: true
```

Unpublishing a page does not unpublish its blocks, so this only affects blocks unpublished from their
own menu. Archiving a block is left alone.

## Known limitation

`ChangeSet::calculateImplicit()` walks `findOwned()` recursively from the page, so a file owned by a
held block (an image on the block, say) is still published by the page cascade. The block itself
stays off the site; only the asset file becomes reachable at its own URL, unlinked from anywhere.

## Requirements

- Silverstripe CMS 5
- `dnadesign/silverstripe-elemental` 5

## Licence

BSD-3-Clause
