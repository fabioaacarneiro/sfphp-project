/**
 * SFJS — Simple Framework JavaScript Library
 * HTMX-like AJAX, forms, and DOM utilities without dependencies
 * ~8KB minified
 */

const sf = (() => {
  const SELECTORS = {
    AJAX_GET: '[\\@hxGet]',
    AJAX_POST: '[\\@hxPost]',
    AJAX_PUT: '[\\@hxPut]',
    AJAX_DELETE: '[\\@hxDelete]',
    AJAX_PATCH: '[\\@hxPatch]',
    FORM_VALIDATE: '[\\@validate]',
    TOGGLE: '[\\@toggle]',
  };

  const DEFAULTS = {
    swapStrategy: 'innerHTML',
    validateOn: 'blur',
    debounceDelay: 300,
  };

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
      default:
        target.innerHTML = content;
    }
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
      const declarative = Array.from(formElement.attributes || []).find((attr) =>
        /^@hx(get|post|put|delete|patch)$/.test(attr.name.toLowerCase())
      );

      const method = declarative
        ? declarative.name.toLowerCase().replace('@hx', '').toUpperCase()
        : (formElement.getAttribute('method') || 'POST').toUpperCase();

      const action = (declarative && declarative.value)
        || formElement.getAttribute('action')
        || '';
      const swapTarget = formElement.getAttribute('@hxTarget');
      const swapStrategy = formElement.getAttribute('@hxSwap') || 'innerHTML';

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

  // ========== AUTO-INITIALIZATION ==========

  function init() {
    // @hxGet, @hxPost, etc
    document.addEventListener('click', (e) => {
      const target = e.target.closest('[\\@hxGet], [\\@hxPost], [\\@hxPut], [\\@hxDelete], [\\@hxPatch]');
      if (!target) return;

      /*
       * A form is driven by its submit event, which is the only place the
       * fields are serialised. Clicking its submit button used to arrive here
       * first — closest() walks up — and this prevented the default, so the
       * submit never fired and the bare action was fetched with nothing the
       * visitor had typed.
       */
      if (target.tagName === 'FORM') return;

      e.preventDefault();

      /*
       * HTML lowercases attribute names, so an attribute written as "@hxGet"
       * is read back as "@hxget". Matching case-sensitively against
       * /^@hx(Get|Post)/ never succeeded, which is why the declarative
       * attributes did nothing in an HTML document. The name is compared in
       * lower case, and the attribute is read back by its real name.
       */
      const attribute = Array.from(target.attributes).find((attr) =>
        /^@hx(get|post|put|delete|patch)$/.test(attr.name.toLowerCase())
      );

      if (!attribute) return;

      const httpMethod = attribute.name.toLowerCase().replace('@hx', '');
      const url = attribute.value;
      const swapTarget = target.getAttribute('@hxTarget');
      const swapStrategy = target.getAttribute('@hxSwap') || 'innerHTML';

      if (!url) return;

      ajax[httpMethod](url, {
        target: swapTarget,
        swap: swapStrategy,
      });
    });

    // Form submit with @hxPost
    document.addEventListener('submit', (e) => {
      const declarative = Array.from(e.target.attributes || []).some((attr) =>
        /^@hx(get|post|put|delete|patch)$/.test(attr.name.toLowerCase())
      );

      if (!declarative) return;

      e.preventDefault();
      form.submit(e.target);
    });

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
    form: { ...form, ...form_validation },
    dom,
    validate,
    storage,
    util,
  };
})();

// Make globally available
window.sf = sf;
