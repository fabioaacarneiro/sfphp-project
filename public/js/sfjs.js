/**
 * SFJS — Simple Framework JavaScript Library
 * HTMX + Alpine.js hybrid: AJAX, reactivity, forms, DOM utilities without dependencies
 * ~12KB minified
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
    DATA: '[\\@data]',
  };

  const DEFAULTS = {
    swapStrategy: 'innerHTML',
    validateOn: 'blur',
    debounceDelay: 300,
  };

  // ========== REACTIVE DATA (Alpine.js-like) ==========

  const reactive = {
    components: new Map(),

    create: (element, data = {}) => {
      const component = reactive.createProxy(data, element);
      reactive.components.set(element, component);
      reactive.bindComponent(element, component);
      return component;
    },

    createProxy: (data, element) => {
      return new Proxy(data, {
        set: (target, property, value) => {
          target[property] = value;
          reactive.update(element);
          return true;
        },
        get: (target, property) => target[property],
      });
    },

    bindComponent: (element, data) => {
      // x-text: Set text content
      element.querySelectorAll('[\\@text]').forEach(el => {
        const expr = el.getAttribute('\\@text');
        el.textContent = reactive.evaluate(expr, data);
      });

      // x-html: Set HTML content
      element.querySelectorAll('[\\@html]').forEach(el => {
        const expr = el.getAttribute('\\@html');
        el.innerHTML = reactive.evaluate(expr, data);
      });

      // x-show: Toggle visibility
      element.querySelectorAll('[\\@show]').forEach(el => {
        const expr = el.getAttribute('\\@show');
        const visible = reactive.evaluate(expr, data);
        el.style.display = visible ? '' : 'none';
      });

      // x-if: Conditional rendering (simplified)
      element.querySelectorAll('[\\@if]').forEach(el => {
        const expr = el.getAttribute('\\@if');
        const show = reactive.evaluate(expr, data);
        if (el._sfPlaceholder === undefined) {
          el._sfPlaceholder = document.createComment(`@if: ${expr}`);
        }
        if (!show && el.parentNode) {
          el.parentNode.replaceChild(el._sfPlaceholder, el);
        } else if (show && el._sfPlaceholder.parentNode) {
          el._sfPlaceholder.parentNode.replaceChild(el, el._sfPlaceholder);
        }
      });

      // x-model: Two-way binding
      element.querySelectorAll('[\\@model]').forEach(el => {
        const key = el.getAttribute('\\@model');
        el.value = data[key] ?? '';
        el.addEventListener('input', (e) => {
          data[key] = e.target.value;
        });
      });
    },

    update: (element) => {
      const data = reactive.components.get(element);
      if (data) {
        reactive.bindComponent(element, data);
      }
    },

    evaluate: (expr, data) => {
      try {
        return new Function(...Object.keys(data), `return ${expr}`)(...Object.values(data));
      } catch (e) {
        console.error('SFJS evaluate error:', e);
        return expr;
      }
    },
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
      const method = (formElement.getAttribute('method') || 'POST').toUpperCase();
      const action = formElement.getAttribute('action') || '';
      const swapTarget = formElement.getAttribute('\\@hxTarget');
      const swapStrategy = formElement.getAttribute('\\@hxSwap') || 'innerHTML';

      return ajax[method.toLowerCase()](action, data, {
        target: swapTarget || null,
        swap: swapStrategy,
      });
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
      const rule = element.getAttribute('\\@validate');
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
    // @data: Initialize reactive components
    document.querySelectorAll('[\\@data]').forEach(el => {
      const dataStr = el.getAttribute('\\@data');
      try {
        const data = new Function(`return ${dataStr}`)();
        reactive.create(el, data);
      } catch (e) {
        console.error('SFJS @data error:', e);
      }
    });

    // @xInit: Run initialization code
    document.querySelectorAll('[\\@init]').forEach(el => {
      const initFn = el.getAttribute('\\@init');
      try {
        new Function(initFn)();
      } catch (e) {
        console.error('SFJS @init error:', e);
      }
    });

    // @hxGet, @hxPost, etc
    document.addEventListener('click', (e) => {
      const target = e.target.closest('[\\@hxGet], [\\@hxPost], [\\@hxPut], [\\@hxDelete], [\\@hxPatch]');
      if (!target) return;

      e.preventDefault();

      const method = Object.keys(target.attributes).find(key =>
        target.attributes[key].nodeName.match(/^@hx(Get|Post|Put|Delete|Patch)$/)
      );

      if (!method) return;

      const httpMethod = method.replace('@hx', '').toLowerCase();
      const url = target.getAttribute(`@hx${method.replace('@hx', '')}`);
      const swapTarget = target.getAttribute('@hxTarget');
      const swapStrategy = target.getAttribute('@hxSwap') || 'innerHTML';

      ajax[httpMethod](url, {
        target: swapTarget,
        swap: swapStrategy,
      });
    });

    // Form submit with @hxPost
    document.addEventListener('submit', (e) => {
      if (!e.target.hasAttribute('\\@hxPost')) return;
      e.preventDefault();
      form.submit(e.target);
    });

    // Validation on blur/change
    document.addEventListener('blur', (e) => {
      if (!e.target.hasAttribute('\\@validate')) return;

      const rule = e.target.getAttribute('\\@validate');
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

      const toggleId = target.getAttribute('\\@toggle');
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
    reactive,
  };
})();

// Make globally available
window.sf = sf;
