/**
 * SFJS — Simple Framework JavaScript Library
 * HTMX-like AJAX, forms, and DOM utilities without dependencies
 * ~12KB minified, 3,6KB gzipped
 */

const sf = (() => {
  /*
   * The attributes are @get, @post, @put, @patch and @delete, with @target,
   * @swap and @trigger beside them. The older @hxGet spellings still work and
   * are read as the same thing: they shipped, so removing them would break
   * pages that are already written. They are deprecated and go in a later
   * release.
   */
  const VERBS = ['get', 'post', 'put', 'patch', 'delete'];

  const DEFAULTS = {
    swapStrategy: 'innerHTML',
    validateOn: 'blur',
    debounceDelay: 300,
  };

  /**
   * The request an element declares, whichever spelling it used.
   *
   * @param {Element} element The element
   * @returns {{method: string, url: string, target: ?string, swap: string}|null}
   */
  function declaration(element) {
    if (!element || !element.attributes) return null;

    for (const attribute of Array.from(element.attributes)) {
      const name = attribute.name.toLowerCase();
      const verb = VERBS.find((one) => name === '@' + one || name === '@hx' + one);

      if (!verb || !attribute.value) continue;

      return {
        method: verb.toUpperCase(),
        url: attribute.value,
        target: attributeOf(element, 'target'),
        swap: attributeOf(element, 'swap') || DEFAULTS.swapStrategy,
      };
    }

    return null;
  }

  /**
   * Read one of the companion attributes in either spelling.
   *
   * @param {Element} element The element
   * @param {string} name Without the @, in lower case
   * @returns {?string}
   */
  function attributeOf(element, name) {
    return element.getAttribute('@' + name) || element.getAttribute('@hx' + name);
  }

  // ========== AJAX ==========

  const ajax = {
    get: (url, options = {}) => request('GET', url, options),
    post: (url, data = {}, options = {}) => request('POST', url, { ...options, data }),
    put: (url, data = {}, options = {}) => request('PUT', url, { ...options, data }),
    delete: (url, options = {}) => request('DELETE', url, options),
    patch: (url, data = {}, options = {}) => request('PATCH', url, { ...options, data }),
  };

  function request(method, url, options = {}) {
    const { data = {}, target = null, swap = 'innerHTML', onSuccess = null, onError = null } = options;

    return fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: method !== 'GET' && method !== 'DELETE' ? JSON.stringify(data) : undefined,
    })
      .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.text();
      })
      .then(html => {
        if (target) performSwap(target, html, swap);
        if (onSuccess) onSuccess(html);
      })
      .catch(error => {
        console.error('SFJS Ajax Error:', error);
        if (onError) onError(error);
      });
  }

  function withQuery(url, data) {
    const entries = Object.entries(data || {}).filter(([, value]) => value !== undefined && value !== null);

    if (entries.length === 0) return url;

    const query = new URLSearchParams();

    entries.forEach(([key, value]) => {
      if (Array.isArray(value)) {
        value.forEach((one) => query.append(key, one));
        return;
      }

      query.append(key, value);
    });

    return url + (url.includes('?') ? '&' : '?') + query.toString();
  }

  function performSwap(targetSelector, content, strategy) {
    const target = typeof targetSelector === 'string'
      ? document.querySelector(targetSelector)
      : targetSelector;

    if (!target) {
      console.warn('SFJS: Target element not found:', targetSelector);
      return;
    }

    switch (strategy) {
      case 'innerHTML':
        target.innerHTML = content;
        break;
      case 'outerHTML':
        target.outerHTML = content;
        break;
      case 'beforebegin':
        target.insertAdjacentHTML('beforebegin', content);
        break;
      case 'afterbegin':
        target.insertAdjacentHTML('afterbegin', content);
        break;
      case 'beforeend':
        target.insertAdjacentHTML('beforeend', content);
        break;
      case 'afterend':
        target.insertAdjacentHTML('afterend', content);
        break;
      case 'morph':
        morph(target, content);
        break;
      default:
        target.innerHTML = content;
    }

    /*
     * What arrived may declare triggers of its own — a fragment that refreshes
     * itself, a form inside a panel. Without this it would be inert, and the
     * page would work once and then stop, which is the kind of bug people
     * describe as "it only updates the first time".
     */
    bindTriggers(target.parentNode || document);
  }

  // ========== FORMS ==========

  const form = {
    serialize: (formElement) => {
      const formData = new FormData(formElement);
      const obj = {};
      formData.forEach((value, key) => {
        if (obj[key]) {
          if (Array.isArray(obj[key])) {
            obj[key].push(value);
          } else {
            obj[key] = [obj[key], value];
          }
        } else {
          obj[key] = value;
        }
      });
      return obj;
    },

    submit: (formElement, options = {}) => {
      const data = form.serialize(formElement);
      const declared = declaration(formElement);

      const method = declared
        ? declared.method
        : (formElement.getAttribute('method') || 'POST').toUpperCase();

      const action = (declared && declared.url) || formElement.getAttribute('action') || '';
      const swapTarget = declared ? declared.target : attributeOf(formElement, 'target');
      const swapStrategy = (declared ? declared.swap : attributeOf(formElement, 'swap')) || 'innerHTML';

      const swapOptions = { target: swapTarget || null, swap: swapStrategy };

      /*
       * GET and DELETE take (url, options), the others take (url, data,
       * options). Calling all five the same way handed the serialised fields
       * over as the options object, so a declarative GET form lost its target
       * and its swap strategy and sent no fields at all — it fetched the bare
       * action and quietly swapped nothing.
       */
      if (method === 'GET' || method === 'DELETE') {
        return ajax[method.toLowerCase()](withQuery(action, data), swapOptions);
      }

      return ajax[method.toLowerCase()](action, data, swapOptions);
    },
  };

  // ========== DOM UTILITIES ==========

  const dom = {
    addClass: (element, className) => {
      if (typeof element === 'string') element = document.querySelector(element);
      element?.classList.add(...className.split(' '));
    },

    removeClass: (element, className) => {
      if (typeof element === 'string') element = document.querySelector(element);
      element?.classList.remove(...className.split(' '));
    },

    toggleClass: (element, className) => {
      if (typeof element === 'string') element = document.querySelector(element);
      element?.classList.toggle(className);
    },

    hasClass: (element, className) => {
      if (typeof element === 'string') element = document.querySelector(element);
      return element?.classList.contains(className) || false;
    },

    show: (element) => {
      if (typeof element === 'string') element = document.querySelector(element);
      if (element) element.style.display = '';
    },

    hide: (element) => {
      if (typeof element === 'string') element = document.querySelector(element);
      if (element) element.style.display = 'none';
    },

    toggle: (element) => {
      if (typeof element === 'string') element = document.querySelector(element);
      if (element) element.style.display = element.style.display === 'none' ? '' : 'none';
    },

    on: (element, event, handler) => {
      if (typeof element === 'string') element = document.querySelector(element);
      element?.addEventListener(event, handler);
    },

    off: (element, event, handler) => {
      if (typeof element === 'string') element = document.querySelector(element);
      element?.removeEventListener(event, handler);
    },

    ready: (callback) => {
      if (document.readyState !== 'loading') {
        callback();
      } else {
        document.addEventListener('DOMContentLoaded', callback);
      }
    },
  };

  // ========== VALIDATION ==========

  const validate = {
    email: (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
    required: (value) => value.trim().length > 0,
    minLength: (value, min) => value.length >= min,
    maxLength: (value, max) => value.length <= max,
    pattern: (value, regex) => new RegExp(regex).test(value),
    number: (value) => /^[0-9]+$/.test(value),
    url: (value) => {
      try {
        new URL(value);
        return true;
      } catch {
        return false;
      }
    },
  };

  const form_validation = {
    validate: (element) => {
      const rule = element.getAttribute('@validate');
      const value = element.value;

      if (!rule || !validate[rule]) return true;

      return validate[rule](value);
    },

    showError: (element, message) => {
      const errorId = `${element.id}-error`;
      let errorEl = document.getElementById(errorId);

      if (!errorEl) {
        errorEl = document.createElement('div');
        errorEl.id = errorId;
        errorEl.className = 'text-danger text-sm mt-1';
        element.parentNode.insertBefore(errorEl, element.nextSibling);
      }

      errorEl.textContent = message;
      dom.addClass(element, 'is-invalid');
    },

    clearError: (element) => {
      const errorId = `${element.id}-error`;
      const errorEl = document.getElementById(errorId);
      if (errorEl) errorEl.remove();
      dom.removeClass(element, 'is-invalid');
    },
  };

  // ========== STORAGE ==========

  const storage = {
    set: (key, value) => localStorage.setItem(key, JSON.stringify(value)),
    get: (key) => {
      const item = localStorage.getItem(key);
      return item ? JSON.parse(item) : null;
    },
    remove: (key) => localStorage.removeItem(key),
    clear: () => localStorage.clear(),
  };

  // ========== UTILITIES ==========

  const util = {
    debounce: (fn, delay) => {
      let timeout;
      return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(this, args), delay);
      };
    },

    throttle: (fn, delay) => {
      let last = 0;
      return function (...args) {
        const now = Date.now();
        if (now - last >= delay) {
          last = now;
          fn.apply(this, args);
        }
      };
    },

    wait: (ms) => new Promise(resolve => setTimeout(resolve, ms)),
  };

  /**
   * Update a subtree to match new markup, changing only what differs.
   *
   * Replacing innerHTML throws the old nodes away, and with them the focus,
   * the caret and anything typed into an input that had not been sent yet. A
   * panel that refreshes every ten seconds would then make a form inside it
   * unusable — which is the difference between "it works" and "it is usable".
   *
   * This walks the two trees together: same tag in the same position is kept
   * and updated, anything else is replaced. A field's value is left alone
   * unless the server actually sent a different one, so typing survives a
   * refresh that did not mean to change it.
   *
   * @param {Element} target The element to update
   * @param {string} html The markup it should match
   * @returns {void}
   */
  function morph(target, html) {
    const parsed = document.createElement('template');
    parsed.innerHTML = html;

    morphChildren(target, parsed.content);
  }

  /**
   * Reconcile one element's children against another's.
   *
   * @param {Element} current The element being updated
   * @param {Element|DocumentFragment} next What it should look like
   * @returns {void}
   */
  function morphChildren(current, next) {
    const existing = Array.from(current.childNodes);
    const wanted = Array.from(next.childNodes);

    wanted.forEach((node, index) => {
      const here = existing[index];

      if (!here) {
        current.appendChild(node.cloneNode(true));
        return;
      }

      morphNode(here, node);
    });

    for (let i = wanted.length; i < existing.length; i++) {
      current.removeChild(existing[i]);
    }
  }

  /**
   * Make one node match another, in place where possible.
   *
   * @param {Node} here The node in the page
   * @param {Node} there The node it should match
   * @returns {void}
   */
  function morphNode(here, there) {
    if (here.nodeType !== there.nodeType || here.nodeName !== there.nodeName) {
      here.replaceWith(there.cloneNode(true));
      return;
    }

    if (here.nodeType === Node.TEXT_NODE || here.nodeType === Node.COMMENT_NODE) {
      if (here.nodeValue !== there.nodeValue) here.nodeValue = there.nodeValue;
      return;
    }

    if (here.nodeType !== Node.ELEMENT_NODE) return;

    // An id that changed means it is a different thing, not the same one moved.
    if (here.id && there.id && here.id !== there.id) {
      here.replaceWith(there.cloneNode(true));
      return;
    }

    Array.from(there.attributes).forEach((attribute) => {
      if (here.getAttribute(attribute.name) !== attribute.value) {
        here.setAttribute(attribute.name, attribute.value);
      }
    });

    Array.from(here.attributes).forEach((attribute) => {
      if (!there.hasAttribute(attribute.name)) here.removeAttribute(attribute.name);
    });

    /*
     * The value of a field is state the visitor owns, and it is not in the
     * attributes: setAttribute('value') does not move the caret, but assigning
     * .value does. It is only assigned when the server sent something
     * different, so a refresh that changed nothing about this field leaves
     * what is being typed exactly where it is.
     */
    if ('value' in here && here.value !== undefined) {
      const sent = there.getAttribute('value');

      if (sent !== null && here.value !== sent && document.activeElement !== here) {
        here.value = sent;
      }
    }

    morphChildren(here, there);
  }

  // ========== DECLARATIVE TRIGGERS ==========

  /**
   * Send what an element declares.
   *
   * A form sends its fields; anything else sends the bare URL.
   *
   * @param {Element} element The element
   * @returns {?Promise}
   */
  function send(element) {
    if (element.tagName === 'FORM') return form.submit(element);

    const declared = declaration(element);

    if (!declared) return null;

    const options = { target: declared.target, swap: declared.swap };

    /*
     * A field carries its own value. Typing in a search box and having the
     * request go out without what was typed is the sort of thing that looks
     * like a broken server, so a named input sends itself the same way a form
     * would: as a query string on GET and DELETE, as a body on the rest.
     */
    const data = {};

    if (element.name && 'value' in element) data[element.name] = element.value;

    const method = declared.method.toLowerCase();

    if (method === 'get' || method === 'delete') {
      return ajax[method](withQuery(declared.url, data), options);
    }

    return ajax[method](declared.url, data, options);
  }

  /**
   * Wire up every element that says when it should fire.
   *
   * @trigger takes a comma-separated list, so one element can load itself and
   * then keep itself current:
   *
   *   <div @get="/sales" @trigger="load, every 10s"></div>
   *   <input name="q" @get="/search" @trigger="input delay:300ms" @target="#results">
   *
   * Without it, the old behaviour stands: a click on an element, a submit on a
   * form.
   *
   * @param {ParentNode} root Where to look
   * @returns {void}
   */
  function bindTriggers(root) {
    const selector = VERBS.map((verb) => '[\\@' + verb + '], [\\@hx' + verb + ']').join(', ');

    root.querySelectorAll(selector).forEach((element) => {
      const spec = attributeOf(element, 'trigger');

      if (!spec || element.__sfBound) return;

      element.__sfBound = true;

      spec.split(',').forEach((one) => {
        const parts = one.trim().split(/\s+/);
        const name = (parts[0] || '').toLowerCase();
        const delay = readDelay(parts);

        if (name === 'load') {
          send(element);
          return;
        }

        if (name === 'every') {
          const period = readPeriod(parts[1]);

          if (period > 0) setInterval(() => send(element), period);
          return;
        }

        if (name === '') return;

        const fire = delay > 0 ? util.debounce(() => send(element), delay) : () => send(element);

        element.addEventListener(name, (event) => {
          /*
           * A submit or a click that came from a trigger still has its own
           * default to prevent — a form would otherwise navigate away and take
           * the page with it.
           */
          if (name === 'submit' || name === 'click') event.preventDefault();

          fire();
        });
      });
    });
  }

  /**
   * Read "delay:300ms" out of a trigger's words.
   *
   * @param {string[]} parts The words
   * @returns {number} Milliseconds
   */
  function readDelay(parts) {
    const found = parts.find((part) => part.toLowerCase().startsWith('delay:'));

    return found ? readPeriod(found.slice('delay:'.length)) : 0;
  }

  /**
   * Read a period such as "10s", "500ms" or "2".
   *
   * @param {?string} text The text
   * @returns {number} Milliseconds
   */
  function readPeriod(text) {
    if (!text) return 0;

    const match = /^([0-9.]+)(ms|s|m)?$/.exec(text.trim().toLowerCase());

    if (!match) return 0;

    const amount = parseFloat(match[1]);
    const unit = match[2] || 's';

    if (unit === 'ms') return amount;
    if (unit === 'm') return amount * 60000;

    return amount * 1000;
  }

  // ========== AUTO-INITIALIZATION ==========

  function init() {
    const selector = VERBS.map((verb) => '[\\@' + verb + '], [\\@hx' + verb + ']').join(', ');

    document.addEventListener('click', (e) => {
      const element = e.target.closest(selector);
      if (!element) return;

      /*
       * A form is driven by its submit event, which is the only place the
       * fields are serialised. Clicking its submit button used to arrive here
       * first — closest() walks up — and this prevented the default, so the
       * submit never fired and the bare action was fetched with nothing the
       * visitor had typed.
       */
      if (element.tagName === 'FORM') return;

      // An element that says when to fire is not fired by a click as well.
      if (attributeOf(element, 'trigger')) return;

      e.preventDefault();
      send(element);
    });

    document.addEventListener('submit', (e) => {
      if (!declaration(e.target)) return;
      if (attributeOf(e.target, 'trigger')) return;

      e.preventDefault();
      form.submit(e.target);
    });

    bindTriggers(document);

    // Validation on blur/change
    document.addEventListener('blur', (e) => {
      if (!e.target.hasAttribute('@validate')) return;

      const rule = e.target.getAttribute('@validate');
      const isValid = form_validation.validate(e.target);

      if (!isValid) {
        form_validation.showError(e.target, `Invalid ${rule}`);
      } else {
        form_validation.clearError(e.target);
      }
    }, true);

    // @toggle
    document.addEventListener('click', (e) => {
      const target = e.target.closest('[\\@toggle]');
      if (!target) return;

      const toggleId = target.getAttribute('@toggle');
      const toggleTarget = document.getElementById(toggleId);

      if (toggleTarget) {
        dom.toggle(toggleTarget);
      }
    });
  }

  // Initialize when DOM is ready
  dom.ready(init);

  // ========== EXPORTS ==========

  return {
    ajax,
    bind: bindTriggers,
    form: { ...form, ...form_validation },
    dom,
    validate,
    storage,
    util,
  };
})();

// Make globally available
window.sf = sf;
