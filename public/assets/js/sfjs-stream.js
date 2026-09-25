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

    // Body can come from @body attribute (JSON) or from a parent form (serialized)
    let body = null;
    const bodyAttr = element.getAttribute('@body');

    if (bodyAttr) {
      try {
        body = JSON.parse(bodyAttr);
      } catch (e) {
        console.error('SFJS Stream: Invalid JSON in @body:', e.message);
        return;
      }
    }

    // If element is or is inside a form, serialize it as POST body
    const form = element.tagName === 'FORM' ? element : element.closest('form');
    if (form && !body && ['POST', 'PUT', 'PATCH'].includes(method)) {
      body = Object.fromEntries(new FormData(form));
    }

    if (!url || !targetEl) {
      console.warn('SFJS Stream: Missing @stream URL or @target element');
      return;
    }

    const isSSE = element.getAttribute('@sse') !== null || element.getAttribute('@hxsse') !== null;

    // Starting again replaces the run in progress: stop it, then start from
    // an empty target, so a second click repeats the stream instead of
    // mixing two of them in the same box.
    element.__sfStreamStop?.();
    targetEl.textContent = '';

    element.__sfStreamStop = isSSE
      ? handleSSE(url, targetEl, element, method, body)
      : handleTextStream(url, targetEl, element, method, body);
  }

  /**
   * Handle text streaming (append chunks)
   * @param {string} url The streaming endpoint
   * @param {Element} target The target element
   * @param {Element} element The original element with @stream (for @abort binding)
   * @param {string} method The HTTP method (GET, POST, etc)
   * @param {?Object} body The request body for POST/PUT/PATCH
   * @returns {Function} Stops the stream
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
            if (done) {
              result += decoder.decode();
              target.textContent = result;
              return;
            }

            const chunk = decoder.decode(value, { stream: true });
            result += chunk;
            target.textContent = result;

            // Scroll to end if target is scrollable
            if (target.scrollHeight > target.clientHeight) {
              target.scrollTop = target.scrollHeight;
            }

            readChunk();
          }).catch((error) => {
            if (error.name === 'AbortError') return;

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

    return () => controller.abort();
  }

  /**
   * Handle Server-Sent Events
   * @param {string} url The SSE endpoint
   * @param {Element} target The target element
   * @param {Element} element The original element with @stream (for @events/@abort binding)
   * @param {string} method The HTTP method (GET, POST, etc) — only GET works with EventSource
   * @param {?Object} body The request body for POST (must use fetch for SSE)
   * @returns {Function} Closes the connection
   */
  function handleSSE(url, target, element, method = 'GET', body = null) {
    let content = '';
    const eventTypes = element.getAttribute('@events')?.split(',').map(e => e.trim()) || [];

    // EventSource only supports GET. For POST, use fetch with manual SSE parsing.
    if (body || method !== 'GET') {
      return handleSSEviafetch(url, target, element, method, body);
    }

    const es = new EventSource(url);

    // Default message event
    es.addEventListener('message', (event) => {
      content += event.data + '\n';
      target.textContent = content;

      if (target.scrollHeight > target.clientHeight) {
        target.scrollTop = target.scrollHeight;
      }
    });

    // Handle custom event types (e.g., @events="progress,complete")
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

    return () => es.close();
  }

  /**
   * Handle SSE via fetch (supports POST)
   * @returns {Function} Stops the stream
   */
  function handleSSEviafetch(url, target, element, method, body) {
    const controller = new AbortController();
    let content = '';
    const eventTypes = element.getAttribute('@events')?.split(',').map(e => e.trim()) || [];
    let currentEvent = null; // Keep across chunks to handle events split between reads

    const headers = {
      'Accept': 'text/event-stream',
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

    if (body && ['POST', 'PUT', 'PATCH'].includes(method)) {
      headers['Content-Type'] = 'application/json';
      fetchOptions.body = JSON.stringify(body);
    }

    fetch(url, fetchOptions)
      .then((response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.body.getReader();
      })
      .then((reader) => {
        const decoder = new TextDecoder();
        let buffer = '';

        const readChunk = () => {
          reader.read().then(({ done, value }) => {
            if (!done) {
              buffer += decoder.decode(value, { stream: true });
            } else {
              // On stream end, decode any remaining bytes and flush buffer
              buffer += decoder.decode(); // Final flush
            }

            const lines = buffer.split('\n');
            buffer = done ? '' : lines.pop(); // On done, process all lines

            // Parse SSE lines
            lines.forEach((line) => {
              if (line.trim() === '') {
                // Blank line = end of event. Dispatch it.
                if (currentEvent) {
                  const eventName = currentEvent.event || 'message';
                  // Only show if no @events filter, or if this event type is in the list
                  if (!eventTypes.length || eventTypes.includes(eventName)) {
                    content += (eventName !== 'message' ? `[${eventName}] ` : '') + (currentEvent.data || '') + '\n';
                    target.textContent = content;

                    if (target.scrollHeight > target.clientHeight) {
                      target.scrollTop = target.scrollHeight;
                    }
                  }

                  currentEvent = null;
                }
              } else if (line.startsWith(':')) {
                // Comment (heartbeat) — ignore
              } else if (line.includes(':')) {
                // Parse "key: value" correctly: handle colons in values (e.g., data: 10:30)
                const colonIndex = line.indexOf(':');
                const key = line.slice(0, colonIndex).trim();
                let val = line.slice(colonIndex + 1);
                // Remove only leading space per SSE spec (not all trim)
                if (val.startsWith(' ')) val = val.slice(1);

                if (!currentEvent) currentEvent = {};

                // Multi-line data: values accumulate with newlines
                if (key === 'data') {
                  currentEvent.data = (currentEvent.data ? currentEvent.data + '\n' : '') + val;
                } else {
                  currentEvent[key] = val;
                }
              }
            });

            // Handle pending event at stream end
            if (done) {
              if (currentEvent) {
                const eventName = currentEvent.event || 'message';
                if (!eventTypes.length || eventTypes.includes(eventName)) {
                  content += (eventName !== 'message' ? `[${eventName}] ` : '') + (currentEvent.data || '') + '\n';
                  target.textContent = content;
                }
                currentEvent = null;
              }
              target.textContent += '\n\n[Connection closed]';
              return;
            }

            readChunk();
          }).catch((error) => {
            if (error.name !== 'AbortError') {
              console.error('SFJS SSE Error:', error);
              target.textContent += `\n\n[Error: ${error.message}]`;
            }
          });
        };

        readChunk();
      })
      .catch((error) => {
        if (error.name !== 'AbortError') {
          console.error('SFJS SSE Error:', error);
          target.textContent = `Error: ${error.message}`;
        }
      });

    return () => controller.abort();
  }

  /**
   * The event that starts a stream when @trigger is not given.
   *
   * A form streams on submit and something clickable streams on click; only
   * other elements start on their own, as the page loads. A submit listener
   * on a button inside a form would never fire, since submit is dispatched
   * on the form, so a button streams on click wherever it sits.
   *
   * @param {Element} element The element with @stream
   * @returns {string} The event name
   */
  function defaultTrigger(element) {
    if (element.tagName === 'FORM') return 'submit';

    if (element.matches('button, a, input[type="button"], input[type="submit"]')) return 'click';

    return 'load';
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

      const trigger = element.getAttribute('@trigger') || element.getAttribute('@hxtrigger') || defaultTrigger(element);

      // @abort names the element that stops whatever run is in progress.
      // Bound once here, not per run, so restarting does not stack listeners.
      const abortSelector = element.getAttribute('@abort');
      if (abortSelector) {
        document.querySelector(abortSelector)?.addEventListener('click', () => {
          element.__sfStreamStop?.();
          element.__sfStreamStop = null;
        });
      }

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
