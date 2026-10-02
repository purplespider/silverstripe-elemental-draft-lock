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

- Adds a **Lock as Draft** switch at the bottom of a block's Content tab, while the block is still
  in draft. Off, it is an unadorned row in the form; on, the row fills amber and the help text
  unfolds beneath it. Once the block is live the field disappears, since there is nothing left to
  hold.
- Blocks with the switch on are skipped when the page is published.
- The block's own three dots menu → **Publish** still publishes it. From then on the block is
  live, so it is no longer held and later edits go live with the page. The switch is left exactly
  as the editor set it, so if the block is unpublished again it is held again, as the switch shows.
- A locked block gets a padlock on its status badge in the block list, so the hold is visible
  without opening anything.
- A notice below the block list on the page tells the editor which blocks will be held back and
  which draft blocks are about to go live. Blocks that are live with nothing pending appear in
  neither list, since nothing is being held back.

Nothing is held back automatically: the editor has to turn the switch on.

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

## Known limitation

`ChangeSet::calculateImplicit()` walks `findOwned()` recursively from the page, so a file owned by a
held block (an image on the block, say) is still published by the page cascade. The block itself
stays off the site; only the asset file becomes reachable at its own URL, unlinked from anywhere.

## Requirements

- Silverstripe CMS 5
- `dnadesign/silverstripe-elemental` 5

## Licence

BSD-3-Clause
