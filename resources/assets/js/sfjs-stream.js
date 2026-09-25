/**
 * SFJS Streaming Extension
 * Adds @stream attribute for receiving streamed responses in the browser
 *
 * Usage:
 *   <div @stream="/api/stream" @target="#output">Loading...</div>
 *   <div @stream="/api/sse" @target="#events">Waiting for events...</div>
 *
 * Requires: sfjs.js (loaded first)
 */

(() => {
  if (typeof sf === 'undefined') {
    console.error('SFJS Stream: sfjs.js must be loaded before sfjs-stream.js');
    return;
  }

  /**
   * Handle streaming responses
   * @param {Element} element The element with @stream
   */
  function handleStream(element) {
    const url = element.getAttribute('@stream') || element.getAttribute('@hxstream');
    const target = element.getAttribute('@target') || element.getAttribute('@hxtarget');
    const targetEl = target ? document.querySelector(target) : element;
    const method = (element.getAttribute('@method') || 'GET').toUpperCase();
    const body = element.getAttribute('@body') ? JSON.parse(element.getAttribute('@body')) : null;

    if (!url || !targetEl) {
      console.warn('SFJS Stream: Missing @stream URL or @target element');
      return;
    }

    const isSSE = element.getAttribute('@sse') !== null || element.getAttribute('@hxsse') !== null;

    if (isSSE) {
      handleSSE(url, targetEl, element);
    } else {
      handleTextStream(url, targetEl, element, method, body);
    }
  }

  /**
   * Handle text streaming (append chunks)
   * @param {string} url The streaming endpoint
   * @param {Element} target The target element
   * @param {Element} element The original element with @stream (for @abort binding)
   * @param {string} method The HTTP method (GET, POST, etc)
   * @param {?Object} body The request body for POST/PUT/PATCH
   */
  function handleTextStream(url, target, element, method = 'GET', body = null) {
    const controller = new AbortController();

    const headers = {
      'X-Requested-With': 'XMLHttpRequest',
    };

    // Add CSRF token for state-changing requests
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      if (token) {
        headers['X-CSRF-Token'] = token;
      }
    }

    const fetchOptions = {
      method,
      signal: controller.signal,
      headers,
    };

    // Add body for state-changing requests
    if (body && ['POST', 'PUT', 'PATCH'].includes(method)) {
      headers['Content-Type'] = 'application/json';
      fetchOptions.body = JSON.stringify(body);
    }

    fetch(url, fetchOptions)
      .then((response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.body;
      })
      .then((body) => {
        if (!body) throw new Error('Response has no body');

        const reader = body.getReader();
        const decoder = new TextDecoder();
        let result = '';

        const readChunk = () => {
          reader.read().then(({ done, value }) => {
            if (done) return;

            const chunk = decoder.decode(value, { stream: true });
            result += chunk;
            target.textContent = result;

            // Scroll to end if target is scrollable
            if (target.scrollHeight > target.clientHeight) {
              target.scrollTop = target.scrollHeight;
            }

            readChunk();
          }).catch((error) => {
            console.error('SFJS Stream: Error reading chunk', error);
            target.textContent = `Error: ${error.message}`;
          });
        };

        readChunk();
      })
      .catch((error) => {
        if (error.name !== 'AbortError') {
          console.error('SFJS Stream: Error', error);
          target.textContent = `Error: ${error.message}`;
        }
      });

    // Allow aborting via @abort attribute
    const abortBtn = element.getAttribute('@abort');
    if (abortBtn) {
      document.querySelector(abortBtn)?.addEventListener('click', () => {
        controller.abort();
      });
    }
  }

  /**
   * Handle Server-Sent Events
   * @param {string} url The SSE endpoint
   * @param {Element} target The target element
   * @param {Element} element The original element with @stream (for @events/@abort binding)
   */
  function handleSSE(url, target, element) {
    const es = new EventSource(url);
    let content = '';

    // Default message event
    es.addEventListener('message', (event) => {
      content += event.data + '\n';
      target.textContent = content;

      if (target.scrollHeight > target.clientHeight) {
        target.scrollTop = target.scrollHeight;
      }
    });

    // Handle custom event types (e.g., @event="progress")
    const eventTypes = element.getAttribute('@events')?.split(',').map(e => e.trim()) || [];
    eventTypes.forEach((eventType) => {
      es.addEventListener(eventType, (event) => {
        content += `[${eventType}] ${event.data}\n`;
        target.textContent = content;

        if (target.scrollHeight > target.clientHeight) {
          target.scrollTop = target.scrollHeight;
        }
      });
    });

    es.addEventListener('error', () => {
      es.close();
      target.textContent += '\n\n[Connection closed]';
    });

    // Allow closing via @abort attribute
    const abortBtn = element.getAttribute('@abort');
    if (abortBtn) {
      document.querySelector(abortBtn)?.addEventListener('click', () => {
        es.close();
      });
    }
  }

  /**
   * Bind streaming handlers to elements
   * @param {Document|Element} root The DOM root to search
   */
  function bindStreaming(root) {
    const selector = '[\\@stream], [\\@hxstream]';

    root.querySelectorAll(selector).forEach((element) => {
      if (element.__sfStreamBound) return;
      element.__sfStreamBound = true;

      const trigger = element.getAttribute('@trigger') || element.getAttribute('@hxtrigger') || 'load';

      if (trigger === 'load') {
        handleStream(element);
      } else {
        // Support other triggers like click, input, etc.
        const fire = () => handleStream(element);
        element.addEventListener(trigger, (e) => {
          if (trigger === 'submit' || trigger === 'click') e.preventDefault();
          fire();
        });
      }
    });
  }

  // Bind on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      bindStreaming(document);
    });
  } else {
    bindStreaming(document);
  }

  // Also bind dynamically added content
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (mutation.type === 'childList') {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === 1) { // Element node
            bindStreaming(node);
          }
        });
      }
    });
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true,
  });
})();
