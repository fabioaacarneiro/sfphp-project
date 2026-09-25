/**
 * SFJS UI — modals, offcanvas, dropdowns, tooltips, tabs, toasts and dismiss
 *
 * Built on what the browser already does: <dialog> for modals and offcanvas,
 * the popover attribute for menus and tooltips, the hidden attribute for tab
 * panels. The browser supplies the focus trap, the top layer, Escape and light
 * dismiss; this adds the keyboard and ARIA work the elements leave to the page,
 * and a fallback where CSS anchor positioning is not there yet.
 *
 * Part of the SFJS bundle, after core.js and stream.js. The look belongs to
 * SFCSS, which styles the class names used here; nothing in this file writes
 * CSS except the fixed coordinates of a floating element.
 */

(() => {
  const { emit } = sf;

  // Everything is delegated from the document, so markup added later works.
  const on = (type, listener, capture = false) => document.addEventListener(type, listener, capture);
  const up = (e, selector) => (e.target.closest ? e.target.closest(selector) : null);

  // ========== POSITIONING ==========

  const GAP = 4;
  const OPPOSITE = { top: 'bottom', bottom: 'top', left: 'right', right: 'left' };

  /*
   * With CSS anchor positioning the stylesheet places a menu under its button
   * and flips it when there is no room, with no script running on scroll.
   * Where it is missing, the same thing is done here with fixed coordinates.
   */
  const anchored = typeof CSS !== 'undefined' && CSS.supports && CSS.supports('anchor-name: --a');

  /** Floating elements open right now, re-placed on scroll and resize. */
  const placed = new Map();

  /**
   * Put a floating element beside its anchor, on the side asked for — or on
   * the opposite one when the asked-for side has no room and the other has
   * more — and keep it inside the viewport.
   *
   * @param {HTMLElement} floating The element to place
   * @param {Element} anchor What it belongs to
   * @param {string} side top, bottom, left or right
   * @param {boolean} centred Centre it on the anchor rather than align starts
   * @returns {string} The side it ended up on
   */
  function place(floating, anchor, side, centred) {
    const box = anchor.getBoundingClientRect();
    const width = floating.offsetWidth;
    const height = floating.offsetHeight;
    const viewWidth = document.documentElement.clientWidth;
    const viewHeight = document.documentElement.clientHeight;
    const room = { top: box.top, bottom: viewHeight - box.bottom, left: box.left, right: viewWidth - box.right };
    const vertical = side === 'top' || side === 'bottom';
    const needed = (vertical ? height : width) + GAP;

    if (room[side] < needed && room[OPPOSITE[side]] > room[side]) side = OPPOSITE[side];

    let x;
    let y;

    if (vertical) {
      y = side === 'top' ? box.top - height - GAP : box.bottom + GAP;

      // A menu opens from the start of its button, and the start is the right
      // in a right-to-left page.
      if (centred) x = box.left + (box.width - width) / 2;
      else x = getComputedStyle(anchor).direction === 'rtl' ? box.right - width : box.left;
    } else {
      x = side === 'left' ? box.left - width - GAP : box.right + GAP;
      y = box.top + (box.height - height) / 2;
    }

    x = Math.max(GAP, Math.min(x, viewWidth - width - GAP));
    y = Math.max(GAP, Math.min(y, viewHeight - height - GAP));

    Object.assign(floating.style, { position: 'fixed', inset: 'auto', margin: '0', left: x + 'px', top: y + 'px' });

    return side;
  }

  const replace = () => placed.forEach((again) => again());

  window.addEventListener('resize', replace);
  window.addEventListener('scroll', replace, { capture: true, passive: true });

  // ========== MODAL AND OFFCANVAS ==========

  /** Who opened each dialog, so the focus can go back there. */
  const openers = new WeakMap();

  /**
   * Open a dialog as a modal: the browser traps the focus inside it, puts it
   * above everything and makes the rest of the page inert.
   *
   * @param {HTMLDialogElement} dialog The dialog
   * @param {?Element} opener The element that asked
   * @returns {void}
   */
  function openDialog(dialog, opener = null) {
    if (dialog.open || !emit(dialog, 'sf:show', { relatedTarget: opener }, true)) return;

    openers.set(dialog, opener || document.activeElement);
    dialog.showModal();
    emit(dialog, 'sf:shown', { relatedTarget: opener });
  }

  /**
   * Close a dialog, unless a listener of sf:hide says not to.
   *
   * @param {HTMLDialogElement} dialog The dialog
   * @returns {void}
   */
  function closeDialog(dialog) {
    if (!dialog.open || !emit(dialog, 'sf:hide', {}, true)) return;

    dialog.close();
  }

  /*
   * Escape reaches a dialog as "cancel". It goes through sf:hide like every
   * other way of closing, so a form with unsaved changes can say no. close and
   * cancel do not bubble, so they are heard in the capture phase.
   */
  on('cancel', (e) => {
    if (e.target.tagName === 'DIALOG' && !emit(e.target, 'sf:hide', {}, true)) e.preventDefault();
  }, true);

  on('close', (e) => {
    const dialog = e.target;

    if (dialog.tagName !== 'DIALOG') return;

    // Back to where the visitor was, or a keyboard user starts over at the top.
    const opener = openers.get(dialog);

    if (opener && opener.isConnected && opener.focus) opener.focus();

    openers.delete(dialog);
    emit(dialog, 'sf:hidden');
  }, true);

  /*
   * A click on the backdrop reaches the dialog itself, and so does a click on
   * the dialog's own padding — the difference is whether it landed outside the
   * box. The press is checked too, so selecting text inside and releasing the
   * mouse outside does not close anything.
   */
  let pressed = null;

  on('pointerdown', (e) => { pressed = e.target; }, true);

  on('click', (e) => {
    const opener = up(e, '[\\@modal]');

    if (opener) {
      const dialog = document.querySelector(opener.getAttribute('@modal'));

      if (dialog && dialog.showModal) {
        e.preventDefault();
        openDialog(dialog, opener);
      }

      return;
    }

    const dialog = e.target;

    if (dialog.tagName !== 'DIALOG' || !dialog.open || pressed !== dialog || dialog.hasAttribute('data-static')) return;

    const box = dialog.getBoundingClientRect();
    const outside = e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom;

    if (outside) closeDialog(dialog);
  });

  // ========== DISMISS ==========

  /**
   * Take an alert or a toast away, saying so first.
   *
   * @param {Element} box The alert or toast
   * @returns {void}
   */
  function dismiss(box) {
    clearTimeout(box.__sfTimer);
    emit(box, 'sf:dismissed');
    box.remove();
  }

  on('click', (e) => {
    const button = up(e, '[\\@dismiss]');
    const box = button && button.closest('.alert, .toast, dialog');

    if (!box) return;

    if (box.tagName === 'DIALOG') closeDialog(box);
    else dismiss(box);
  });

  // ========== DROPDOWN ==========

  /**
   * The buttons that open a popover.
   *
   * @param {Element} menu The popover
   * @returns {Element[]}
   */
  function invokersOf(menu) {
    return menu.id ? Array.from(document.querySelectorAll('[popovertarget="' + CSS.escape(menu.id) + '"]')) : [];
  }

  /**
   * The items of a menu that can take the focus.
   *
   * @param {Element} menu The menu
   * @returns {HTMLElement[]}
   */
  function itemsOf(menu) {
    return Array.from(menu.querySelectorAll('.dropdown-item, [role="menuitem"]'))
      .filter((item) => !item.disabled && item.getAttribute('aria-disabled') !== 'true' && !item.hidden);
  }

  let anchors = 0;

  /*
   * Placed on beforetoggle, which fires before the menu is shown: an anchor
   * name set now is in force for the first frame, and a fallback position is
   * computed in the next animation frame — after the menu has a size to
   * measure, before it is painted — so it never flashes in the wrong place.
   */
  on('beforetoggle', (e) => {
    const menu = e.target;

    /*
     * A .popover is placed the same way as a menu. It used to be left out,
     * so without CSS anchor positioning — Firefox, Safari — it opened at the
     * top left of the window, far from the button that opened it.
     */
    if (!menu.classList || !(menu.classList.contains('dropdown-menu') || menu.classList.contains('popover'))) return;

    const invoker = invokersOf(menu)[0];

    if (e.newState !== 'open') {
      placed.delete(menu);

      return;
    }

    if (!invoker) return;

    if (anchored) {
      if (!invoker.style.getPropertyValue('anchor-name')) {
        const name = '--sf-anchor-' + (++anchors);

        invoker.style.setProperty('anchor-name', name);
        menu.style.setProperty('--sf-anchor', name);
      }

      return;
    }

    const again = () => place(menu, invoker, 'bottom', false);

    placed.set(menu, again);
    requestAnimationFrame(again);
  }, true);

  /*
   * The popover's toggle event is the one place that hears every way a menu
   * opened or closed — its button, Escape, a click outside, a script — so the
   * ARIA state is kept in step from here rather than from each of them.
   */
  on('toggle', (e) => {
    const menu = e.target;

    if (!menu.classList || !menu.classList.contains('dropdown-menu')) return;

    const open = e.newState === 'open';
    const invoker = invokersOf(menu)[0];

    invokersOf(menu).forEach((one) => one.setAttribute('aria-expanded', String(open)));

    if (open) {
      const first = itemsOf(menu)[0];

      if (first) first.focus();

      return;
    }

    // Only when the focus was inside, or nowhere; a click elsewhere keeps it.
    if (invoker && (menu.contains(document.activeElement) || document.activeElement === document.body)) invoker.focus();
  }, true);

  // Choosing an item is the end of the menu's job.
  on('click', (e) => {
    const item = up(e, '.dropdown-menu .dropdown-item');
    const menu = item && item.closest('.dropdown-menu');

    if (menu && menu.matches(':popover-open') && !menu.hasAttribute('data-keep-open')) menu.hidePopover();
  });

  on('keydown', (e) => {
    const menu = up(e, '.dropdown-menu');

    // Down on a closed menu's button opens it, as a native select would.
    if (!menu) {
      const target = e.target.getAttribute && e.target.getAttribute('popovertarget');
      const popover = target && document.getElementById(target);

      if (popover && popover.classList.contains('dropdown-menu') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        e.preventDefault();
        popover.showPopover();
      }

      return;
    }

    const items = itemsOf(menu);
    const at = items.indexOf(document.activeElement);
    const next = {
      ArrowDown: (at + 1) % items.length,
      ArrowUp: (at - 1 + items.length) % items.length,
      Home: 0,
      End: items.length - 1,
    }[e.key];

    if (next === undefined || items.length === 0) return;

    e.preventDefault();
    items[next].focus();
  });

  /*
   * Tab out of an open menu closes it. Light dismiss only listens for clicks
   * and Escape, so without this the menu stayed open behind the focus.
   */
  on('focusout', (e) => {
    const menu = up(e, '.dropdown-menu');
    const to = e.relatedTarget;

    if (!menu || !menu.matches(':popover-open') || !to || menu.contains(to) || invokersOf(menu).includes(to)) return;

    menu.hidePopover();
  });

  // ========== TOOLTIP ==========

  let tip = null;
  let owner = null;
  let waiting = null;

  /**
   * Show the shared tooltip for an element.
   *
   * One element serves every tooltip on the page, because only one can be
   * shown at a time; popover="manual" puts it in the top layer, above any
   * overflow: hidden, without light dismiss closing it on the first click.
   *
   * @param {Element} element The element carrying @tooltip
   * @returns {void}
   */
  function showTip(element) {
    clearTimeout(waiting);

    if (owner === element) return;
    if (owner) hideTip();

    if (!tip) {
      tip = document.createElement('div');
      tip.setAttribute('role', 'tooltip');
      tip.setAttribute('popover', 'manual');
      tip.id = sf.util.id(tip, 'sf-tooltip');
      document.body.appendChild(tip);
    }

    const side = element.getAttribute('@tooltip-placement') || 'top';

    owner = element;
    tip.textContent = element.getAttribute('@tooltip');
    tip.className = 'tooltip';
    tip.showPopover();

    const again = () => {
      tip.className = 'tooltip tooltip-' + place(tip, element, OPPOSITE[side] ? side : 'top', true);
    };

    placed.set(tip, again);
    again();

    const ids = (element.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);

    element.setAttribute('aria-describedby', ids.concat(tip.id).join(' '));
  }

  /**
   * Hide the tooltip, and take its description off the element it described.
   *
   * @returns {void}
   */
  function hideTip() {
    clearTimeout(waiting);

    if (!owner) return;

    const ids = (owner.getAttribute('aria-describedby') || '').split(' ').filter((id) => id && id !== tip.id);

    if (ids.length) owner.setAttribute('aria-describedby', ids.join(' '));
    else owner.removeAttribute('aria-describedby');

    placed.delete(tip);
    tip.hidePopover();
    owner = null;
  }

  // A pending hide, cancelled when the pointer reaches the tooltip or its element.
  let leaving = null;

  // A pointer lingers for a moment before it asks; the keyboard asks at once.
  on('mouseover', (e) => {
    const element = up(e, '[\\@tooltip]');

    if ((element && element === owner) || (tip && tip.contains(e.target))) {
      clearTimeout(leaving);

      return;
    }

    if (element) {
      clearTimeout(waiting);
      waiting = setTimeout(() => showTip(element), 300);
    }
  });

  /*
   * Leaving the element for the tooltip itself keeps it open, so it can be
   * read and selected — WCAG asks for content shown on hover to be hoverable.
   * The hide waits a moment: the tooltip sits a few pixels away, and the
   * pointer crossing that gap used to count as leaving. The stylesheet no
   * longer sets pointer-events: none on it, which made it impossible to
   * reach at all.
   */
  on('mouseout', (e) => {
    const from = up(e, '[\\@tooltip], .tooltip');
    const to = e.relatedTarget;

    if (!from) return;
    if (to && ((owner && owner.contains(to)) || (tip && tip.contains(to)))) return;

    clearTimeout(leaving);
    leaving = setTimeout(hideTip, 150);
  });

  on('focusin', (e) => {
    const element = up(e, '[\\@tooltip]');

    if (element) showTip(element);
  });

  on('focusout', (e) => {
    if (owner && owner.contains(e.target)) hideTip();
  });

  on('keydown', (e) => {
    if (e.key === 'Escape' && owner) hideTip();
  });

  // ========== TABS ==========

  /**
   * The tabs that belong to a tab list, and not to a list nested inside it.
   *
   * @param {Element} list The element carrying @tabs
   * @returns {HTMLElement[]}
   */
  function tabsOf(list) {
    return Array.from(list.querySelectorAll('[role="tab"]')).filter((tab) => tab.closest('[\\@tabs]') === list);
  }

  /**
   * Select a tab: its panel shows, the others hide, and only the selected tab
   * is in the Tab order — the arrows move between the rest, which is what
   * "roving tabindex" means and what the ARIA tabs pattern asks for.
   *
   * @param {HTMLElement} chosen The tab
   * @param {boolean} announce Whether to fire sf:tab
   * @returns {void}
   */
  function selectTab(chosen, announce) {
    const list = chosen.closest('[\\@tabs]');
    let panel = null;

    tabsOf(list).forEach((tab) => {
      const selected = tab === chosen;
      const own = document.getElementById(tab.getAttribute('aria-controls'));

      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;

      if (!own) return;

      own.hidden = !selected;
      own.setAttribute('role', 'tabpanel');
      own.setAttribute('aria-labelledby', sf.util.id(tab, 'sf-tab'));

      // A panel with nothing focusable in it has to be reachable itself.
      if (!own.hasAttribute('tabindex')) own.tabIndex = 0;

      if (selected) panel = own;
    });

    if (announce) emit(list, 'sf:tab', { tab: chosen, panel });
  }

  on('click', (e) => {
    const tab = up(e, '[\\@tabs] [role="tab"]');

    if (!tab || tab.disabled || tab.getAttribute('aria-disabled') === 'true') return;

    e.preventDefault();
    selectTab(tab, true);
  });

  on('keydown', (e) => {
    const tab = up(e, '[\\@tabs] [role="tab"]');

    if (!tab) return;

    const list = tab.closest('[\\@tabs]');
    const tabs = tabsOf(list).filter((one) => !one.disabled && one.getAttribute('aria-disabled') !== 'true');
    const at = tabs.indexOf(tab);
    const vertical = list.getAttribute('aria-orientation') === 'vertical';

    // In a right-to-left page the tab to the right is the previous one.
    const forward = getComputedStyle(list).direction === 'rtl' ? -1 : 1;
    const step = vertical
      ? { ArrowDown: 1, ArrowUp: -1 }[e.key]
      : { ArrowRight: forward, ArrowLeft: -forward }[e.key];

    let next = null;

    if (step) next = tabs[(at + step + tabs.length) % tabs.length];
    else if (e.key === 'Home') next = tabs[0];
    else if (e.key === 'End') next = tabs[tabs.length - 1];

    if (!next) return;

    e.preventDefault();
    next.focus();
    selectTab(next, true);
  });

  // ========== TOAST ==========

  let stack = null;

  /**
   * The live region toasts are put in. It is made as soon as the page is
   * ready rather than with the first toast, because a screen reader only
   * announces changes to a live region it already knew about — a region
   * created together with its first message is often read as nothing.
   *
   * @returns {HTMLElement}
   */
  function stackOf() {
    if (!stack || !stack.isConnected) {
      stack = document.createElement('div');
      stack.className = 'toast-stack';
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }

    return stack;
  }

  sf.dom.ready(stackOf);

  /**
   * Show a short message that goes away by itself.
   *
   * The stack is a polite live region, so a screen reader reads a toast when
   * it arrives without interrupting; a danger toast is role="alert", which
   * does interrupt, because an error the visitor never heard about is worse
   * than an interruption. The timer pauses while the pointer or the focus is
   * on the toast — nobody should lose a message while they are reading it.
   *
   * @param {string|Node} message What to say; a string is text, never markup
   * @param {Object} options variant, timeout (ms, 0 to stay) and dismissible
   * @returns {HTMLElement} The toast
   */
  function toast(message, options = {}) {
    const { variant = 'info', timeout = 5000, dismissible = true } = options;

    const box = document.createElement('div');
    const body = document.createElement('div');

    box.className = 'toast toast-' + variant;
    box.setAttribute('role', variant === 'danger' ? 'alert' : 'status');
    box.setAttribute('aria-atomic', 'true');
    if (variant === 'danger') box.setAttribute('aria-live', 'assertive');

    body.className = 'toast-body';
    body.append(message);
    box.appendChild(body);

    if (dismissible) {
      // Parsed rather than built with setAttribute, which refuses a name with @.
      const holder = document.createElement('div');

      holder.innerHTML = '<button type="button" class="btn-close" @dismiss></button>';
      holder.firstChild.setAttribute('aria-label', sf.t('close'));
      box.appendChild(holder.firstChild);
    }

    const start = () => {
      clearTimeout(box.__sfTimer);
      if (timeout > 0) box.__sfTimer = setTimeout(() => dismiss(box), timeout);
    };
    const pause = () => clearTimeout(box.__sfTimer);

    box.addEventListener('mouseenter', pause);
    box.addEventListener('focusin', pause);
    box.addEventListener('mouseleave', () => { if (!box.contains(document.activeElement)) start(); });
    box.addEventListener('focusout', (e) => { if (!box.contains(e.relatedTarget)) start(); });

    stackOf().appendChild(box);
    start();

    return box;
  }

  /*
   * The server can raise a toast by answering with an SF-Toast header: JSON
   * such as {"message": "Saved", "variant": "success"}, or plain text. Header
   * values are Latin-1, so text in any other script travels as JSON with
   * \u escapes — which is what PHP's json_encode writes by default.
   */
  const fromServer = (e) => {
    const response = e.detail && e.detail.response;
    const raised = response && response.headers && response.headers.get ? response.headers.get('SF-Toast') : null;

    if (!raised) return;

    let said;

    try {
      said = JSON.parse(raised);
    } catch {
      said = { message: raised };
    }

    if (typeof said === 'string') said = { message: said };
    if (said && said.message) toast(said.message, said);
  };

  on('sf:after', fromServer);
  on('sf:error', fromServer);

  // ========== BINDING ==========

  /*
   * What can be said about each widget before anybody touches it: which
   * button opens a menu or a dialog, which tab is selected. Run on the page
   * and on every fragment a swap brings in.
   */
  sf.onBind((root) => {
    const all = (selector) => {
      const found = Array.from(root.querySelectorAll(selector));

      if (root.matches && root.matches(selector)) found.push(root);

      return found;
    };

    all('[\\@modal]').forEach((opener) => {
      opener.setAttribute('aria-haspopup', 'dialog');

      const dialog = document.querySelector(opener.getAttribute('@modal'));

      if (dialog) opener.setAttribute('aria-controls', sf.util.id(dialog, 'sf-dialog'));
    });

    all('[popovertarget]').forEach((invoker) => {
      const menu = document.getElementById(invoker.getAttribute('popovertarget'));

      if (!menu || !menu.classList.contains('dropdown-menu')) return;

      invoker.setAttribute('aria-haspopup', menu.getAttribute('role') === 'menu' ? 'menu' : 'true');
      invoker.setAttribute('aria-expanded', String(menu.matches(':popover-open')));

      menu.querySelectorAll('.dropdown-item').forEach((item) => {
        if (!item.hasAttribute('role')) item.setAttribute('role', 'menuitem');
      });

      menu.querySelectorAll('.dropdown-divider').forEach((line) => {
        if (!line.hasAttribute('role')) line.setAttribute('role', 'separator');
      });
    });

    all('[\\@tabs]').forEach((list) => {
      const tabs = tabsOf(list);
      const chosen = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0];

      if (!list.hasAttribute('role')) list.setAttribute('role', 'tablist');
      if (chosen) selectTab(chosen, false);
    });
  });

  sf.toast = toast;
  sf.modal = { open: openDialog, close: closeDialog };
})();
