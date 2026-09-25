/**
 * SFJS — Simple Framework JavaScript Library, core
 * HTMX-like AJAX, forms, and DOM utilities without dependencies
 *
 * One of three parts — core.js, stream.js, ui.js — that the builder
 * (tools/js-builder/sfjs-builder.php) joins, in that order, into the single
 * sfjs.js / sfjs.min.js a page loads. The whole bundle is one script tag.
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
    /*
     * morph, not innerHTML. Replacing markup throws away the focus, the caret
     * and anything typed and not yet sent, so the old default quietly broke a
     * form inside any panel that refreshed itself — a framework should not
     * make the destructive choice on the visitor's behalf. It costs about four
     * times as much: measured in Chrome, a 200-row table is 1.4 ms against 6
     * ms, and a 1,000-row one is 4 ms against 16. Both are a fragment's worth
     * of work, and a fragment that large is a pagination problem.
     */
    swapStrategy: 'morph',
    validateOn: 'blur',
    debounceDelay: 300,
  };

  /*
   * Everything SFJS says to a visitor, in one place, so an application can say
   * it in the visitor's language. English is the default for the same reason
   * it is on the server: a framework used anywhere cannot pick one audience's
   * language for everybody else. Assigning sf.messages, or passing messages to
   * sf.config(), replaces only the keys it names, and a single field can carry
   * its own text in data-msg-<rule>. {min}, {max} and {error} are filled in
   * where they appear.
   */
  const messages = {
    required: 'This field is required.',
    email: 'Enter a valid email address.',
    number: 'Use digits only.',
    alpha: 'Use letters only.',
    alphanum: 'Use letters and numbers only.',
    min: 'Enter a value of at least {min}.',
    max: 'Enter a value of at most {max}.',
    minLength: 'Use at least {min} characters.',
    maxLength: 'Use at most {max} characters.',
    pattern: 'Use the format requested.',
    url: 'Enter a valid URL.',
    invalid: 'This value is not valid.',
    close: 'Close',
    streamClosed: '[Connection closed]',
    streamError: 'Error: {error}',
  };

  /**
   * Fill the {placeholders} of a text.
   *
   * @param {string} text The text
   * @param {Object} params The values, by placeholder name
   * @returns {string}
   */
  function format(text, params = {}) {
    return String(text).replace(/\{(\w+)\}/g, (whole, name) => (name in params ? params[name] : whole));
  }

  /**
   * A message in the page's language, by key.
   *
   * @param {string} key The key in sf.messages
   * @param {Object} params The values for its placeholders
   * @returns {string}
   */
  function t(key, params) {
    return format(key in messages ? messages[key] : key, params);
  }

  /**
   * Announce something on an element, the way the browser announces its own
   * events: bubbling, so one listener on the document hears every element.
   *
   * An element a swap has already taken out of the page cannot bubble to
   * anything, so the event goes to the document instead — otherwise a button
   * that replaced itself would report its own answer to nobody.
   *
   * @param {?Element} element Where it happened
   * @param {string} name The event name
   * @param {Object} detail What listeners get in event.detail
   * @param {boolean} cancelable Whether preventDefault() can stop it
   * @returns {boolean} False when a listener cancelled it
   */
  function emit(element, name, detail = {}, cancelable = false) {
    const on = element && element.isConnected ? element : document;

    return on.dispatchEvent(new CustomEvent(name, { bubbles: true, cancelable, detail }));
  }

  /**
   * An element, from itself or from a selector.
   *
   * @param {Element|string|null} what The element or a CSS selector
   * @returns {?Element}
   */
  function find(what) {
    return typeof what === 'string' ? document.querySelector(what) : what || null;
  }

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

  /**
   * Send a request and put the answer where it was asked for.
   *
   * `source` is the element that asked, when there is one. It is what the
   * lifecycle events are dispatched on, what is marked busy, and what owns the
   * request: a second request from the same element aborts the first, because
   * a slow answer to an old question must never overwrite a fast answer to the
   * new one — the search box that shows results for "ab" after "abc" was typed.
   *
   * @param {string} method The HTTP method
   * @param {string} url Where to send it
   * @param {Object} options data, target, swap, source, errorTarget, onSuccess, onError
   * @returns {Promise<void>}
   */
  function request(method, url, options = {}) {
    const { data = {}, target = null, swap = DEFAULTS.swapStrategy, onSuccess = null, onError = null, source = null, errorTarget = null } = options;
    const into = find(target);

    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
    let body;

    /*
     * A GET carries no body, so it carries no Content-Type either — claiming
     * JSON for nothing makes some proxies and servers refuse the request. A
     * FormData body is left for the browser to label, since only it knows the
     * multipart boundary it chose; that is how a file reaches the server.
     */
    if (method !== 'GET' && method !== 'DELETE') {
      if (data instanceof FormData) {
        body = data;
      } else {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(data);
      }
    }

    // Add CSRF token for state-changing requests
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      if (token) {
        headers['X-CSRF-Token'] = token;
      }
    }

    if (!emit(source, 'sf:before', { url, method, target: into }, true)) return Promise.resolve();

    if (source && source.__sfRequest) {
      source.__sfRequest.abort();
      setBusy(source, source.__sfRequest.into, false);
    }

    const controller = new AbortController();
    controller.into = into;

    if (source) source.__sfRequest = controller;

    // Whether this is still the element's latest request.
    const current = () => !source || source.__sfRequest === controller;

    setBusy(source, into, true);

    return fetch(url, { method, headers, body, signal: controller.signal })
      .then((response) => response.text().then((html) => {
        if (!current()) return;

        /*
         * An error answer is still an answer. A 422 carrying the form back with
         * its messages is the most useful thing the server can send, and it
         * used to be thrown away with nothing but "HTTP 422" in the console.
         * @error-target says where it goes; without one, sf:error says it came.
         */
        if (!response.ok) {
          const error = new Error('HTTP ' + response.status);
          const fallback = find(errorTarget);

          if (fallback) performSwap(fallback, html, swap);
          else console.error('SFJS Ajax Error:', error);

          emit(source, 'sf:error', { error, response, target: fallback });
          if (onError) onError(error, html);

          return;
        }

        if (target) performSwap(target, html, swap);
        emit(source, 'sf:after', { response, target: into });
        if (onSuccess) onSuccess(html);
      }))
      .catch((error) => {
        if (error.name === 'AbortError' || !current()) return;

        console.error('SFJS Ajax Error:', error);
        emit(source, 'sf:error', { error, response: null, target: null });
        if (onError) onError(error);
      })
      .finally(() => {
        if (!current()) return;

        setBusy(source, into, false);
        if (source) source.__sfRequest = null;
      });
  }

  /**
   * Mark a request as in the air, or as landed.
   *
   * The target says aria-busy, so a screen reader waits for the new content
   * instead of reading half of it, and CSS can dim it. The control that sent
   * it is disabled, so a second click cannot send a second copy of an order —
   * but a text field is only marked, never disabled, because disabling it
   * would take the focus away from somebody who is still typing.
   *
   * @param {?Element} source The element that sent it
   * @param {?Element} target Where the answer goes
   * @param {boolean} on Whether it is starting or ending
   * @returns {void}
   */
  function setBusy(source, target, on) {
    if (target) {
      if (on) target.setAttribute('aria-busy', 'true');
      else target.removeAttribute('aria-busy');
    }

    if (!source) return;

    if (!on) {
      (source.__sfDisabled || []).forEach((control) => {
        if (control.tagName !== 'A') control.disabled = false;
        control.removeAttribute('aria-disabled');
      });

      // Disabling the focused button sent the focus to the page; give it back.
      if (source.__sfFocus && document.activeElement === document.body) source.__sfFocus.focus();

      source.__sfDisabled = null;
      source.__sfFocus = null;

      return;
    }

    const controls = source.tagName === 'FORM'
      ? Array.from(source.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]'))
      : (source.matches('a, button, input[type="submit"], input[type="button"]') ? [source] : []);

    source.__sfDisabled = controls.filter((control) => !control.disabled && control.getAttribute('aria-disabled') !== 'true');
    source.__sfFocus = source.__sfDisabled.find((control) => control === document.activeElement) || null;

    source.__sfDisabled.forEach((control) => {
      if (control.tagName !== 'A') control.disabled = true;
      control.setAttribute('aria-disabled', 'true');
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

    /*
     * innerHTML and outerHTML rebuild the nodes, and the focus goes with them
     * — a keyboard user is thrown back to the top of the page. When the
     * focused element has an id and the new markup has it too, it is the same
     * field as far as the visitor is concerned, so the focus and caret return.
     */
    const focused = document.activeElement;
    const refocus = (strategy === 'innerHTML' || strategy === 'outerHTML')
      && focused && focused.id && target.contains(focused) ? focused.id : null;
    let caret = null;

    try {
      if (refocus) caret = [focused.selectionStart, focused.selectionEnd];
    } catch {
      caret = null;
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
        morph(target, content);
    }

    const again = refocus ? document.getElementById(refocus) : null;

    if (again && again !== focused) {
      again.focus();

      try {
        if (caret && caret[0] !== null) again.setSelectionRange(caret[0], caret[1]);
      } catch {
        // Not a field with a caret; the focus is what mattered.
      }
    }

    /*
     * What arrived may declare triggers of its own — a fragment that refreshes
     * itself, a form inside a panel. Without this it would be inert, and the
     * page would work once and then stop, which is the kind of bug people
     * describe as "it only updates the first time".
     */
    bindAll(target.parentNode || document);
  }

  // ========== FORMS ==========

  const form = {
    /*
     * A field that appears twice is a list, and a name ending in [] is a list
     * even when only one box was ticked — otherwise the server receives a
     * string one day and an array the next, depending on how many boxes the
     * visitor happened to tick.
     */
    serialize: (formElement, submitter = null) => {
      const formData = submitter ? new FormData(formElement, submitter) : new FormData(formElement);
      const obj = {};

      /*
       * Field names are read the way PHP reads them, so a form means the same
       * thing sent by SFJS as JSON and sent by the browser without it:
       * "tags[]" is the list "tags", and "address[city]" is "city" inside
       * "address". The brackets used to travel as part of the key, and the
       * server received {"tags[]": [...]} where it expected "tags".
       */
      formData.forEach((value, key) => {
        const match = /^([^[\]]+)((?:\[[^\]]*\])*)$/.exec(key);

        if (!match || match[2] === '') {
          if (key in obj) {
            obj[key] = Array.isArray(obj[key]) ? [...obj[key], value] : [obj[key], value];
          } else {
            obj[key] = value;
          }

          return;
        }

        const steps = [match[1], ...Array.from(match[2].matchAll(/\[([^\]]*)\]/g), (m) => m[1])];
        let holder = obj;

        for (let i = 0; i < steps.length - 1; i++) {
          const step = steps[i];
          const nextIsList = steps[i + 1] === '';

          if (!safeStep(step)) return;

          if (typeof holder[step] !== 'object' || holder[step] === null) holder[step] = nextIsList ? [] : {};
          holder = holder[step];
        }

        const last = steps[steps.length - 1];

        if (last === '') {
          if (Array.isArray(holder)) holder.push(value);
        } else if (safeStep(last)) {
          holder[last] = value;
        }
      });

      return obj;
    },

    submit: (formElement, options = {}) => {
      const submitter = options.submitter && options.submitter.form === formElement ? options.submitter : null;

      /*
       * A file cannot travel inside JSON, so a form that has a file input, or
       * says multipart itself, is sent as the browser would send it: FormData,
       * multipart, every repeated field kept.
       */
      const multipart = formElement.enctype === 'multipart/form-data'
        || formElement.querySelector('input[type="file"]') !== null;

      const data = multipart
        ? (submitter ? new FormData(formElement, submitter) : new FormData(formElement))
        : form.serialize(formElement, submitter);
      const declared = declaration(formElement);

      const method = declared
        ? declared.method
        : (formElement.getAttribute('method') || 'POST').toUpperCase();

      const action = (declared && declared.url) || formElement.getAttribute('action') || '';
      const swapTarget = declared ? declared.target : attributeOf(formElement, 'target');
      const swapStrategy = (declared ? declared.swap : attributeOf(formElement, 'swap')) || DEFAULTS.swapStrategy;

      const swapOptions = {
        target: swapTarget || null,
        swap: swapStrategy,
        source: formElement,
        errorTarget: attributeOf(formElement, 'error-target'),
      };

      /*
       * GET and DELETE take (url, options), the others take (url, data,
       * options). Calling all five the same way handed the serialised fields
       * over as the options object, so a declarative GET form lost its target
       * and its swap strategy and sent no fields at all — it fetched the bare
       * action and quietly swapped nothing.
       */
      if (method === 'GET' || method === 'DELETE') {
        return ajax[method.toLowerCase()](withQuery(action, form.serialize(formElement, submitter)), swapOptions);
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

    /*
     * The hidden attribute, as @show, @toggle and SFCSS use it. An inline
     * display could not reveal an element hidden with the attribute, and it
     * overrode whatever display — flex, grid — the element's own CSS gave it.
     * An inline "display: none" written to avoid a flash is cleared on show.
     */
    show: (element) => {
      if (typeof element === 'string') element = document.querySelector(element);
      if (!element) return;
      element.hidden = false;
      if (element.style.display === 'none') element.style.display = '';
    },

    hide: (element) => {
      if (typeof element === 'string') element = document.querySelector(element);
      if (element) element.hidden = true;
    },

    toggle: (element) => {
      if (typeof element === 'string') element = document.querySelector(element);
      if (!element) return;
      if (element.hidden || element.style.display === 'none') dom.show(element);
      else dom.hide(element);
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

  /*
   * The same rule names the server validates with, so a form says one thing
   * once. The browser's answer is a convenience — anybody can skip it with a
   * request of their own — and the server's is the one that counts, which is
   * why the two lists have to agree: a field the browser accepts and the
   * server rejects is a form that fails after it looked fine.
   */
  const validate = {
    required: (value) => String(value).trim().length > 0,
    /*
     * The server's rule: a local part and a dotted domain, in any script, with
     * no empty label — "josé@exemplo.com.br" passes, "a@b..com" does not.
     */
    email: (value) => /^[^\s@(),:;<>[\]\\]+@(?!.*\.\.)[^\s@.][^\s@]*\.[^\s@.]+$/u.test(value),
    number: (value) => /^[0-9]+$/.test(value),
    alpha: (value) => /^\p{L}[\p{L}\p{M}]*$/u.test(value),
    alphanum: (value) => /^[\p{L}\p{N}][\p{L}\p{M}\p{N}]*$/u.test(value),
    minLength: (value, min) => characters(value) >= Number(min),
    maxLength: (value, max) => characters(value) <= Number(max),
    /*
     * min and max follow the value, as they do on the server: a number is
     * compared, anything else is counted. "age must be at least 18" and "name
     * must be at least 3 characters" are both what somebody meant when they
     * wrote it.
     */
    min: (value, bound) => (isNumeric(value)
      ? Number(value) >= Number(bound)
      : characters(value) >= Number(bound)),
    max: (value, bound) => (isNumeric(value)
      ? Number(value) <= Number(bound)
      : characters(value) <= Number(bound)),
    pattern: (value, regex) => new RegExp(regex, 'u').test(value),
    /*
     * A web address, as the server has it: http or https, with a host. The
     * URL parser alone accepted "javascript:alert(1)" and "foo:bar", which
     * the server refuses, so the browser said yes and the server said no.
     */
    url: (value) => {
      try {
        const parsed = new URL(value);

        return (parsed.protocol === 'http:' || parsed.protocol === 'https:') && parsed.hostname !== '';
      } catch {
        return false;
      }
    },
  };

  /**
   * How many characters a reader sees, the way the server counts them.
   *
   * "José" typed with a combining accent is four, and an emoji family is one.
   * Code points counted them as five and seven.
   *
   * @param {*} value The value
   * @returns {number}
   */
  function characters(value) {
    const text = String(value);

    if (typeof Intl !== 'undefined' && Intl.Segmenter) {
      return Array.from(new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text)).length;
    }

    return [...text].length;
  }

  /**
   * Whether a value is a number, the way the server decides it.
   *
   * @param {*} value The value
   * @returns {boolean}
   */
  function isNumeric(value) {
    return String(value).trim() !== '' && !Number.isNaN(Number(value));
  }

  /**
   * The first rule a field breaks, or null when it breaks none.
   *
   * Rules are pipe separated and an argument comes after a colon, the same as
   * on the server. The argument used to be dropped: the whole "minLength:5"
   * was looked up as a rule name, not found, and the field passed — so three
   * of the documented rules never did anything.
   *
   * An empty field — blank, or only spaces — is judged by required alone,
   * wherever it sits in the list: with it, "required" is the one message;
   * without it the field is optional and nothing is checked. A field with a
   * value is checked against the rest. The server decides the same way, so
   * the two never disagree about a blank field.
   *
   * @param {Element} element The field
   * @returns {?{name: string, argument: ?string, value: string}}
   */
  function failure(element) {
    const spec = element.getAttribute('@validate');

    if (!spec) return null;

    // An unticked checkbox still has a value ("on"), so required would pass.
    const value = element.type === 'checkbox' && !element.checked ? '' : element.value;

    const rules = spec.split('|').map((one) => {
      const at = one.indexOf(':');

      const written = (at === -1 ? one : one.slice(0, at)).trim();

      /*
       * Rule names are read regardless of case, as the server reads them:
       * "minlength:3" is minLength, and used to be "a rule SFJS does not
       * know" while the server applied it.
       */
      const name = Object.keys(validate).find((rule) => rule.toLowerCase() === written.toLowerCase()) || written;

      return { name, argument: at === -1 ? null : one.slice(at + 1) };
    }).filter((rule) => rule.name !== '');

    for (const { name } of rules) {
      if (!validate[name]) console.error('SFJS: @validate does not know "' + name + '"');
    }

    if (String(value).trim() === '') {
      return rules.some((rule) => rule.name === 'required')
        ? { name: 'required', argument: null, value }
        : null;
    }

    for (const { name, argument } of rules) {
      if (name === 'required' || !validate[name]) continue;

      if (!validate[name](value, argument)) return { name, argument, value };
    }

    return null;
  }

  /**
   * What to tell the visitor about a broken rule.
   *
   * The field's own data-msg-<rule> first, then sf.messages. min and max on a
   * value that is not a number count characters, as they do on the server, so
   * they borrow the minLength and maxLength wording — "at least 3" is not
   * something a visitor can act on when it means characters.
   *
   * @param {Element} element The field
   * @param {{name: string, argument: ?string, value: string}} broken What failed
   * @returns {string}
   */
  function messageFor(element, broken) {
    const { name, argument, value } = broken;
    const key = (name === 'min' || name === 'max') && !isNumeric(value) ? name + 'Length' : name;
    const own = element.getAttribute('data-msg-' + name.toLowerCase());

    return format(own || (key in messages ? messages[key] : messages.invalid), { min: argument, max: argument });
  }

  // One counter for every id SFJS generates, so no two can collide.
  let sequence = 0;

  const form_validation = {
    validate: (element) => failure(element) === null,

    /**
     * Validate a field and say so on the page, both ways.
     *
     * @param {Element} element The field
     * @returns {boolean} Whether it passed
     */
    check: (element) => {
      const broken = failure(element);

      if (broken) form_validation.showError(element, messageFor(element, broken));
      else form_validation.clearError(element);

      return broken === null;
    },

    /*
     * The message is tied to the field with aria-describedby, so a screen
     * reader reads it when the field is focused rather than leaving it as text
     * somewhere nearby. Its id is generated, never derived from the field's:
     * two fields without an id both used to write into "undefined-error", and
     * a field whose id was "name" collided with anything already called
     * "name-error". The styling belongs to the stylesheet — is-invalid and
     * invalid-feedback — not to utility classes chosen here.
     */
    showError: (element, message) => {
      let note = element.__sfError;

      if (!note || !note.isConnected) {
        note = document.createElement('div');
        note.id = 'sf-error-' + (++sequence);
        note.className = 'invalid-feedback';
        note.setAttribute('aria-live', 'polite');
        element.after(note);
        element.__sfError = note;
      }

      note.textContent = message;
      element.classList.add('is-invalid');
      element.setAttribute('aria-invalid', 'true');
      describedBy(element, note.id, true);
    },

    clearError: (element) => {
      const note = element.__sfError;

      if (note) {
        describedBy(element, note.id, false);
        note.remove();
        element.__sfError = null;
      }

      element.classList.remove('is-invalid');
      element.removeAttribute('aria-invalid');
    },
  };

  /**
   * Add an id to aria-describedby, or take it out, keeping whatever else the
   * markup had put there — a hint written by the page is not ours to delete.
   *
   * @param {Element} element The element described
   * @param {string} id The describing element's id
   * @param {boolean} add Whether to add or remove it
   * @returns {void}
   */
  function describedBy(element, id, add) {
    const ids = (element.getAttribute('aria-describedby') || '').split(' ').filter((one) => one && one !== id);

    if (add) ids.push(id);

    if (ids.length) element.setAttribute('aria-describedby', ids.join(' '));
    else element.removeAttribute('aria-describedby');
  }

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

    /**
     * An element's id, giving it a unique one first if it has none — ARIA
     * links elements by id, and markup does not always bring them.
     *
     * @param {Element} element The element
     * @param {string} prefix How the generated id starts
     * @returns {string}
     */
    id: (element, prefix = 'sf') => {
      if (!element.id) element.id = prefix + '-' + (++sequence);

      return element.id;
    },
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
    /*
     * Children are matched by key first — @key, or failing that the id — and
     * only then by position. By position alone, a row inserted at the top of a
     * list shifted every row below it by one: each was morphed into its
     * neighbour, and the field being typed into became a different field with
     * the caret still in it. A keyed child is found wherever it now sits.
     */
    const keyed = new Map();

    Array.from(current.children).forEach((child) => {
      const key = keyOf(child);

      if (key) keyed.set(key, child);
    });

    let cursor = current.firstChild;

    Array.from(next.childNodes).forEach((node) => {
      const key = keyOf(node);
      const match = key ? keyed.get(key) : null;

      if (match) {
        keyed.delete(key);

        if (match !== cursor) current.insertBefore(match, cursor);

        cursor = match.nextSibling;
        morphNode(match, node);

        return;
      }

      /*
       * A keyed child in the page is never morphed into something else: it is
       * either claimed by its key further on, or removed at the end.
       */
      if (!key && cursor && !keyOf(cursor)) {
        const here = cursor;

        cursor = cursor.nextSibling;
        morphNode(here, node);

        return;
      }

      current.insertBefore(node.cloneNode(true), cursor);
    });

    while (cursor) {
      const extra = cursor;

      cursor = cursor.nextSibling;
      current.removeChild(extra);
    }
  }

  /**
   * What identifies a node across two renders, if anything does.
   *
   * @param {Node} node The node
   * @returns {?string}
   */
  function keyOf(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return null;

    return node.getAttribute('@key') || node.id || null;
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
        /*
         * setAttribute() refuses a name that starts with "@" in some
         * browsers, which would stop the morph half-way through a node.
         * Copying the attribute node itself carries any name the parser
         * accepted.
         */
        try {
          here.setAttribute(attribute.name, attribute.value);
        } catch (error) {
          here.setAttributeNode(attribute.cloneNode());
        }
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
    const typed = here.tagName === 'TEXTAREA'
      || (here.tagName === 'INPUT' && !['checkbox', 'radio', 'file', 'button', 'submit', 'reset', 'image'].includes(here.type));

    /*
     * A field the server sends back with no value is an empty field. Only a
     * field with a value attribute used to be touched, so after a form was
     * answered with a fresh, empty copy of itself the text typed before
     * stayed in the box — and was sent again with the next submit.
     */
    if (typed) {
      const sent = here.tagName === 'TEXTAREA' ? there.textContent : (there.getAttribute('value') ?? '');

      if (here.value !== sent && document.activeElement !== here) {
        here.value = sent;
      }
    }

    /*
     * checked and selected are the same kind of state: the attribute is only
     * the starting value, so copying it changes nothing on the screen. The
     * property follows what the server sent, unless the visitor is on the
     * control right now. An option is only ever selected by this, never
     * deselected: markup that marks no option says nothing about which one the
     * visitor picked.
     */
    if (here.type === 'checkbox' || here.type === 'radio') {
      const sent = there.hasAttribute('checked');

      if (here.checked !== sent && document.activeElement !== here) here.checked = sent;
    }

    if (here.tagName === 'OPTION' && there.hasAttribute('selected') && !here.selected) {
      const field = here.closest('select');

      if (!field || document.activeElement !== field) here.selected = true;
    }

    morphChildren(here, there);
  }

  // ========== STATE ==========

  /*
   * A tiny expression language, parsed rather than eval()'d.
   *
   * Alpine and Vue hand their attribute values to new Function(), which
   * accepts all of JavaScript and, in exchange, requires 'unsafe-eval' in the
   * Content-Security-Policy of every page that uses them. This framework
   * spends a chapter arguing for a strict policy, so it cannot ship a feature
   * that quietly asks for the opposite.
   *
   * What is supported: paths (user.name), literals, ! - unary, the arithmetic
   * and comparison operators, && and ||, the ternary, object and array
   * literals, and assignment. What is not: calls, arrow functions, indexing by
   * expression. Those belong in PHP, before the markup, where the data already
   * is — and refusing them loudly beats supporting them at the price above.
   */

  const PUNCTUATION = ['===', '!==', '==', '!=', '<=', '>=', '&&', '||', '?', ':', '(', ')', '{', '}', '[', ']', ',', '.', '+', '-', '*', '/', '%', '<', '>', '=', '!'];

  /**
   * Break an expression into tokens.
   *
   * @param {string} source The expression
   * @returns {Array<{type: string, value: *}>}
   */
  function tokenize(source) {
    const tokens = [];
    let i = 0;

    while (i < source.length) {
      const char = source[i];

      if (/\s/.test(char)) { i++; continue; }

      if (char === '"' || char === "'") {
        let value = '';
        i++;

        while (i < source.length && source[i] !== char) {
          if (source[i] === '\\') i++;
          value += source[i++];
        }

        i++;
        tokens.push({ type: 'string', value });
        continue;
      }

      if (/[0-9]/.test(char)) {
        let raw = '';

        while (i < source.length && /[0-9.]/.test(source[i])) raw += source[i++];

        tokens.push({ type: 'number', value: parseFloat(raw) });
        continue;
      }

      if (/[A-Za-z_$]/.test(char)) {
        let name = '';

        while (i < source.length && /[A-Za-z0-9_$]/.test(source[i])) name += source[i++];

        if (name === 'true') tokens.push({ type: 'boolean', value: true });
        else if (name === 'false') tokens.push({ type: 'boolean', value: false });
        else if (name === 'null') tokens.push({ type: 'null', value: null });
        else tokens.push({ type: 'name', value: name });

        continue;
      }

      const punctuation = PUNCTUATION.find((one) => source.startsWith(one, i));

      if (!punctuation) throw new Error('SFJS: cannot read "' + char + '" in expression');

      tokens.push({ type: 'punctuation', value: punctuation });
      i += punctuation.length;
    }

    return tokens;
  }

  /**
   * Parse tokens into a tree.
   *
   * @param {Array} tokens The tokens
   * @returns {Object} The tree
   */
  function parse(tokens) {
    let at = 0;

    const peek = () => tokens[at];
    const take = () => tokens[at++];
    const eat = (value) => {
      if (!peek() || peek().value !== value) {
        throw new Error('SFJS: expected "' + value + '" in expression');
      }

      return take();
    };

    function primary() {
      const token = peek();

      if (!token) throw new Error('SFJS: expression ended early');

      if (token.value === '!' ) { take(); return { kind: 'not', value: primary() }; }
      if (token.value === '-') { take(); return { kind: 'negate', value: primary() }; }

      if (token.value === '(') {
        take();
        const inner = expression();
        eat(')');

        return inner;
      }

      if (token.value === '[') {
        take();
        const items = [];

        while (peek() && peek().value !== ']') {
          items.push(expression());

          if (peek() && peek().value === ',') take();
        }

        eat(']');

        return { kind: 'array', items };
      }

      if (token.value === '{') {
        take();
        const entries = [];

        while (peek() && peek().value !== '}') {
          const key = take();
          eat(':');
          entries.push([key.value, expression()]);

          if (peek() && peek().value === ',') take();
        }

        eat('}');

        return { kind: 'object', entries };
      }

      if (token.type === 'name') {
        take();
        const path = [token.value];

        while (peek() && peek().value === '.') {
          take();
          path.push(take().value);
        }

        return { kind: 'path', path };
      }

      take();

      return { kind: 'literal', value: token.value };
    }

    const LEVELS = [['||'], ['&&'], ['===', '!==', '==', '!='], ['<', '>', '<=', '>='], ['+', '-'], ['*', '/', '%']];

    function binary(level) {
      if (level >= LEVELS.length) return primary();

      let left = binary(level + 1);

      while (peek() && peek().type === 'punctuation' && LEVELS[level].includes(peek().value)) {
        const operator = take().value;
        left = { kind: 'binary', operator, left, right: binary(level + 1) };
      }

      return left;
    }

    function expression() {
      const left = binary(0);

      if (peek() && peek().value === '?') {
        take();
        const yes = expression();
        eat(':');

        return { kind: 'ternary', test: left, yes, no: expression() };
      }

      if (peek() && peek().value === '=' && left.kind === 'path') {
        take();

        return { kind: 'assign', path: left.path, value: expression() };
      }

      return left;
    }

    const tree = expression();

    if (at < tokens.length) throw new Error('SFJS: unexpected "' + tokens[at].value + '" in expression');

    return tree;
  }

  /** Expressions are parsed once and kept, because bindings re-evaluate often. */
  const parsed = new Map();

  /**
   * Parse an expression, reusing the tree when it has been seen before.
   *
   * @param {string} source The expression
   * @returns {Object} The tree
   */
  function compile(source) {
    if (!parsed.has(source)) parsed.set(source, parse(tokenize(source)));

    return parsed.get(source);
  }

  /**
   * Work out what an expression means in a scope.
   *
   * @param {Object} node The tree
   * @param {Object} scope The state
   * @returns {*} The value
   */
  /**
   * Whether a property name may be read or written by an expression.
   *
   * The prototype chain is out of reach: "__proto__", "prototype" and
   * "constructor" are how an expression walks from its state to every object
   * in the page.
   */
  function safeStep(step) {
    return step !== '__proto__' && step !== 'prototype' && step !== 'constructor';
  }

  function evaluate(node, scope) {
    switch (node.kind) {
      case 'literal': return node.value;
      case 'not': return !evaluate(node.value, scope);
      case 'negate': return -evaluate(node.value, scope);
      case 'array': return node.items.map((item) => evaluate(item, scope));
      case 'object': {
        const built = {};
        node.entries.forEach(([key, value]) => { built[key] = evaluate(value, scope); });

        return built;
      }
      case 'path': {
        let value = scope;

        for (const step of node.path) {
          if (value === null || value === undefined || !safeStep(step)) return undefined;
          value = value[step];
        }

        return value;
      }
      case 'ternary':
        return evaluate(node.test, scope) ? evaluate(node.yes, scope) : evaluate(node.no, scope);
      case 'assign': {
        const value = evaluate(node.value, scope);
        let holder = scope;

        /*
         * Every step is checked, so an expression cannot reach the prototype
         * chain: "constructor.prototype.isAdmin = true" used to set isAdmin
         * on every object in the page, which is what a strict CSP was meant
         * to rule out.
         */
        if (!node.path.every(safeStep)) return undefined;

        for (let i = 0; i < node.path.length - 1; i++) {
          if (holder === null || typeof holder !== 'object' || !Object.prototype.hasOwnProperty.call(holder, node.path[i])) return undefined;
          holder = holder[node.path[i]];
        }

        if (holder === null || typeof holder !== 'object') return undefined;

        holder[node.path[node.path.length - 1]] = value;

        return value;
      }
      case 'binary': {
        const left = evaluate(node.left, scope);

        // Short-circuit, so "user && user.name" is safe when there is no user.
        if (node.operator === '&&') return left ? evaluate(node.right, scope) : left;
        if (node.operator === '||') return left ? left : evaluate(node.right, scope);

        const right = evaluate(node.right, scope);

        switch (node.operator) {
          case '+': return left + right;
          case '-': return left - right;
          case '*': return left * right;
          case '/': return left / right;
          case '%': return left % right;
          case '==': return left == right;
          case '!=': return left != right;
          case '===': return left === right;
          case '!==': return left !== right;
          case '<': return left < right;
          case '>': return left > right;
          case '<=': return left <= right;
          case '>=': return left >= right;
        }
      }
    }

    return undefined;
  }

  /**
   * Make an object announce its changes.
   *
   * @param {Object} initial The starting values
   * @param {Function} onChange Called after any write
   * @returns {Proxy} The state
   */
  function reactive(initial, onChange) {
    const handler = {
      get(target, key) {
        const value = target[key];

        // Nested objects announce their own writes, so user.name = 'x' works.
        return value && typeof value === 'object' ? new Proxy(value, handler) : value;
      },
      set(target, key, value) {
        if (target[key] === value) return true;

        target[key] = value;
        onChange();

        return true;
      },
      deleteProperty(target, key) {
        delete target[key];
        onChange();

        return true;
      },
    };

    return new Proxy(initial, handler);
  }

  /**
   * Wire up every state scope under a root.
   *
   * A scope is an element carrying @state. Everything under it binds to that
   * state until another @state starts a scope of its own.
   *
   *   <div @state="{ open: false, name: '' }">
   *     <button @on:click="open = !open">Toggle</button>
   *     <div @show="open">
   *       <input @model="name">
   *       <p>Hello, <span @text="name"></span></p>
   *     </div>
   *   </div>
   *
   * Nothing here goes to the server. This is interface state — open, selected,
   * half-typed — and asking a server whether a menu is open is thirty
   * milliseconds spent on a decision that takes none.
   *
   * @param {ParentNode} root Where to look
   * @returns {void}
   */
  function bindState(root) {
    const scopes = [];

    if (root.nodeType === Node.ELEMENT_NODE && root.hasAttribute('@state')) scopes.push(root);

    if (root.querySelectorAll) root.querySelectorAll('[\\@state]').forEach((one) => scopes.push(one));

    scopes.forEach((element) => {
      if (element.__sfState) {
        /*
         * A scope that already exists has just been through a swap, and its
         * bindings point at nodes the swap replaced. Keeping the state and
         * collecting again is what lets client state survive a server-driven
         * refresh — without it the state says one thing and the page shows
         * another, silently, which is the worst of both.
         */
        if (element.__sfRebind) element.__sfRebind();

        return;
      }

      let state;
      const bindings = [];
      const apply = () => bindings.forEach((binding) => binding());

      try {
        state = reactive(evaluate(compile(element.getAttribute('@state') || '{}'), {}), apply);
      } catch (error) {
        console.error('SFJS: @state could not be read —', error.message);

        return;
      }

      element.__sfState = state;
      element.__sfRebind = () => {
        bindings.length = 0;
        collect(element, element, bindings, state);
        apply();
      };

      element.__sfRebind();
    });
  }

  /**
   * Find the state this element belongs to.
   *
   * @param {Element} element The element
   * @returns {?Object} The state, or null
   */
  function scopeOf(element) {
    const holder = element && element.closest ? element.closest('[\\@state]') : null;

    return holder ? holder.__sfState || null : null;
  }

  /**
   * Walk a scope and register what each element asked for.
   *
   * @param {Element} scope The element carrying @state
   * @param {Element} element The element being examined
   * @param {Array} bindings Where to add the update functions
   * @param {Object} state The scope's state
   * @returns {void}
   */
  function collect(scope, element, bindings, state) {
    if (element !== scope && element.hasAttribute && element.hasAttribute('@state')) return;

    if (element.attributes) {
      Array.from(element.attributes).forEach((attribute) => {
        const name = attribute.name.toLowerCase();
        const source = attribute.value;

        if (name === '@text') {
          bindings.push(() => {
            const value = read(source, state);
            const text = value === undefined || value === null ? '' : String(value);

            if (element.textContent !== text) element.textContent = text;
          });
        }

        if (name === '@show') {
          /*
           * The hidden attribute rather than an inline display: none, so the
           * element keeps whatever display its own CSS gives it when shown —
           * an inline style would have to guess between block, flex and grid.
           * An inline none written by the markup, to avoid a flash before
           * SFJS runs, is cleared the first time it is shown.
           */
          bindings.push(() => {
            const shown = !!read(source, state);

            element.hidden = !shown;
            if (shown && element.style.display === 'none') element.style.display = '';
          });
        }

        if (name === '@class') {
          /*
           * The element's own classes are read once, the first time it is
           * bound. Bindings are collected again after every swap, and reading
           * them then took the classes the expression had added for its own —
           * "btn active" became the fixed part, and toggling off never took
           * "active" away again.
           */
          if (element.__sfFixedClass === undefined) element.__sfFixedClass = element.getAttribute('class') || '';

          const fixed = element.__sfFixedClass;

          bindings.push(() => {
            const extra = read(source, state);
            element.setAttribute('class', (fixed + ' ' + (extra || '')).trim());
          });
        }

        if (name === '@model') {
          const path = source.trim();

          /*
           * A scope re-collects its bindings after a swap, and a node the
           * swap kept would otherwise end up with the listener twice — so
           * each element remembers what it is already listening for.
           */
          element.__sfOn = element.__sfOn || {};

          if (!element.__sfOn[name]) {
            element.__sfOn[name] = true;

            /*
             * A radio button writes its own value when it is the one chosen,
             * on change. Treated as a text field it wrote on input and had
             * its value overwritten by the state, so every radio in a group
             * ended up holding the same value and none was checked.
             */
            if (element.type === 'radio') {
              element.addEventListener('change', () => { if (element.checked) write(path, element.value, state); });
            } else {
              element.addEventListener('input', () => {
                write(path, element.type === 'checkbox' ? element.checked : element.value, state);
              });
            }
          }

          bindings.push(() => {
            const value = read(path, state);

            if (element.type === 'checkbox') {
              element.checked = !!value;

              return;
            }

            if (element.type === 'radio') {
              element.checked = value !== undefined && value !== null && String(value) === element.value;

              return;
            }

            const text = value === undefined || value === null ? '' : String(value);

            // Writing while somebody types moves the caret, so only when it differs.
            if (element.value !== text) element.value = text;
          });
        }

        if (name.startsWith('@on:')) {
          element.__sfOn = element.__sfOn || {};

          if (element.__sfOn[name]) return;

          element.__sfOn[name] = true;
          element.addEventListener(name.slice('@on:'.length), (event) => {
            if (element.tagName === 'FORM' || element.type === 'submit') event.preventDefault();

            try {
              evaluate(compile(source), state);
            } catch (error) {
              console.error('SFJS: ' + name + ' failed —', error.message);
            }
          });
        }
      });
    }

    Array.from(element.children || []).forEach((child) => collect(scope, child, bindings, state));
  }

  /**
   * Evaluate an expression, reporting rather than throwing.
   *
   * @param {string} source The expression
   * @param {Object} state The state
   * @returns {*} The value
   */
  function read(source, state) {
    try {
      return evaluate(compile(source), state);
    } catch (error) {
      console.error('SFJS: cannot evaluate "' + source + '" —', error.message);

      return undefined;
    }
  }

  /**
   * Assign to a path in the state.
   *
   * @param {string} path Dotted
   * @param {*} value The value
   * @param {Object} state The state
   * @returns {void}
   */
  function write(path, value, state) {
    const steps = path.split('.');
    let holder = state;

    for (let i = 0; i < steps.length - 1; i++) holder = holder[steps[i]];

    holder[steps[steps.length - 1]] = value;
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

    const into = attributeOf(element, 'into');
    const busy = attributeOf(element, 'loading');
    const state = into || busy ? scopeOf(element) : null;

    /*
     * Without @target the answer goes into the element that asked. It used
     * to go nowhere: the request was sent and its answer dropped, so the
     * documentation's own <div @get="/dashboard/sales" @trigger="load">
     * stayed empty.
     */
    const options = {
      target: declared.target || element,
      swap: declared.swap,
      source: element,
      errorTarget: attributeOf(element, 'error-target'),
    };

    if (state && into) {
      /*
       * @into puts the answer in the state instead of in the page, which is
       * what "fetch and render where I said" looks like without a line of
       * JavaScript: @get="/api/user" @into="user", then @text="user.name".
       */
      options.target = null;
      options.onSuccess = (body) => {
        try {
          write(into, JSON.parse(body), state);
        } catch (error) {
          console.error('SFJS: @into expected JSON —', error.message);
        }

        if (busy) write(busy, false, state);
      };
      options.onError = () => { if (busy) write(busy, false, state); };
    } else if (state && busy) {
      options.onSuccess = () => write(busy, false, state);
      options.onError = () => write(busy, false, state);
    }

    if (state && busy) write(busy, true, state);

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
        /*
         * One shape for everything: a word, or a word and a value joined by a
         * colon, separated by spaces. "every 10s" with a space is read as the
         * same thing, because it shipped — it is deprecated and goes in a
         * later release.
         */
        const parts = one.trim().split(/\s+/);
        const head = (parts[0] || '').split(':');
        const name = (head[0] || '').toLowerCase();
        const argument = head[1] || parts[1] || '';
        const delay = readDelay(parts);

        if (name === 'load') {
          // delay: works here too; it used to be read and then ignored.
          if (delay > 0) setTimeout(() => { if (element.isConnected) send(element); }, delay);
          else send(element);

          return;
        }

        if (name === 'every') {
          const period = readPeriod(argument);

          if (period <= 0) {
            console.error('SFJS: @trigger="every" needs a period, such as "every:10s"');

            return;
          }

          /*
           * A panel that a swap removed kept polling forever, sending requests
           * for an element nobody could see, and every visit to a page that
           * swaps panels in and out added another one. The timer now checks
           * that its element is still in the document, and stops itself the
           * first time it is not.
           */
          const start = () => {
            const timer = setInterval(() => {
              if (!element.isConnected) {
                clearInterval(timer);

                return;
              }

              send(element);
            }, period);
          };

          // delay: postpones the first run, so ten panels do not all fire at once.
          if (delay > 0) setTimeout(() => { if (element.isConnected) start(); }, delay);
          else start();

          return;
        }

        if (name === '') return;

        const fire = delay > 0 ? util.debounce(() => send(element), delay) : () => send(element);

        element.addEventListener(name, (event) => {
          if (name === 'click' && keepsDefault(event, element)) return;

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
   * Whether a click on a link means "open this somewhere else".
   *
   * Ctrl, Cmd and Shift clicks, and the middle button, are the visitor asking
   * for a new tab or window. Taking them over for a swap in place is the kind
   * of thing that makes people distrust every link on a site.
   *
   * @param {MouseEvent} event The click
   * @param {Element} element The element that declared the request
   * @returns {boolean}
   */
  function keepsDefault(event, element) {
    return element.tagName === 'A'
      && (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey);
  }

  // ========== @toggle ==========

  /**
   * The element a @toggle points at.
   *
   * A bare id keeps working, since that is what shipped; anything else is a
   * selector. The id is tried first because "menu" as a selector means the
   * <menu> element, which is not what anybody who wrote @toggle="menu" meant.
   *
   * @param {Element} trigger The element carrying @toggle
   * @returns {?Element}
   */
  function toggled(trigger) {
    const value = trigger.getAttribute('@toggle');

    if (!value) return null;

    try {
      return document.getElementById(value) || document.querySelector(value);
    } catch {
      return null;
    }
  }

  /**
   * Whether a panel is showing. An inline display: none counts as hidden, so
   * pages written for the old @toggle behave the same on their first click.
   *
   * @param {Element} panel The panel
   * @returns {boolean}
   */
  function isShown(panel) {
    return !panel.hidden && panel.style.display !== 'none';
  }

  /**
   * Tell every trigger of a panel what state the panel is in.
   *
   * aria-expanded is what a screen reader announces as "collapsed" or
   * "expanded", and aria-controls says which region the button is about. Two
   * buttons can open the same panel — one in the header, one in the page —
   * and both have to say the same thing.
   *
   * @param {ParentNode} root Where to look for triggers
   * @param {?Element} only Just the triggers of this panel, when given
   * @returns {void}
   */
  function syncToggles(root, only = null) {
    const triggers = Array.from(root.querySelectorAll('[\\@toggle]'));

    if (root.nodeType === Node.ELEMENT_NODE && root.hasAttribute('@toggle')) triggers.push(root);

    triggers.forEach((trigger) => {
      const panel = toggled(trigger);

      if (!panel || (only && panel !== only)) return;

      trigger.setAttribute('aria-controls', util.id(panel, 'sf-panel'));
      trigger.setAttribute('aria-expanded', String(isShown(panel)));
    });
  }

  /**
   * Show or hide a panel, announcing it first.
   *
   * @param {Element} panel The panel
   * @param {boolean} show Whether to show it
   * @returns {void}
   */
  function setPanel(panel, show) {
    if (!emit(panel, show ? 'sf:show' : 'sf:hide', {}, true)) return;

    panel.hidden = !show;
    if (show && panel.style.display === 'none') panel.style.display = '';

    syncToggles(document, panel);
  }

  // ========== BINDING ==========

  /** What extensions asked to run on every root that gets bound. */
  const binders = [];

  /**
   * Wire up everything SFJS knows about under a root: on load, and again on
   * whatever a swap brings in.
   *
   * @param {ParentNode} root Where to look
   * @returns {void}
   */
  function bindAll(root) {
    bindTriggers(root);
    bindState(root);
    syncToggles(root);
    binders.forEach((binder) => binder(root));
  }

  /**
   * Run a function on the document once it is ready, and on every fragment a
   * swap brings in afterwards. This is how the UI part and other extensions
   * wire up markup that did not exist when the page loaded.
   *
   * @param {Function} binder Called with the root to look under
   * @returns {void}
   */
  function onBind(binder) {
    binders.push(binder);

    if (initialised) binder(document);
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

  let initialised = false;

  function init() {
    const selector = VERBS.map((verb) => '[\\@' + verb + '], [\\@hx' + verb + ']').join(', ');

    /*
     * The server can hand the translations over in a meta element, which
     * needs no inline script — a page under a strict Content-Security-Policy
     * cannot run one — and is escaped by {{ }} like any other attribute.
     */
    const translated = document.querySelector('meta[name="sf-messages"]');

    if (translated) {
      try {
        Object.assign(messages, JSON.parse(translated.content));
      } catch (error) {
        console.error('SFJS: <meta name="sf-messages"> is not JSON —', error.message);
      }
    }

    /*
     * Validation runs before anything else hears the submit — in the capture
     * phase on the document, ahead of the form's own listeners — so an invalid
     * form is never sent, with SFJS or without it. The first field at fault
     * gets the focus, which is where a screen reader reads its message.
     */
    document.addEventListener('submit', (e) => {
      const invalid = Array.from(e.target.querySelectorAll('[\\@validate]'))
        .filter((field) => !field.disabled && !form_validation.check(field));

      if (invalid.length === 0) return;

      e.preventDefault();
      e.stopImmediatePropagation();
      invalid[0].focus();
    }, true);

    document.addEventListener('click', (e) => {
      const element = e.target.closest(selector);
      if (!element) return;
      if (keepsDefault(e, element)) return;

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
      form.submit(e.target, { submitter: e.submitter });
    });

    initialised = true;
    bindAll(document);

    document.addEventListener('blur', (e) => {
      if (e.target.hasAttribute && e.target.hasAttribute('@validate')) form_validation.check(e.target);
    }, true);

    /*
     * Once a field has been told it is wrong, it is told as soon as it is
     * right, not only when the visitor leaves it — otherwise the message
     * stays up while they fix exactly what it asked them to fix.
     */
    document.addEventListener('input', (e) => {
      if (e.target.getAttribute && e.target.getAttribute('aria-invalid') === 'true' && e.target.hasAttribute('@validate')) {
        form_validation.check(e.target);
      }
    });

    document.addEventListener('click', (e) => {
      const trigger = e.target.closest('[\\@toggle]');
      if (!trigger) return;

      const panel = toggled(trigger);
      if (!panel) return;

      if (trigger.tagName === 'A') e.preventDefault();

      setPanel(panel, !isShown(panel));
    });
  }

  // Initialize when DOM is ready
  dom.ready(init);

  // ========== EXPORTS ==========

  return {
    ajax,
    bind: bindAll,
    onBind,
    morph,
    form: { ...form, ...form_validation },
    dom,
    validate,
    storage,
    util,
    emit,
    t,

    /*
     * Assigning merges rather than replaces, so a page that translates three
     * messages keeps the English of the rest instead of showing keys.
     */
    get messages() {
      return messages;
    },
    set messages(value) {
      Object.assign(messages, value);
    },

    /**
     * Change the defaults and the messages in one call.
     *
     * @param {Object} options swapStrategy, debounceDelay, messages
     * @returns {Object} The defaults now in force
     */
    config: (options = {}) => {
      const { messages: text, ...rest } = options;

      if (text) Object.assign(messages, text);
      Object.assign(DEFAULTS, rest);

      return { ...DEFAULTS };
    },
  };
})();

// Make globally available
window.sf = sf;
