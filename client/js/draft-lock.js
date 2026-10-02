/**
 * Keeps the publish notices, and the "Publish page and locked blocks" action, in step with the
 * block list, and puts a "Lock as Draft" switch in the header of each expanded draft block.
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
 *   .element-editor-header__actions                  the header's ... menu and caret
 *   .element-editor-editform--collapsed              a collapsed block's form, kept off screen
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

        /* Locked by the server since this page was loaded, see matchLockOnUnpublish() */
        if (block.draftLockLockedOnUnpublish) {
            return true;
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
    function fill(panel, heading, intro, blocks, hint, untitled) {
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

        if (intro) {
            var introEl = document.createElement('span');
            introEl.className = 'elemental-draft-lock-notice__intro';
            introEl.textContent = intro;
            panel.appendChild(introEl);
        }

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

    /** Renders one notice and returns how many blocks it lists as held. */
    function render(notices) {
        var list = listFor(notices);

        if (!list) {
            return 0;
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
            return held.length;
        }

        notices.draftLockSignature = signature;

        var data = notices.dataset;

        fill(
            notices.querySelector('[data-role="held"]'),
            data.heldHeading,
            data.heldIntro,
            held,
            data.heldHint,
            data.untitled
        );
        fill(
            notices.querySelector('[data-role="pending"]'),
            data.pendingHeading,
            data.pendingIntro,
            pendingBlocks,
            data.pendingHint,
            data.untitled
        );

        return held.length;
    }

    /* Hidden rather than removed, so the CMS's own handling of the actions menu is undisturbed.
       Only touched when it would change, for the same reason as the padlock class above. */
    function toggleAction(form, show) {
        var action = form.querySelector('.elemental-draft-lock-publish-all');

        if (action && action.hidden === show) {
            action.hidden = !show;
        }
    }

    var HEADER_TOGGLE = 'elemental-draft-lock-header';
    var HAS_HEADER_TOGGLE = 'elemental-draft-lock-has-header-toggle';

    /**
     * The block's own Lock as Draft checkbox, if its form is loaded and the block is expanded.
     * Elemental keeps a collapsed block's form rendered off screen, so being in the DOM is not
     * enough.
     */
    function visibleCheckbox(block) {
        var checkbox = block.querySelector('.elemental-draft-lock input[type="checkbox"]');

        if (!checkbox || checkbox.closest('.element-editor-editform--collapsed')) {
            return null;
        }

        return checkbox;
    }

    function setChecked(toggle, checked) {
        var value = checked ? 'true' : 'false';

        if (toggle.getAttribute('aria-checked') !== value) {
            toggle.setAttribute('aria-checked', value);
        }
    }

    /**
     * Builds the header switch for a block.
     *
     * The header is drawn by Elemental's React code, which has no slot for it, so this is added
     * beside its own nodes rather than through it. It is only a stand-in: clicking it clicks the
     * real checkbox in the block's form, so React, the form's dirty state and saving all behave
     * exactly as if the editor had used the checkbox, and the checkbox remains what is submitted.
     */
    function buildHeaderToggle(field) {
        var label = field.querySelector('.form-check-label');
        var description = field.querySelector('.form__field-description');
        var toggle = document.createElement('button');
        var track = document.createElement('span');
        var text = document.createElement('span');

        toggle.type = 'button';
        toggle.className = HEADER_TOGGLE;
        toggle.setAttribute('role', 'switch');
        toggle.setAttribute('aria-checked', 'false');

        var notices = document.querySelector('.elemental-draft-lock-notices');
        var title = notices && notices.getAttribute('data-toggle-title');

        if (title || description) {
            toggle.title = title || description.textContent.trim();
        }

        track.className = HEADER_TOGGLE + '__track';
        track.setAttribute('aria-hidden', 'true');

        text.className = HEADER_TOGGLE + '__label';
        text.textContent = label ? label.textContent.trim() : 'Lock as Draft';

        toggle.appendChild(track);
        toggle.appendChild(text);

        /* The whole block is Elemental's click and key target for expanding and collapsing it, so
           neither may reach it from here. Stopping the native event also keeps it from React,
           which listens further up the document. */
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var block = toggle.closest(BLOCK);
            var checkbox = block && visibleCheckbox(block);

            if (checkbox) {
                checkbox.click();
                setChecked(toggle, checkbox.checked);
                schedule();
            }
        });

        toggle.addEventListener('keyup', function (event) {
            event.stopPropagation();
        });

        return toggle;
    }

    function locksOnUnpublish() {
        var notices = document.querySelector('.elemental-draft-lock-notices');

        return Boolean(notices && notices.getAttribute('data-lock-on-unpublish') === '1');
    }

    /**
     * With `lock_on_unpublish` the server locks a block when it is unpublished from its own menu.
     * That is a GraphQL call after which Elemental does not re-fetch the block's form, so a form
     * already loaded would go on showing the switch off, and saving it would unlock the block
     * again. This switches it on to match, through the checkbox, so the form knows it changed.
     * A block whose form is not loaded yet will fetch the locked value when it is opened.
     */
    function matchLockOnUnpublish(block) {
        var checkbox = block.querySelector('.elemental-draft-lock input[type="checkbox"]');

        block.draftLockLockedOnUnpublish = true;

        if (checkbox && !checkbox.checked) {
            checkbox.click();
        }
    }

    /** Adds, updates or removes the header switch on every block, only where it would change. */
    function syncHeaderToggles() {
        var lockOnUnpublish = locksOnUnpublish();

        Array.prototype.forEach.call(document.querySelectorAll(BLOCK), function (block) {
            var draftOnly = blockIsDraftOnly(block);

            /* Only a block seen on the live site and now off it has just been unpublished. One that
               has been draft only since the page loaded has not. */
            if (lockOnUnpublish && block.draftLockWasDraftOnly === false && draftOnly) {
                matchLockOnUnpublish(block);
            }

            block.draftLockWasDraftOnly = draftOnly;

            var actions = block.querySelector('.element-editor-header__actions');
            var toggle = actions && actions.querySelector('.' + HEADER_TOGGLE);
            var checkbox = draftOnly ? visibleCheckbox(block) : null;
            var wanted = Boolean(actions && checkbox);

            if (!wanted) {
                if (toggle) {
                    toggle.parentNode.removeChild(toggle);
                }

                if (block.classList.contains(HAS_HEADER_TOGGLE)) {
                    block.classList.remove(HAS_HEADER_TOGGLE);
                }

                return;
            }

            if (!toggle) {
                toggle = buildHeaderToggle(checkbox.closest('.elemental-draft-lock'));
                actions.insertBefore(toggle, actions.firstChild);
            }

            /* Hides the switch in the form, which stays in place as the value that is saved */
            if (!block.classList.contains(HAS_HEADER_TOGGLE)) {
                block.classList.add(HAS_HEADER_TOGGLE);
            }

            setChecked(toggle, checkbox.checked);
        });
    }

    var queued = false;

    function renderAll() {
        queued = false;

        syncHeaderToggles();

        var all = document.querySelectorAll('.elemental-draft-lock-notices');
        var forms = [];
        var heldByForm = [];

        /* A page can have more than one block list, each with its own notice, but only one
           actions menu, so the action is shown if any of them has a held block */
        Array.prototype.forEach.call(all, function (notices) {
            var form = notices.closest('form');
            var held = render(notices);
            var index = forms.indexOf(form);

            if (index === -1) {
                forms.push(form);
                heldByForm.push(held);
            } else {
                heldByForm[index] += held;
            }
        });

        forms.forEach(function (form, index) {
            if (form) {
                toggleAction(form, heldByForm[index] > 0);
            }
        });
    }

    function schedule() {
        if (queued || !document.querySelector('.element-editor')) {
            return;
        }

        queued = true;
        window.requestAnimationFrame(renderAll);
    }

    /** Ignore this script's own markup, so rendering it can never trigger another render. */
    function worthChecking(records) {
        for (var i = 0; i < records.length; i += 1) {
            var target = records[i].target;
            var element = target.nodeType === 1 ? target : target.parentElement;

            if (element && !element.closest('.elemental-draft-lock-notices, .' + HEADER_TOGGLE)) {
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
