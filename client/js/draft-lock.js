/**
 * Keeps the publish notices in step with the block list.
 *
 * Publishing or unpublishing a block from its own menu is a GraphQL mutation. Elemental refreshes
 * the block list from it, but the page edit form around it is never re-fetched, so the notices the
 * server rendered into that form would otherwise stay as they were until the page was reloaded.
 * This recomputes them from the block list, which Elemental does keep current.
 *
 * Everything it reads is rendered by Elemental and would need revisiting on a major upgrade:
 *   .element-editor                                  the block list
 *   .element-editor__element                         one block
 *   .element-editor__element--draft|--modified       the block has something unpublished
 *   .element-editor__element--draft                  ...and is not on the live site at all
 *   #element-icon-<id>                               the block's ID
 *   .element-editor-header__title                    the block's title
 */
(function () {
    var BLOCK = '.element-editor__element';
    var PENDING = /element-editor__element--(draft|modified)(\s|$)/;
    var DRAFT = /element-editor__element--draft(\s|$)/;
    var ICON_PREFIX = 'element-icon-';
    var HELD_CLASS = 'elemental-draft-lock-held';

    function blockIsPending(block) {
        return PENDING.test(block.className);
    }

    /* Only a block with no live version can be held, the same rule the server applies when the
       page is published. A published block with edits goes live with the page whatever its flag. */
    function blockIsDraftOnly(block) {
        return DRAFT.test(block.className);
    }

    function blockID(block) {
        var icon = block.querySelector('[id^="' + ICON_PREFIX + '"]');

        return icon ? icon.id.slice(ICON_PREFIX.length) : null;
    }

    /**
     * Empty for an untitled block, so it is described the same way the server describes it.
     * Elemental's own label for those is "Untitled {type} block", which would repeat the type
     * this list already shows.
     */
    function blockTitle(block) {
        var title = block.querySelector('.element-editor-header__title');

        if (!title || title.classList.contains('element-editor-header__title--none')) {
            return '';
        }

        return title.textContent.trim();
    }

    /**
     * The hold flag only ever changes when the editor saves it; nothing on the server touches it
     * behind the form's back. So the IDs the server sent stay accurate for a collapsed block, and
     * an expanded block is read from its switch instead, so throwing it updates the notices
     * straight away.
     */
    function blockIsHeld(block, heldIDs) {
        var checkbox = block.querySelector('.elemental-draft-lock input[type="checkbox"]');

        if (checkbox) {
            return checkbox.checked;
        }

        return heldIDs.indexOf(blockID(block)) !== -1;
    }

    /** The block list this notice belongs to: the last one that starts before it. */
    function listFor(notices) {
        var form = notices.closest('form') || document;
        var lists = form.querySelectorAll('.element-editor');
        var match = null;

        Array.prototype.forEach.call(lists, function (list) {
            /* eslint-disable-next-line no-bitwise */
            if (list.compareDocumentPosition(notices) & Node.DOCUMENT_POSITION_FOLLOWING) {
                match = list;
            }
        });

        return match;
    }

    /** Builds the same markup as ElementalPublishNoticeExtension::panel() on the server. */
    function fill(panel, heading, blocks, hint, untitled) {
        if (!panel) {
            return;
        }

        if (!blocks.length) {
            panel.hidden = true;
            return;
        }

        panel.hidden = false;
        panel.textContent = '';

        var strong = document.createElement('strong');
        strong.textContent = heading;
        panel.appendChild(strong);

        var list = document.createElement('ul');
        list.className = 'elemental-draft-lock-notice__list';

        blocks.forEach(function (block) {
            var item = document.createElement('li');

            if (block.title) {
                item.appendChild(document.createTextNode(block.title));
            } else {
                var em = document.createElement('em');
                em.textContent = untitled;
                item.appendChild(em);
            }

            if (block.type) {
                item.appendChild(document.createTextNode(' (' + block.type + ')'));
            }

            list.appendChild(item);
        });

        panel.appendChild(list);

        var hintEl = document.createElement('span');
        hintEl.className = 'elemental-draft-lock-notice__hint';
        /* Markup, not text, so the hint can carry the three dots icon. The string comes from the
           server's translation files, the same place the server-rendered version comes from, so
           there is no user input in it. Block titles above are still added as text nodes. */
        hintEl.innerHTML = hint;
        panel.appendChild(hintEl);
    }

    function render(notices) {
        var list = listFor(notices);

        if (!list) {
            return;
        }

        var heldIDs = (notices.getAttribute('data-held-ids') || '').split(',').filter(Boolean);
        var types = {};

        try {
            types = JSON.parse(notices.getAttribute('data-block-types') || '{}');
        } catch (e) {
            types = {};
        }

        var held = [];
        var pendingBlocks = [];

        Array.prototype.forEach.call(list.querySelectorAll(BLOCK), function (block) {
            var pending = blockIsPending(block);
            var isHeld = blockIsDraftOnly(block) && blockIsHeld(block, heldIDs);

            /* Marks the block so the stylesheet can put a padlock on its status badge. Only
               toggled when it would actually change, so this never mutates the DOM for nothing
               and sets the observer below going again. */
            if (block.classList.contains(HELD_CLASS) !== isHeld) {
                block.classList.toggle(HELD_CLASS, isHeld);
            }

            if (!pending) {
                return;
            }

            var id = blockID(block);

            /* A block added since the page form was rendered will not be in the map. Its type is
               only in the DOM as a hover tooltip, so it is listed by title alone. */
            var entry = { title: blockTitle(block), type: types[id] || '' };

            (isHeld ? held : pendingBlocks).push(entry);
        });

        /* Writing the notices is itself a DOM change, so without this the observer below would
           see its own work and loop on every frame for as long as the page was open. */
        var signature = JSON.stringify([held, pendingBlocks]);

        if (notices.draftLockSignature === signature) {
            return;
        }

        notices.draftLockSignature = signature;

        var data = notices.dataset;

        fill(notices.querySelector('[data-role="held"]'), data.heldHeading, held, data.heldHint, data.untitled);
        fill(
            notices.querySelector('[data-role="pending"]'),
            data.pendingHeading,
            pendingBlocks,
            data.pendingHint,
            data.untitled
        );
    }

    var queued = false;

    function renderAll() {
        queued = false;

        var all = document.querySelectorAll('.elemental-draft-lock-notices');

        Array.prototype.forEach.call(all, render);
    }

    function schedule() {
        if (queued || !document.querySelector('.elemental-draft-lock-notices')) {
            return;
        }

        queued = true;
        window.requestAnimationFrame(renderAll);
    }

    /** Ignore the notices' own markup, so rendering them can never trigger another render. */
    function worthChecking(records) {
        for (var i = 0; i < records.length; i += 1) {
            var target = records[i].target;
            var element = target.nodeType === 1 ? target : target.parentElement;

            if (element && !element.closest('.elemental-draft-lock-notices')) {
                return true;
            }
        }

        return false;
    }

    /* The notices and the block list are both drawn well after page load, and again whenever the
       editor moves between pages, so watch rather than run once. */
    new MutationObserver(function (records) {
        if (worthChecking(records)) {
            schedule();
        }
    }).observe(document.body, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['class']
    });

    document.addEventListener('change', function (event) {
        if (event.target.closest && event.target.closest('.elemental-draft-lock')) {
            schedule();
        }
    });

    schedule();
}());
