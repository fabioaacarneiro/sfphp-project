/**
 * SFJS — Simple Framework JavaScript Library
 * HTMX-like AJAX, forms, and DOM utilities without dependencies
 * ~21KB minified, 6.0KB gzipped
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
    const { data = {}, target = null, swap = DEFAULTS.swapStrategy, onSuccess = null, onError = null } = options;

    const headers = {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };

    // Add CSRF token for state-changing requests
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      if (token) {
        headers['X-CSRF-Token'] = token;
      }
    }

    return fetch(url, {
      method,
      headers,
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
        morph(target, content);
    }

    /*
     * What arrived may declare triggers of its own — a fragment that refreshes
     * itself, a form inside a panel. Without this it would be inert, and the
     * page would work once and then stop, which is the kind of bug people
     * describe as "it only updates the first time".
     */
    bindTriggers(target.parentNode || document);
    bindState(target.parentNode || document);
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
      const swapStrategy = (declared ? declared.swap : attributeOf(formElement, 'swap')) || DEFAULTS.swapStrategy;

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

  /*
   * The same rule names the server validates with, so a form says one thing
   * once. The browser's answer is a convenience — anybody can skip it with a
   * request of their own — and the server's is the one that counts, which is
   * why the two lists have to agree: a field the browser accepts and the
   * server rejects is a form that fails after it looked fine.
   */
  const validate = {
    required: (value) => String(value).trim().length > 0,
    email: (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
    number: (value) => /^[0-9]+$/.test(value),
    alpha: (value) => /^\p{L}+$/u.test(value),
    alphanum: (value) => /^[\p{L}\p{N}]+$/u.test(value),
    minLength: (value, min) => [...String(value)].length >= Number(min),
    maxLength: (value, max) => [...String(value)].length <= Number(max),
    /*
     * min and max follow the value, as they do on the server: a number is
     * compared, anything else is counted. "age must be at least 18" and "name
     * must be at least 3 characters" are both what somebody meant when they
     * wrote it.
     */
    min: (value, bound) => (isNumeric(value)
      ? Number(value) >= Number(bound)
      : [...String(value)].length >= Number(bound)),
    max: (value, bound) => (isNumeric(value)
      ? Number(value) <= Number(bound)
      : [...String(value)].length <= Number(bound)),
    pattern: (value, regex) => new RegExp(regex, 'u').test(value),
    url: (value) => {
      try {
        new URL(value);
        return true;
      } catch {
        return false;
      }
    },
  };

  /**
   * Whether a value is a number, the way the server decides it.
   *
   * @param {*} value The value
   * @returns {boolean}
   */
  function isNumeric(value) {
    return String(value).trim() !== '' && !Number.isNaN(Number(value));
  }

  const form_validation = {
    validate: (element) => {
      const spec = element.getAttribute('@validate');
      const value = element.value;

      if (!spec) return true;

      /*
       * Rules are pipe separated and an argument comes after a colon, the
       * same as on the server. The argument used to be dropped: the whole
       * "minLength:5" was looked up as a rule name, not found, and the field
       * passed — so three of the documented rules never did anything.
       */
      return spec.split('|').every((one) => {
        const at = one.indexOf(':');
        const name = (at === -1 ? one : one.slice(0, at)).trim();
        const argument = at === -1 ? null : one.slice(at + 1);

        if (!validate[name]) {
          console.error('SFJS: @validate does not know "' + name + '"');

          return true;
        }

        return validate[name](value, argument);
      });
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
          if (value === null || value === undefined) return undefined;
          value = value[step];
        }

        return value;
      }
      case 'ternary':
        return evaluate(node.test, scope) ? evaluate(node.yes, scope) : evaluate(node.no, scope);
      case 'assign': {
        const value = evaluate(node.value, scope);
        let holder = scope;

        for (let i = 0; i < node.path.length - 1; i++) holder = holder[node.path[i]];

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
          bindings.push(() => {
            element.style.display = read(source, state) ? '' : 'none';
          });
        }

        if (name === '@class') {
          const fixed = element.getAttribute('class') || '';

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
            element.addEventListener('input', () => {
              write(path, element.type === 'checkbox' ? element.checked : element.value, state);
            });
          }

          bindings.push(() => {
            const value = read(path, state);

            if (element.type === 'checkbox') {
              element.checked = !!value;

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

    const options = { target: declared.target, swap: declared.swap };

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
          if (delay > 0) setTimeout(() => send(element), delay);
          else send(element);

          return;
        }

        if (name === 'every') {
          const period = readPeriod(argument);

          if (period <= 0) {
            console.error('SFJS: @trigger="every" needs a period, such as "every:10s"');

            return;
          }

          // delay: postpones the first run, so ten panels do not all fire at once.
          const start = () => setInterval(() => send(element), period);

          if (delay > 0) setTimeout(start, delay);
          else start();

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
    bindState(document);

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
    bind: (root) => { bindTriggers(root); bindState(root); },
    morph,
    form: { ...form, ...form_validation },
    dom,
    validate,
    storage,
    util,
  };
})();

// Make globally available
window.sf = sf;
