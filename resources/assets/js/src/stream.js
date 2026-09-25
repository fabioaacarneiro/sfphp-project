/**
 * SFJS — streaming: the @stream and @sse attributes
 *
 *   <div @stream="/api/stream" @target="#output">Loading...</div>
 *   <div @stream="/api/sse" @sse @target="#events">Waiting for events...</div>
 *
 * Part of the SFJS bundle: the builder puts it after core.js, so `sf` is
 * always defined here. It used to be a file of its own that had to be loaded
 * second, and a page that got the order wrong failed with a console message
 * instead of working.
 */

(() => {
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

    // If element is or is inside a form, serialize it as POST body. Through
    // sf.form.serialize rather than Object.fromEntries, which kept only the
    // last of a repeated field: three ticked boxes arrived as one.
    const form = element.tagName === 'FORM' ? element : element.closest('form');
    if (form && !body && ['POST', 'PUT', 'PATCH'].includes(method)) {
      body = sf.form.serialize(form);
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

    /*
     * The target is a live region, so what arrives is read out — but only once
     * the run is over: aria-busy holds the announcement back, because a screen
     * reader reading every chunk of a token stream as it lands is noise, not
     * information. A page that set its own aria-live keeps it.
     */
    if (!targetEl.hasAttribute('aria-live')) targetEl.setAttribute('aria-live', 'polite');
    targetEl.setAttribute('aria-busy', 'true');

    const done = () => targetEl.removeAttribute('aria-busy');
    const stop = isSSE
      ? handleSSE(url, targetEl, element, method, body, done)
      : handleTextStream(url, targetEl, element, method, body, done);

    element.__sfStreamStop = () => {
      stop();
      done();
    };
  }

  /**
   * An error, in the page's language.
   *
   * @param {Error} error What went wrong
   * @returns {string}
   */
  function failed(error) {
    return sf.t('streamError', { error: error.message });
  }

  /**
   * Handle text streaming (append chunks)
   * @param {string} url The streaming endpoint
   * @param {Element} target The target element
   * @param {Element} element The original element with @stream (for @abort binding)
   * @param {string} method The HTTP method (GET, POST, etc)
   * @param {?Object} body The request body for POST/PUT/PATCH
   * @param {Function} done Called when the stream ends, however it ends
   * @returns {Function} Stops the stream
   */
  function handleTextStream(url, target, element, method = 'GET', body = null, done = () => {}) {
    const controller = new AbortController();
    const finish = done;

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
              finish();
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
            target.textContent = failed(error);
            finish();
          });
        };

        readChunk();
      })
      .catch((error) => {
        if (error.name !== 'AbortError') {
          console.error('SFJS Stream: Error', error);
          target.textContent = failed(error);
          finish();
        }
      });

    return () => controller.abort();
  }

  /**
   * Append one event to the target, keeping the end in view.
   *
   * @param {Element} target The target element
   * @param {string} name The event type
   * @param {string} data The event's data
   * @returns {void}
   */
  function show(target, name, data) {
    target.textContent += (name !== 'message' ? '[' + name + '] ' : '') + data + '\n';

    if (target.scrollHeight > target.clientHeight) {
      target.scrollTop = target.scrollHeight;
    }
  }

  /**
   * The event types that mean "that was the last one".
   *
   * EventSource reconnects on its own when a connection drops, which is what
   * makes it worth using — and it cannot tell a dropped connection from a
   * stream that finished, so a finite stream would start over forever. The
   * server says it is finished by sending one of these, @done="complete" by
   * name, or "done" and "complete" when nothing is named. Answering 204 on a
   * reconnect also stops it, as the SSE standard says.
   *
   * @param {Element} element The element with @stream
   * @returns {string[]}
   */
  function finalEvents(element) {
    const named = element.getAttribute('@done');

    return (named || 'done,complete').split(',').map((one) => one.trim()).filter(Boolean);
  }

  /**
   * Handle Server-Sent Events
   * @param {string} url The SSE endpoint
   * @param {Element} target The target element
   * @param {Element} element The original element with @stream (for @events/@abort binding)
   * @param {string} method The HTTP method (GET, POST, etc) — only GET works with EventSource
   * @param {?Object} body The request body for POST (must use fetch for SSE)
   * @param {Function} done Called when the stream ends, however it ends
   * @returns {Function} Closes the connection
   */
  function handleSSE(url, target, element, method = 'GET', body = null, done = () => {}) {
    const eventTypes = element.getAttribute('@events')?.split(',').map(e => e.trim()) || [];
    const finals = finalEvents(element);

    // EventSource only supports GET. For POST, use fetch with manual SSE parsing.
    if (body || method !== 'GET') {
      return handleSSEviafetch(url, target, element, method, body, done);
    }

    const es = new EventSource(url);

    const close = () => {
      es.close();
      target.textContent += '\n\n' + sf.t('streamClosed');
      done();
    };

    // Default message event
    es.addEventListener('message', (event) => show(target, 'message', event.data));

    // Handle custom event types (e.g., @events="progress,complete"), and the
    // final ones, which close the connection after they are shown.
    new Set([...eventTypes, ...finals]).forEach((eventType) => {
      es.addEventListener(eventType, (event) => {
        if (eventTypes.includes(eventType)) show(target, eventType, event.data);
        if (finals.includes(eventType)) close();
      });
    });

    /*
     * An error while the state is CONNECTING is the browser retrying, and is
     * left alone: closing here, as this used to, turned every network blip
     * into a dead stream. CLOSED means the browser gave up — a refused
     * connection, a wrong content type, a 204 — and that is the end.
     */
    es.addEventListener('error', () => {
      if (!element.isConnected) {
        es.close();
        done();

        return;
      }

      if (es.readyState === EventSource.CLOSED) close();
    });

    return () => es.close();
  }

  /**
   * Handle SSE via fetch (supports POST)
   * @returns {Function} Stops the stream
   */
  function handleSSEviafetch(url, target, element, method, body, done = () => {}) {
    const controller = new AbortController();
    const eventTypes = element.getAttribute('@events')?.split(',').map(e => e.trim()) || [];
    const finish = done;
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

    // Only show if no @events filter, or if this event type is in the list
    const dispatch = (event) => {
      const eventName = event.event || 'message';

      if (!eventTypes.length || eventTypes.includes(eventName)) show(target, eventName, event.data || '');
    };

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
                  dispatch(currentEvent);
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
                dispatch(currentEvent);
                currentEvent = null;
              }
              target.textContent += '\n\n' + sf.t('streamClosed');
              finish();
              return;
            }

            readChunk();
          }).catch((error) => {
            if (error.name !== 'AbortError') {
              console.error('SFJS SSE Error:', error);
              target.textContent += '\n\n[' + failed(error) + ']';
              finish();
            }
          });
        };

        readChunk();
      })
      .catch((error) => {
        if (error.name !== 'AbortError') {
          console.error('SFJS SSE Error:', error);
          target.textContent = failed(error);
          finish();
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
   * Read "delay:300ms" and friends the way sfjs.js reads them, so @trigger
   * means the same thing on a stream as on a request.
   *
   * @param {string} text A period such as "300ms", "2s" or "1m"
   * @returns {number} Milliseconds
   */
  function readPeriod(text) {
    const match = /^([0-9.]+)(ms|s|m)?$/.exec((text || '').trim().toLowerCase());

    if (!match) return 0;

    const unit = match[2] || 's';

    return parseFloat(match[1]) * (unit === 'ms' ? 1 : unit === 'm' ? 60000 : 1000);
  }

  const selector = '[\\@stream], [\\@hxstream]';

  /**
   * Bind streaming handlers to elements
   * @param {Document|Element} root The DOM root to search
   */
  function bindStreaming(root) {
    const found = Array.from(root.querySelectorAll(selector));

    // The root itself: an element added on its own is a root with no match inside.
    if (root.matches && root.matches(selector)) found.push(root);

    found.forEach((element) => {
      if (element.__sfStreamBound) return;
      element.__sfStreamBound = true;

      const spec = element.getAttribute('@trigger') || element.getAttribute('@hxtrigger') || defaultTrigger(element);
      const words = spec.trim().split(/\s+/);
      const trigger = words[0].toLowerCase();
      const delayWord = words.find((word) => word.toLowerCase().startsWith('delay:'));
      const delay = delayWord ? readPeriod(delayWord.slice('delay:'.length)) : 0;

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
        if (delay > 0) setTimeout(() => { if (element.isConnected) handleStream(element); }, delay);
        else handleStream(element);
      } else {
        // Support other triggers like click, input, etc.
        const fire = delay > 0 ? sf.util.debounce(() => handleStream(element), delay) : () => handleStream(element);
        element.addEventListener(trigger, (e) => {
          if (trigger === 'submit' || trigger === 'click') e.preventDefault();
          fire();
        });
      }
    });
  }

  /**
   * Stop the streams of elements that have left the page.
   *
   * A stream whose element a swap removed kept its connection open and kept
   * writing into a node nobody could see, and the server kept producing for
   * it. isConnected is checked rather than trusting the removal, because a
   * morph moves nodes by removing and re-inserting them.
   *
   * @param {Node} node A removed node
   * @returns {void}
   */
  function stopRemoved(node) {
    const found = Array.from(node.querySelectorAll(selector));

    if (node.matches(selector)) found.push(node);

    found.forEach((element) => {
      if (element.isConnected || !element.__sfStreamStop) return;

      element.__sfStreamStop();
      element.__sfStreamStop = null;
    });
  }

  /*
   * Bound when the document is ready, not when the script runs: loaded in the
   * <head>, document.body does not exist yet, and observing it threw — which
   * took the whole extension down before it bound a single element.
   */
  sf.dom.ready(() => {
    bindStreaming(document);

    // Also bind dynamically added content, and stop what was taken away.
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === 1) bindStreaming(node);
        });

        mutation.removedNodes.forEach((node) => {
          if (node.nodeType === 1) stopRemoved(node);
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true,
    });
  });
})();
