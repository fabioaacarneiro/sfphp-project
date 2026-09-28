<?php

/*
 * SFJS, in the browser and as a build.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Assets;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Validator;

$tests->run('the minified script is still a program, and still the same one', function () use ($tests): void {
    /*
     * A minifier that is wrong produces a file that looks fine in a directory
     * listing and breaks every page that loads it. SFJS has no regex literals,
     * which is what makes a minifier this small safe — and this is what would
     * notice if that stopped being true.
     */
    $readable = Assets::path() . '/js/sfjs.js';
    $minified = Assets::path() . '/js/sfjs.min.js';

    $tests->assertSame(true, is_file($minified));
    $tests->assertSame(true, filesize($minified) < filesize($readable));

    // Comments went, the code did not.
    $source = file_get_contents($minified);
    $tests->assertSame(false, str_contains($source, 'HTMX-like AJAX'));
    $tests->assertSame(true, str_contains($source, 'const sf'));

    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        // No JavaScript engine here; the CI runner has one.
        return;
    }

    /*
     * Both files, because only the minified one used to be checked — and a
     * mistake in the readable source is a mistake in every copy of it. One
     * arrived this way: a const that redeclared the parameter it sat next to.
     */
    foreach ([$readable, $minified] as $script) {
        $status = 0;
        $output = [];
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $tests->assertSame(0, $status);
    }
});

$tests->run('the state helper encodes values an attribute can carry', function () use ($tests): void {
    /*
     * A plain string on purpose. {{ }} escapes it, so the quotes JSON needs
     * become entities inside the attribute and the browser hands them back
     * intact. Returning Sfht would put raw quotes in an attribute, which is
     * how markup breaks — or, with a value that came from a visitor, how an
     * attribute is forged.
     */
    $tests->assertSame('{"open":false,"items":[1,2]}', state(['open' => false, 'items' => [1, 2]]));

    // Unicode and slashes stay readable rather than turning into escapes.
    $tests->assertSame('{"name":"José","path":"a/b"}', state(['name' => 'José', 'path' => 'a/b']));

    // Printed with {{ }}, every quote becomes an entity — attribute-safe.
    $tests->assertSame(
        '{&quot;a&quot;:&quot;b&quot;}',
        SfphpProject\src\View\Compiler::text(state(['a' => 'b']))
    );
});

$tests->run('client state survives a refresh that came from the server', function () use ($tests): void {
    /*
     * The two halves of the feature meet here, and they disagreed. A panel
     * that refreshes itself is morphed against the markup the server sent,
     * which replaces the nodes the bindings pointed at — so afterwards the
     * state said "closed" while the page showed "open", silently, which is
     * worse than either failing.
     *
     * The scope now collects its bindings again, keeping the state it had,
     * and an element that survived the swap does not end up listening twice.
     */

    $log = sfjsInBrowser(
        <<<HTML
        <div id="panel" \x40state="{ open: true, n: 0 }" \x40get="/fragment" \x40trigger="load"
             \x40target="#panel" \x40swap="morph">
          <span id="mark">old</span>
          <p id="body" \x40show="open">visible</p>
          <button id="plus" \x40on:click="n = n + 1">+</button>
          <span id="count" \x40text="n"></span>
        </div>
        HTML,
        <<<HTML
          window.fetch = () => Promise.resolve({
            ok: true, status: 200,
            text: () => Promise.resolve(
              '<span id="mark">new</span>'
              + '<p id="body" \x40show="open">visible</p>'
              + '<button id="plus" \x40on:click="n = n + 1">+</button>'
              + '<span id="count" \x40text="n"></span>'
            )
          });
        HTML,
        <<<HTML
          window.addEventListener('load', async () => {
            const q = (id) => document.getElementById(id);

            // Close it before the server's answer arrives.
            q('panel').__sfState.open = false;

            await new Promise((resolve) => setTimeout(resolve, 300));

            q('plus').click();

            report([
              'mark=' + q('mark').textContent,
              'hidden=' + q('body').hidden,
              'state=' + q('panel').__sfState.open,
              'clicks=' + q('count').textContent
            ].join(' | '));
          });
        HTML
    );

    if ($log === null) {
        return;
    }

    // The server's markup did arrive.
    $tests->assertTrue(str_contains($log, 'mark=new'));

    // And the state the visitor had set is still in force afterwards.
    $tests->assertTrue(str_contains($log, 'hidden=true'));
    $tests->assertTrue(str_contains($log, 'state=false'));

    // One click counts once: the swap did not leave a second listener.
    $tests->assertTrue(str_contains($log, 'clicks=1'));
});

$tests->run('a scope holds state in the browser, and the page follows it', function () use ($tests): void {
    /*
     * Interface state — open, selected, half-typed — belongs in the page:
     * asking a server whether a menu is open spends thirty milliseconds on a
     * decision that takes none. This is the whole feature in one harness,
     * because none of it is observable from PHP.
     *
     * The expressions are parsed rather than eval()'d, so this also stands as
     * the check that the grammar covers what the documentation promises.
     */
    $initial = state(['open' => false, 'name' => '', 'items' => 3, 'user' => null, 'busy' => false]);
    $initial = htmlspecialchars($initial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $log = sfjsInBrowser(
        <<<HTML
        <div \x40state="{$initial}">
          <button id="toggle" \x40on:click="open = !open">Toggle</button>
          <div id="panel" \x40show="open">visible</div>
          <input id="field" \x40model="name">
          <span id="greeting" \x40text="'Hello, ' + name"></span>
          <span id="doubled" \x40text="items * 2"></span>
          <span id="styled" class="base" \x40class="open ? 'on' : 'off'"></span>
          <button id="load" \x40get="/api/user" \x40into="user" \x40loading="busy">Load</button>
          <span id="loaded" \x40text="user.name"></span>
          <span id="busy" \x40text="busy ? 'busy' : 'idle'"></span>
        </div>
        HTML,
        <<<HTML
          window.fetch = () => new Promise((resolve) => setTimeout(() => resolve({
            ok: true, status: 200, text: () => Promise.resolve('{"name":"Ana"}')
          }), 30));
        HTML,
        <<<HTML
          window.addEventListener('load', async () => {
            const q = (id) => document.getElementById(id);
            const steps = [];

            steps.push('hidden=' + q('panel').hidden);
            steps.push('class=' + q('styled').className);

            q('toggle').click();
            steps.push('shown=' + !q('panel').hidden);
            steps.push('class2=' + q('styled').className);

            q('field').value = 'Fabio';
            q('field').dispatchEvent(new Event('input'));
            steps.push('greeting=' + q('greeting').textContent);
            steps.push('doubled=' + q('doubled').textContent);

            q('load').click();
            steps.push('during=' + q('busy').textContent);
            await new Promise((resolve) => setTimeout(resolve, 300));
            steps.push('loaded=' + q('loaded').textContent);
            steps.push('after=' + q('busy').textContent);

            report(steps.join(' | '));
          });
        HTML
    );

    if ($log === null) {
        return;
    }

    // @show follows a boolean through the hidden attribute, and @on:click can flip it.
    $tests->assertTrue(str_contains($log, 'hidden=true'));
    $tests->assertTrue(str_contains($log, 'shown=true'));

    // @class adds to the element's own classes rather than replacing them.
    $tests->assertTrue(str_contains($log, 'class=base off'));
    $tests->assertTrue(str_contains($log, 'class2=base on'));

    // @model writes into the state, and @text reads an expression back.
    $tests->assertTrue(str_contains($log, 'greeting=Hello, Fabio'));
    $tests->assertTrue(str_contains($log, 'doubled=6'));

    // @into puts the answer in the state; @loading brackets the request.
    $tests->assertTrue(str_contains($log, 'during=busy'));
    $tests->assertTrue(str_contains($log, 'loaded=Ana'));
    $tests->assertTrue(str_contains($log, 'after=idle'));
});

$tests->run('morph updates a panel without throwing away what is being typed', function () use ($tests): void {
    /*
     * Why morph is the default rather than an option. A panel that refreshes on
     * a period contains a form somebody is filling in; replacing the markup
     * throws away the focus, the caret and anything typed and not yet sent, and
     * nothing warns anybody. This asks for no strategy at all, so a change of
     * default is what it would catch. Measured against innerHTML by hand, the same swap loses
     * the focus, the caret and the text; here the strategy is asserted on its
     * own, because two panels in one page end up with duplicate ids and the
     * assertions start reading the wrong element.
     */

    $log = sfjsInBrowser(
        <<<HTML
        <div id="morphed"><h2 id="heading">Count: 1</h2><input id="field" name="note" value=""></div>
        HTML,
        <<<HTML
          window.fetch = () => Promise.resolve({
            ok: true, status: 200,
            text: () => Promise.resolve('<h2 id="heading">Count: 2</h2><input id="field" name="note" value="">')
          });
        HTML,
        <<<HTML
          window.addEventListener('load', async () => {
            const headingBefore = document.getElementById('heading');
            const field = document.getElementById('field');
            field.focus(); field.value = 'typing'; field.setSelectionRange(3, 3);

            // No swap named on purpose: the default is what is under test.
            await sf.req.get('/x', { target: '#morphed' });

            report([
              'text=' + document.getElementById('heading').textContent,
              'sameNode=' + (headingBefore === document.getElementById('heading')),
              'focus=' + ((document.activeElement || {}).id || 'lost'),
              'typed=' + document.getElementById('field').value,
              'caret=' + document.getElementById('field').selectionStart
            ].join(' | '));
          });
        HTML
    );

    if ($log === null) {
        return;
    }

    // What the server sent did arrive.
    $tests->assertTrue(str_contains($log, 'text=Count: 2'));

    // And the node itself was kept rather than rebuilt.
    $tests->assertTrue(str_contains($log, 'sameNode=true'));

    // Which is why the visitor keeps the focus, the text and the caret.
    $tests->assertTrue(str_contains($log, 'focus=field'));
    $tests->assertTrue(str_contains($log, 'typed=typing'));
    $tests->assertTrue(str_contains($log, 'caret=3'));
});

$tests->run('an element can say when it fires, and a field sends itself', function () use ($tests): void {
    /*
     * @trigger is what turns "click this" into "keep this current": a panel
     * that loads itself and refreshes on a period, a search box that asks as
     * somebody types. None of it is observable from PHP, so this drives a real
     * browser and steps aside where there is not one.
     */

    $log = sfjsInBrowser(
        <<<HTML
        <div id="panel" \x40get="/tick" \x40trigger="load, every:200ms"></div>
        <input id="search" name="q" value="abc" \x40get="/search" \x40target="#out" \x40trigger="input delay:50ms">
        <form id="form" \x40post="/save" \x40target="#out" \x40trigger="submit">
          <input name="title" value="hello"><button type="submit">go</button>
        </form>
        <div id="out"></div>
        HTML,
        <<<HTML
          const calls = [];
          window.fetch = (url, init) => {
            calls.push(((init && init.method) || 'GET') + ' ' + url);
            return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('<b>swapped</b>') });
          };
        HTML,
        <<<HTML
          window.addEventListener('load', () => {
            document.querySelector('#form button').click();
            const field = document.getElementById('search');
            field.value = 'xyz';
            field.dispatchEvent(new Event('input'));
            setTimeout(() => { report(calls.join(' | ')); }, 700);
          });
        HTML
    );

    if ($log === null) {
        return;
    }

    // load fired once, and the period kept firing after it.
    $tests->assertTrue(substr_count($log, 'GET /tick') >= 3);

    // The form sent its fields through the new attribute name.
    $tests->assertTrue(str_contains($log, 'POST /save'));

    // The field sent what was typed, debounced into one request.
    $tests->assertTrue(str_contains($log, 'GET /search?q=xyz'));
    $tests->assertSame(1, substr_count($log, 'GET /search'));
});

$tests->run('a declarative form sends the fields a visitor typed', function () use ($tests): void {
    /*
     * Two bugs lived here, and neither was visible from PHP. The click handler
     * walks up from whatever was clicked, so a submit button found the form,
     * prevented the default and fetched the bare action — the submit event,
     * which is the only place fields are serialised, never fired. And when it
     * did fire, form.submit() called GET with the three-argument shape the
     * other verbs use, so the fields arrived as the options object.
     *
     * A real browser is the only honest way to check that, so this runs one
     * when there is one and steps aside when there is not.
     */

    $log = sfjsInBrowser(
        <<<HTML
        <form method="get" action="/look" \x40get="/look" \x40target="#result">
          <input name="postcode" value="01001-000">
          <button type="submit">Look up</button>
        </form>
        <div id="result"></div>
        HTML,
        <<<HTML
          const log = [];
          window.fetch = (url, init) => {
            log.push('FETCH ' + url);
            return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('<p>swapped</p>') });
          };
        HTML,
        <<<HTML
          window.addEventListener('load', () => {
            document.querySelector('button').click();
            setTimeout(() => { report(log.join(' | ') + ' || ' + document.getElementById('result').innerHTML); }, 50);
          });
        HTML
    );

    if ($log === null) {
        return;
    }

    // The typed value left the page, which is the whole point.
    $tests->assertTrue(str_contains($log, 'FETCH /look?postcode=01001-000'));

    // And the answer landed where the attribute said.
    $tests->assertTrue(str_contains($log, '<p>swapped</p>'));
});

$tests->run('min and max follow the value, and length is asked for by name', function () use ($tests): void {
    /*
     * "min:18" on an age used to demand eighteen *characters*: it passed for 7
     * and failed for 21, and nothing said so. A rule whose meaning is the
     * opposite of what it reads is worse than a missing rule.
     */
    $tests->assertSame(true, Validator::validate(['age' => 21], ['age' => 'number|min:18'])->passes());
    $tests->assertSame(true, Validator::validate(['age' => 7], ['age' => 'number|min:18'])->fails());
    $tests->assertSame(true, Validator::validate(['age' => 21], ['age' => 'number|max:18'])->fails());

    // On anything that is not a number they count characters, as before.
    $tests->assertSame(true, Validator::validate(['name' => 'Jo'], ['name' => 'min:3'])->fails());
    $tests->assertSame(true, Validator::validate(['name' => 'Joana'], ['name' => 'min:3'])->passes());

    // Characters, not bytes.
    $tests->assertSame(true, Validator::validate(['name' => '日本語'], ['name' => 'min:3'])->passes());

    /*
     * A postcode is a number that is really a string, so the length rules say
     * so by name and are never read as a value.
     */
    $tests->assertSame(true, Validator::validate(['zip' => '01001'], ['zip' => 'minLength:5'])->passes());
    $tests->assertSame(true, Validator::validate(['zip' => '0100'], ['zip' => 'minLength:5'])->fails());
    $tests->assertSame(true, Validator::validate(['zip' => '010012'], ['zip' => 'maxLength:5'])->fails());

    // The message says which kind of limit failed.
    $value = Validator::validate(['age' => 7], ['age' => 'number|min:18'])->errors()['age'][0];
    $length = Validator::validate(['name' => 'Jo'], ['name' => 'min:3'])->errors()['name'][0];

    $tests->assertSame(false, str_contains($value, 'character'));
    $tests->assertSame(true, str_contains($length, 'character'));
});

$tests->run('the rules the browser checks are the rules the server enforces', function () use ($tests): void {
    /*
     * The browser validated url and pattern while the server could not, which
     * is backwards: anybody can skip the browser with a request of their own,
     * so the weaker list was the one that mattered. And three of the client's
     * documented rules never ran at all — "minLength:5" was looked up whole as
     * a rule name, not found, and the field passed.
     *
     * This compares the two lists so they cannot drift apart again.
     */
    $script = (string) file_get_contents(Assets::path() . '/js/sfjs.js');
    $start = strpos($script, 'const validate = {');
    $end = strpos($script, PHP_EOL . '  };', $start ?: 0);
    $section = substr($script, (int) $start, (int) $end - (int) $start);

    preg_match_all('/^\s{4}([a-zA-Z]+):/m', $section, $matches);
    $browser = array_values(array_unique($matches[1]));

    $server = ['required', 'email', 'url', 'number', 'alpha', 'alphanum', 'min', 'max', 'minLength', 'maxLength', 'pattern'];

    sort($browser);
    sort($server);

    $tests->assertSame($server, $browser);

    // And every one of them is a rule the server really applies.
    foreach ($server as $rule) {
        $expression = in_array($rule, ['min', 'max', 'minLength', 'maxLength'], true)
            ? $rule . ':3'
            : ($rule === 'pattern' ? 'pattern:^a$' : $rule);

        // Unknown rules throw; a known one must not, whatever the value.
        Validator::validate(['f' => 'a'], ['f' => $expression]);
    }

    $tests->assertThrows(
        static fn () => Validator::validate(['f' => 'a'], ['f' => 'inventada']),
        InvalidArgumentException::class
    );
});

$tests->run('sf.req hands back the Response, and swaps from a copy of it', function () use ($tests): void {
    /*
     * sf.ajax resolved with nothing, so the answer to a request made from
     * JavaScript could not be read at all — worse than fetch at the one thing
     * fetch does. sf.req resolves with fetch's own Response, adds the CSRF
     * token, and still swaps when it is given a target, from a copy, so the
     * body is there for the caller afterwards.
     */
    $result = sfjsInBrowser(
        '<div id="out"></div>',
        <<<'JS'
        window.calls = [];
        window.fetch = (url, init) => {
          calls.push({ url, method: init.method, headers: init.headers, body: init.body ?? null, credentials: init.credentials ?? null });
          const json = url.startsWith('/api');
          return Promise.resolve(new Response(json ? '{"name":"Ana"}' : '<b>swapped</b>', { status: 200, headers: { 'X-Stock': '3' } }));
        };
        JS,
        <<<'JS'
        window.addEventListener('load', async () => {
          const got = await sf.req.get('/api/users', {
            query: { page: 2, tag: ['x', 'y'], none: null },
            headers: { Accept: 'application/json' },
            credentials: 'include',
          });
          const user = await got.json();
          const posted = await sf.req.post('/cart', { id: 7 }, { target: '#out' });

          report({
            isResponse: got instanceof Response,
            status: got.status,
            name: user.name,
            get: calls[0],
            post: calls[1],
            swapped: document.getElementById('out').innerHTML,
            bodyAfterSwap: await posted.text(),
            stock: posted.headers.get('X-Stock'),
          });
        });
        JS
    );

    if ($result === null) {
        return;
    }

    $tests->assertSame(true, $result['isResponse']);
    $tests->assertSame(200, $result['status']);
    $tests->assertSame('Ana', $result['name']);

    // The query is built, a list as repeated keys, and nothing for a null.
    $tests->assertSame('/api/users?page=2&tag=x&tag=y', $result['get']['url']);
    $tests->assertSame('application/json', $result['get']['headers']['Accept']);
    $tests->assertSame('include', $result['get']['credentials']);

    // A GET changes nothing, so it carries no token and no body.
    $tests->assertSame(false, isset($result['get']['headers']['X-CSRF-Token']));
    $tests->assertSame(null, $result['get']['body']);

    $tests->assertSame('POST', $result['post']['method']);
    $tests->assertSame('the-token', $result['post']['headers']['X-CSRF-Token']);
    $tests->assertSame('application/json', $result['post']['headers']['Content-Type']);
    $tests->assertSame('{"id":7}', $result['post']['body']);

    // Swapped, and the body was still there to be read.
    $tests->assertSame('<b>swapped</b>', $result['swapped']);
    $tests->assertSame('<b>swapped</b>', $result['bodyAfterSwap']);
    $tests->assertSame('3', $result['stock']);
});

$tests->run('sf.req rejects when no answer came, and only the latest request of an element lands', function () use ($tests): void {
    /*
     * sf.ajax caught every failure itself, so a page could not tell a network
     * error from success — a try/catch around it caught nothing. sf.req
     * settles the way fetch does: a failure, a timeout and an abort reject,
     * each with the error fetch would give.
     */
    $result = sfjsInBrowser(
        '<button id="go">go</button><div id="out"></div>',
        <<<'JS'
        window.fetch = (url, init) => new Promise((resolve, reject) => {
          if (url === '/down') return reject(new TypeError('Failed to fetch'));

          const delay = { '/slow': 200, '/hang': 10000 }[url] ?? 10;
          const timer = setTimeout(() => resolve(new Response('<i>' + url + '</i>')), delay);

          init.signal.addEventListener('abort', () => { clearTimeout(timer); reject(init.signal.reason); });
        });
        JS,
        <<<'JS'
        window.addEventListener('load', async () => {
          const out = {};
          const button = document.getElementById('go');
          let errors = 0;

          button.addEventListener('sf:error', () => errors++);

          out.down = await sf.req.get('/down', { source: button }).then(() => 'resolved', (e) => e.name);
          out.timeout = await sf.req.get('/hang', { timeout: 50 }).then(() => 'resolved', (e) => e.name);

          const controller = new AbortController();
          const cancelled = sf.req.get('/hang', { signal: controller.signal });
          controller.abort();
          out.cancelled = await cancelled.then(() => 'resolved', (e) => e.name);

          // The slow one is asked first; the fast one replaces it.
          const first = sf.req.get('/slow', { source: button, target: '#out' });
          const second = sf.req.get('/fast', { source: button, target: '#out' });

          out.first = await first.then(() => 'resolved', (e) => e.name);
          await second;
          await wait(300);

          out.landed = document.getElementById('out').textContent;
          out.errors = errors;
          out.busy = document.getElementById('out').getAttribute('aria-busy');
          out.disabled = button.disabled;

          report(out);
        });
        JS
    );

    if ($result === null) {
        return;
    }

    $tests->assertSame('TypeError', $result['down']);
    $tests->assertSame('TimeoutError', $result['timeout']);
    $tests->assertSame('AbortError', $result['cancelled']);

    // The replaced request rejects, and its late answer never reaches the page.
    $tests->assertSame('AbortError', $result['first']);
    $tests->assertSame('/fast', $result['landed']);

    // sf:error went out for the answer that did not come, not for the aborts.
    $tests->assertSame(1, $result['errors']);

    // And nothing is left looking busy.
    $tests->assertSame(null, $result['busy']);
    $tests->assertSame(false, $result['disabled']);
});

$tests->run('sf.target puts markup in place from a string or a response', function () use ($tests): void {
    /*
     * The second half of a request, on its own, so an answer can be looked at
     * before it is sent somewhere. It goes through the same swap the
     * attributes use: morph by default, and what arrives is bound.
     */
    $result = sfjsInBrowser(
        '<div id="box"><h2 id="heading">one</h2></div><ul id="list"><li>a</li></ul><div id="more"></div>',
        <<<'JS'
        window.calls = [];
        window.fetch = (url) => { calls.push(url); return Promise.resolve(new Response('<em>loaded</em>')); };
        JS,
        <<<'JS'
        window.addEventListener('load', async () => {
          const out = {};
          const heading = document.getElementById('heading');

          await sf.target('#box', '<h2 id="heading">two</h2>');
          out.sameNode = heading === document.getElementById('heading');
          out.text = heading.textContent;

          await sf.target(document.getElementById('list'), new Response('<li>b</li>'), { swap: 'beforeend' });
          out.list = document.getElementById('list').innerHTML;

          const read = new Response('<p>x</p>');
          await read.text();
          out.used = await sf.target('#box', read).then(() => 'resolved', (e) => e.name + ': ' + e.message);

          // What arrives is bound: this @get fires as soon as it is in the page.
          await sf.target('#more', '<div @get="/loaded" @trigger="load"></div>');
          await wait(50);
          out.calls = calls;

          report(out);
        });
        JS
    );

    if ($result === null) {
        return;
    }

    $tests->assertSame(true, $result['sameNode']);
    $tests->assertSame('two', $result['text']);
    $tests->assertSame('<li>a</li><li>b</li>', $result['list']);
    $tests->assertSame(true, str_starts_with($result['used'], 'TypeError: sf.target: this response was already read'));
    $tests->assertSame(['/loaded'], $result['calls']);
});

$tests->run('a plugin is attached once per element, updated, and cleaned up when the element leaves', function () use ($tests): void {
    /*
     * sf.onBind hands a plugin the parent of whatever was swapped, so the
     * elements that were already there come round again, and nothing says
     * when one leaves. Every plugin had to remember a flag of its own and
     * leaked its timers when it forgot. sf.plugin does the bookkeeping.
     */
    $result = sfjsInBrowser(
        <<<'HTML'
        <div id="scope" @state="{ count: 0 }">
          <span id="count" @text="count"></span>
          <div id="panel"><span id="one" @counter="a"></span></div>
        </div>
        <b @broken></b>
        HTML,
        <<<'JS'
        window.fetch = (url, init) => new Promise((resolve, reject) => {
          const timer = setTimeout(() => resolve(new Response('fetched ' + url)), url === '/hang' ? 10000 : 10);
          init.signal.addEventListener('abort', () => { clearTimeout(timer); reject(init.signal.reason); });
        });
        JS,
        <<<'JS'
        window.addEventListener('load', async () => {
          const out = { attached: 0, detached: 0, updated: [], pings: 0, ticks: 0 };

          sf.plugin('counter', {
            attach(el, ctx) {
              out.attached++;
              ctx.state.count++;
              ctx.every(10, () => out.ticks++);
              ctx.on(window, 'ping', () => out.pings++);
              return () => out.detached++;
            },
            update(el, ctx) { out.updated.push(ctx.value); },
          });

          sf.plugin('broken', { attach() { throw new Error('boom'); } });
          sf.plugin('later', { attach(el) { el.dataset.ok = 'yes'; } });

          sf.plugin('fetcher', {
            async attach(el, ctx) {
              const answer = await ctx.req.get(ctx.value);
              el.textContent = await answer.text();
            },
          });

          await wait(0);
          out.countAfterFirst = document.getElementById('count').textContent;

          // A swap of the parent: the first element stays, a second arrives.
          await sf.target('#panel', '<span id="one" @counter="a"></span><span id="two" @counter="b"></span>');
          out.attachedAfterSwap = out.attached;

          // A swap that changes a value.
          await sf.target('#panel', '<span id="one" @counter="z"></span><span id="two" @counter="b"></span>');

          window.dispatchEvent(new Event('ping'));
          out.pingsBefore = out.pings;

          // Taken out by plain DOM code, not by a swap.
          document.getElementById('two').remove();
          await wait(0);
          out.detachedAfterRemove = out.detached;

          window.dispatchEvent(new Event('ping'));
          out.pingsAfter = out.pings;

          document.getElementById('one').remove();
          await wait(0);
          const ticks = out.ticks;
          await wait(100);
          out.ticksStopped = out.ticks === ticks;

          // Markup that another script put in the page.
          document.body.insertAdjacentHTML('beforeend', '<i id="late" @later></i><i id="fetched" @fetcher="/data"></i><i id="hanging" @fetcher="/hang"></i>');
          await wait(50);
          out.late = document.getElementById('late').dataset.ok;
          out.fetched = document.getElementById('fetched').textContent;

          // Leaves while its request is in the air: the request is aborted, and that is not an error.
          document.getElementById('hanging').remove();
          await wait(50);

          out.names = ['include', 'Bad_Name', 'state', 'counter', 'stream', 'get'].map((name) => {
            try { sf.plugin(name, { attach() {} }); return name + ': accepted'; } catch (e) { return name + ': ' + e.message; }
          });

          out.errors = logged.error;
          out.countAtEnd = document.getElementById('count').textContent;

          report(out);
        });
        JS
    );

    if ($result === null) {
        return;
    }

    // Registered after the page was bound, and attached straight away; ctx.state writes reach @text.
    $tests->assertSame('1', $result['countAfterFirst']);

    // The element that survived the swap was not attached a second time.
    $tests->assertSame(2, $result['attachedAfterSwap']);
    $tests->assertSame(['z'], $result['updated']);
    $tests->assertSame(2, $result['pingsBefore']);

    // Removed: cleanup ran, its listener went, its timer stopped.
    $tests->assertSame(1, $result['detachedAfterRemove']);
    $tests->assertSame(3, $result['pingsAfter']);
    $tests->assertSame(true, $result['ticksStopped']);

    // Markup from elsewhere is attached too, and ctx.req answers.
    $tests->assertSame('yes', $result['late']);
    $tests->assertSame('fetched /data', $result['fetched']);

    // One broken plugin is reported by name and stops nobody else.
    $broken = array_values(array_filter($result['errors'], static fn (string $line): bool => str_contains($line, '@broken')));
    $tests->assertSame(1, count($broken));
    $tests->assertSame(true, str_contains($broken[0], 'failed in attach'));

    // The request that was cut off by its element leaving is not an error.
    $tests->assertSame(1, count($result['errors']));

    [$include, $bad, $state, $twice, $builtIn, $core] = $result['names'];
    $tests->assertSame(true, str_contains($include, 'is reserved'));
    $tests->assertSame(true, str_contains($bad, 'is not a valid name'));
    $tests->assertSame(true, str_contains($state, 'is reserved'));
    $tests->assertSame(true, str_contains($twice, 'already registered'));
    $tests->assertSame(true, str_contains($builtIn, 'already registered'));
    $tests->assertSame(true, str_contains($core, 'is reserved'));
});

$tests->run('a stream is a plugin, and stops when its element leaves', function () use ($tests): void {
    /*
     * @stream moved onto sf.plugin: its own flag, its own observer and its
     * own cleanup gave way to the ones every plugin gets. This is the check
     * that a stream still streams, and still stops.
     */
    $result = sfjsInBrowser(
        '<button id="start" @stream="/stream" @target="#out" @trigger="click">go</button><div id="out"></div>',
        <<<'JS'
        window.signals = [];
        window.fetch = (url, init) => {
          signals.push(init.signal);
          const encoder = new TextEncoder();
          const body = new ReadableStream({
            start(controller) {
              controller.enqueue(encoder.encode('Hello, '));
              setTimeout(() => controller.enqueue(encoder.encode('world')), 20);
            },
          });
          return Promise.resolve(new Response(body));
        };
        JS,
        <<<'JS'
        window.addEventListener('load', async () => {
          document.getElementById('start').click();
          await wait(100);

          const text = document.getElementById('out').textContent;

          document.getElementById('start').remove();
          await wait(20);

          report({ text, requests: signals.length, stopped: signals[0] ? signals[0].aborted : null });
        });
        JS
    );

    if ($result === null) {
        return;
    }

    $tests->assertSame('Hello, world', $result['text']);
    $tests->assertSame(1, $result['requests']);
    $tests->assertSame(true, $result['stopped']);
});

$tests->run('a plugin\'s callbacks are guarded: a failure is reported by name, a replaced request is not', function () use ($tests): void {
    /*
     * A request replaced by a newer one from the same element rejects with
     * AbortError, as fetch does when it is aborted. An async listener that
     * awaited it would print "Uncaught (in promise)" at every quick second
     * click, so what ctx.on, ctx.every, ctx.after and ctx.debounce call is
     * guarded like attach: the abort is quiet, a real failure is named.
     */
    $result = sfjsInBrowser(
        '<button id="ask" @asker="/question">ask</button>',
        <<<'JS'
        window.fetch = (url, init) => new Promise((resolve, reject) => {
          const timer = setTimeout(() => resolve(new Response('answer')), 50);
          init.signal.addEventListener('abort', () => { clearTimeout(timer); reject(init.signal.reason); });
        });
        JS,
        <<<'JS'
        window.addEventListener('load', async () => {
          sf.plugin('asker', {
            attach(el, ctx) {
              ctx.on(el, 'click', async () => {
                const res = await ctx.req.get(ctx.value);
                el.dataset.got = await res.text();
              });
              ctx.on(el, 'boom', () => { throw new Error('bad'); });
            },
          });

          const button = document.getElementById('ask');

          button.click();
          await wait(5);
          button.disabled = false;   // the first request disabled it; click again before it lands
          button.click();
          await wait(150);

          button.dispatchEvent(new Event('boom'));
          await wait(0);

          report({ got: button.dataset.got, errors: logged.error });
        });
        JS
    );

    if ($result === null) {
        return;
    }

    $tests->assertSame('answer', $result['got']);
    $tests->assertSame(1, count($result['errors']));
    $tests->assertSame(true, str_contains($result['errors'][0], '@asker failed in boom'));
});

$tests->run('what was deprecated is gone, and the old spellings do nothing', function () use ($tests): void {
    /*
     * Removed in 0.39.0: sf.ajax (sf.req), sf.morph (sf.target), the sf.dom,
     * sf.storage and sf.util wrappers, the @hxGet spellings and "every 10s"
     * with a space. A page still written against them has to fail where it
     * can be seen — an undefined function, a button that does nothing — rather
     * than keep working through an alias nobody remembers is there.
     */
    $result = sfjsInBrowser(
        '<button id="old" @hxGet="/old" @hxTarget="#out">old</button>'
        . '<div @get="/spaced" @trigger="every 50ms"></div><div id="out"></div>',
        <<<'JS'
        window.calls = [];
        window.fetch = (url) => { calls.push(url); return Promise.resolve(new Response('swapped')); };
        JS,
        <<<'JS'
        window.addEventListener('load', async () => {
          document.getElementById('old').click();
          await wait(200);

          report({
            gone: ['ajax', 'morph', 'dom', 'storage', 'util'].filter((name) => name in sf),
            calls,
            out: document.getElementById('out').textContent,
          });
        });
        JS
    );

    if ($result === null) {
        return;
    }

    $tests->assertSame([], $result['gone']);
    $tests->assertSame([], $result['calls']);
    $tests->assertSame('', $result['out']);
});

$tests->run('a plugin cannot be named after a template directive', function () use ($tests): void {
    /*
     * The SFPHP parser reads @include, @if or @block in a template as its own
     * syntax, so <div @include="x"> stops the page from compiling long before
     * SFJS could see it. sf.plugin refuses those names, and this keeps its
     * list in step with the parser's.
     */
    $script = (string) file_get_contents(Assets::path() . '/js/sfjs.js');
    $start = strpos($script, 'const RESERVED = [');
    $end = strpos($script, '];', $start ?: 0);

    preg_match_all("/'([a-z-]+)'/", substr($script, (int) $start, (int) $end - (int) $start), $matches);

    foreach (\SfphpProject\src\View\Parser::DIRECTIVES as $directive) {
        $tests->assertSame(true, in_array(strtolower($directive), $matches[1], true));
    }
});

$tests->run('js:build bundles the plugins one scope each, and builds and cleans up the page scripts', function () use ($tests): void {
    /*
     * The plugins share one file, so each is wrapped: two that both declare
     * `const format` would otherwise be a syntax error, and one that throws
     * while loading would stop the rest. The minifier does not understand
     * regular expressions, so what it writes is checked, and a script it
     * would break is kept whole.
     */
    $root = sys_get_temp_dir() . '/sfphp-bundle-' . bin2hex(random_bytes(6));
    $plugins = $root . '/app/resources/js/plugins';
    $scripts = $root . '/app/resources/js/scripts';
    mkdir($plugins, 0755, true);
    mkdir($scripts . '/admin', 0755, true);

    file_put_contents($plugins . '/a.js', "const format = 1;\nwindow.aLoaded = format;\n");
    file_put_contents($plugins . '/b.js', "const format = 2;\nthrow new Error('b broke');\n");
    file_put_contents($plugins . '/c.js', "const format = 3; // a comment\nwindow.cLoaded = format;\n");
    file_put_contents($scripts . '/home.js', "window.home = true; // gone once minified\n");
    file_put_contents($scripts . '/admin/users.js', "const slashes = /\\/\\//;\nwindow.users = slashes.test('//');\n");

    $node = trim((string) shell_exec('command -v node 2>/dev/null')) !== '';

    try {
        $lines = (new \SfphpProject\src\View\ScriptBundler($root))->build();
        $public = $root . '/public/assets/js';

        $tests->assertSame(true, str_contains(implode("\n", $lines), 'plugins.js: 3 plugins'));

        $bundle = (string) file_get_contents($public . '/plugins.js');
        $tests->assertSame(true, strpos($bundle, '// plugins/a.js') < strpos($bundle, '// plugins/b.js'));
        $tests->assertSame(true, strpos($bundle, '// plugins/b.js') < strpos($bundle, '// plugins/c.js'));
        $tests->assertSame(true, is_file($public . '/plugins.min.js'));

        $tests->assertSame(true, is_file($public . '/scripts/home.js'));
        $tests->assertSame(true, is_file($public . '/scripts/admin/users.min.js'));

        if ($node) {
            // The regex would have lost its "//": the minified copy is the source.
            $tests->assertSame(file_get_contents($scripts . '/admin/users.js'), file_get_contents($public . '/scripts/admin/users.min.js'));
            $tests->assertSame(true, str_contains(implode("\n", $lines), 'scripts/admin/users.js: the minifier broke it'));

            // And one it can minify is minified.
            $tests->assertSame(false, str_contains((string) file_get_contents($public . '/scripts/home.min.js'), 'gone once minified'));
        }

        // In the browser, each plugin in its own scope: b fails alone.
        $result = sfjsInBrowser('', '', $bundle . "\nwindow.addEventListener('load', () => report({ a: window.aLoaded, c: window.cLoaded, errors: logged.error }));");

        if ($result !== null) {
            $tests->assertSame(1, $result['a']);
            $tests->assertSame(3, $result['c']);
            $tests->assertSame(1, count($result['errors']));
            $tests->assertSame(true, str_contains($result['errors'][0], 'SFJS: plugins/b.js failed to load'));
        }

        // What is not valid JavaScript is refused, naming the file.
        if ($node) {
            file_put_contents($plugins . '/broken.js', "const = ;\n");
            $tests->assertThrows(static fn () => (new \SfphpProject\src\View\ScriptBundler($root))->build(), RuntimeException::class);
            unlink($plugins . '/broken.js');
        }

        // A name that is where a minified copy goes.
        file_put_contents($scripts . '/page.min.js', "window.x = 1;\n");
        $tests->assertThrows(static fn () => (new \SfphpProject\src\View\ScriptBundler($root))->build(), RuntimeException::class);
        unlink($scripts . '/page.min.js');

        // Sources gone: what was built from them goes too, folders included.
        array_map('unlink', glob($plugins . '/*.js') ?: []);
        unlink($scripts . '/admin/users.js');
        (new \SfphpProject\src\View\ScriptBundler($root))->build();

        $tests->assertSame(false, is_file($public . '/plugins.js'));
        $tests->assertSame(false, is_file($public . '/plugins.min.js'));
        $tests->assertSame(false, is_dir($public . '/scripts/admin'));
        $tests->assertSame(true, is_file($public . '/scripts/home.js'));
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
});

$tests->run('make:plugin writes a plugin, and refuses the names sf.plugin refuses', function () use ($tests): void {
    $root = sys_get_temp_dir() . '/sfphp-make-plugin-' . bin2hex(random_bytes(6));
    mkdir($root, 0755, true);

    try {
        $generator = new \SfphpProject\src\Console\Generators\PluginGenerator($root);
        $file = $generator->generate('Countdown');

        $tests->assertSame($root . '/app/resources/js/plugins/countdown.js', $file);
        $tests->assertSame(true, str_contains((string) file_get_contents($file), "sf.plugin('countdown', {"));

        foreach (['include', 'stream', 'swap', 'Bad_Name', '9lives'] as $name) {
            $tests->assertThrows(static fn () => $generator->generate($name), InvalidArgumentException::class);
        }

        // It never replaces a plugin that is there.
        $tests->assertThrows(static fn () => $generator->generate('countdown'), \SfphpProject\src\Console\Generators\GeneratorFileExists::class);
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }

    /*
     * The same list as sf.plugin, plus the one SFJS registers for itself, so
     * a name make:plugin accepts is one the browser accepts.
     */
    $script = (string) file_get_contents(Assets::path() . '/js/sfjs.js');
    $start = strpos($script, 'const RESERVED = [');
    $end = strpos($script, '];', $start ?: 0);
    preg_match_all("/'([a-z-]+)'/", substr($script, (int) $start, (int) $end - (int) $start), $matches);

    $browser = array_merge($matches[1], ['stream']);
    $server = \SfphpProject\src\Console\Generators\PluginGenerator::RESERVED;

    sort($browser);
    sort($server);

    $tests->assertSame($browser, $server);
});

$tests->run('what the server noticed while writing the page is said in the console', function () use ($tests): void {
    /*
     * @sfjs('minified') or a plugin edited and not rebuilt is noticed while
     * PHP writes the page, and a strict Content-Security-Policy refuses the
     * inline script that could report it. So it travels as data-sf-warning,
     * and SFJS says it.
     */
    $result = sfjsInBrowser(
        '<link rel="stylesheet" href="data:," data-sf-warning="the first"><i data-sf-warning="the second"></i>',
        '',
        "window.addEventListener('load', () => report(logged.warn));"
    );

    if ($result === null) {
        return;
    }

    $tests->assertSame(['SFPHP: the first', 'SFPHP: the second'], $result);
});
