/**
 * Puts a "Lock as Draft" switch in the header of each expanded draft block, which saves the moment
 * it is thrown, and keeps the publish notices and the "Publish page and locked blocks" action in
 * step with the block list.
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
 *   .element-editor__element--expandable             the block opens inline, rather than in its
 *                                                    own edit form
 *   .element-editor-editform(--collapsed)            an expanded block's form, and a collapsed
 *                                                    one kept off screen
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
     * Every block's lock as the server holds it, by block ID. Seeded from each notices container
     * the server renders, which happens again whenever the CMS reloads the page form, and kept
     * current by the switch's own saves and by re-reading a block when something else may have
     * changed it.
     */
    var locks = {};
    var requested = {};

    function settings() {
        var notices = document.querySelector('.elemental-draft-lock-notices');

        return notices ? notices.dataset : null;
    }

    function seed(notices) {
        if (notices.draftLockSeeded) {
            return;
        }

        notices.draftLockSeeded = true;

        var locked = (notices.getAttribute('data-locked-ids') || '').split(',');
        var types = {};

        try {
            types = JSON.parse(notices.getAttribute('data-block-types') || '{}');
        } catch (e) {
            types = {};
        }

        Object.keys(types).forEach(function (id) {
            locks[id] = locked.indexOf(id) !== -1;
            delete requested[id];
        });
    }

    /** Asks the server for a block's lock, at most once until something says to ask again. */
    function fetchLock(id) {
        var data = settings();

        if (requested[id] || !data || !data.stateUrl || !window.fetch) {
            return;
        }

        requested[id] = true;

        window.fetch(data.stateUrl + '?ID=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (result) {
                if (result) {
                    locks[id] = Boolean(result.Locked);
                    schedule();
                }
            })
            .catch(function () {});
    }

    /**
     * A block added since the page was loaded is not in the seed. Until the server answers, it is
     * assumed to start the way new blocks do on this site.
     */
    function isLocked(block) {
        var id = blockID(block);

        if (!id) {
            return false;
        }

        if (!Object.prototype.hasOwnProperty.call(locks, id)) {
            fetchLock(id);
            var data = settings();

            return Boolean(data && data.lockNewBlocks === '1');
        }

        return locks[id];
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
            var isHeld = blockIsDraftOnly(block) && isLocked(block);

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
    var saving = {};

    /**
     * Whether the block is open inline. A block that is not edited inline never opens here; its
     * switch is in the action bar of its own edit screen instead.
     */
    function isOpen(block) {
        if (!block.classList.contains('element-editor__element--expandable')) {
            return false;
        }

        var form = block.querySelector('.element-editor-editform');

        return Boolean(form && !form.classList.contains('element-editor-editform--collapsed'));
    }

    function setChecked(toggle, checked) {
        var value = checked ? 'true' : 'false';

        if (toggle.getAttribute('aria-checked') !== value) {
            toggle.setAttribute('aria-checked', value);
        }
    }

    function showError(message) {
        if (window.jQuery && window.jQuery.noticeAdd) {
            window.jQuery.noticeAdd({ text: message, type: 'error', stayTime: 5000 });
        } else {
            window.alert(message);
        }
    }

    /** The CMS form's security token, which the save needs to be accepted */
    function securityToken() {
        var input = document.querySelector('form.cms-edit-form input[name="SecurityID"]')
            || document.querySelector('input[name="SecurityID"]');

        return input ? input.value : '';
    }

    /**
     * Saves a block's lock straight away. The switch moves first, and moves back if the save
     * fails, so it never sits saying something the server does not. `done` is given the lock
     * the server ended up with.
     */
    function postLock(id, locked, url, failedMessage, toggle, done) {
        if (saving[id]) {
            return;
        }

        var body = new window.URLSearchParams();

        body.append('ID', id);
        body.append('Locked', locked ? '1' : '0');
        body.append('SecurityID', securityToken());

        saving[id] = true;
        setChecked(toggle, locked);
        toggle.setAttribute('aria-busy', 'true');

        window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json();
            })
            .then(function (result) {
                return Boolean(result.Locked);
            })
            .catch(function () {
                showError(failedMessage);

                return !locked;
            })
            .then(function (result) {
                delete saving[id];
                toggle.removeAttribute('aria-busy');
                setChecked(toggle, result);
                done(result);
            });
    }

    /** The switch in a block header */
    function save(block, toggle) {
        var data = settings();
        var id = blockID(block);

        if (!data || !id || saving[id]) {
            return;
        }

        var locked = !isLocked(block);

        locks[id] = locked;
        schedule();

        postLock(id, locked, data.toggleUrl, data.toggleFailed, toggle, function (result) {
            locks[id] = result;
            schedule();
        });
    }

    /* The switch in the action bar of a block's own edit screen, which the server draws already
       set. That screen is reloaded after anything that would change it, so it keeps no state. */
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest && event.target.closest('.elemental-draft-lock-form-toggle');

        if (!toggle) {
            return;
        }

        event.preventDefault();

        postLock(
            toggle.getAttribute('data-id'),
            toggle.getAttribute('aria-checked') !== 'true',
            toggle.getAttribute('data-toggle-url'),
            toggle.getAttribute('data-toggle-failed'),
            toggle,
            function () {}
        );
    });

    /**
     * Builds the header switch for a block. The header is drawn by Elemental's React code, which
     * has no slot for it, so this is added beside its own nodes rather than through it.
     */
    function buildHeaderToggle() {
        var data = settings() || {};
        var toggle = document.createElement('button');
        var track = document.createElement('span');
        var text = document.createElement('span');

        toggle.type = 'button';
        toggle.className = HEADER_TOGGLE;
        toggle.setAttribute('role', 'switch');
        toggle.setAttribute('aria-checked', 'false');

        if (data.toggleTitle) {
            toggle.title = data.toggleTitle;
        }

        track.className = HEADER_TOGGLE + '__track';
        track.setAttribute('aria-hidden', 'true');

        text.className = HEADER_TOGGLE + '__label';
        text.textContent = data.toggleLabel || 'Lock as Draft';

        toggle.appendChild(track);
        toggle.appendChild(text);

        /* The whole block is Elemental's click and key target for expanding and collapsing it, so
           neither may reach it from here. Stopping the native event also keeps it from React,
           which listens further up the document. */
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var block = toggle.closest(BLOCK);

            if (block) {
                save(block, toggle);
            }
        });

        toggle.addEventListener('keyup', function (event) {
            event.stopPropagation();
        });

        return toggle;
    }

    /** Adds, updates or removes the header switch on every block, only where it would change. */
    function syncHeaderToggles() {
        var data = settings();
        var lockOnUnpublish = Boolean(data && data.lockOnUnpublish === '1');

        Array.prototype.forEach.call(document.querySelectorAll(BLOCK), function (block) {
            var draftOnly = blockIsDraftOnly(block);
            var id = blockID(block);

            /* With lock_on_unpublish the server locks a block unpublished from its own menu, which
               Elemental does through GraphQL without telling the page. A block seen live and now
               draft only has just been unpublished, so its lock is read again. */
            if (lockOnUnpublish && id && block.draftLockWasDraftOnly === false && draftOnly) {
                locks[id] = true;
                delete requested[id];
                fetchLock(id);
            }

            block.draftLockWasDraftOnly = draftOnly;

            var actions = block.querySelector('.element-editor-header__actions');
            var toggle = actions && actions.querySelector('.' + HEADER_TOGGLE);
            var wanted = Boolean(data && actions && id && draftOnly && isOpen(block));

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
                toggle = buildHeaderToggle();
                actions.insertBefore(toggle, actions.firstChild);
            }

            if (!block.classList.contains(HAS_HEADER_TOGGLE)) {
                block.classList.add(HAS_HEADER_TOGGLE);
            }

            setChecked(toggle, isLocked(block));
        });
    }

    var queued = false;

    function renderAll() {
        queued = false;

        Array.prototype.forEach.call(document.querySelectorAll('.elemental-draft-lock-notices'), seed);
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

    schedule();
}());
