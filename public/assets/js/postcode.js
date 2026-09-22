/**
 * The postcode lookup for the .phpx demonstration page.
 *
 * Written with SFJS and nothing else: no bundler, no framework, no dependency.
 * The request leaves the browser, so the server is never involved — which is
 * the point being demonstrated.
 */
sf.dom.ready(function () {
    var input = document.getElementById('postcode');
    var button = document.getElementById('lookup');
    var output = document.getElementById('result');

    if (!input || !button || !output) {
        return;
    }

    function notice(kind, text) {
        output.innerHTML = '<div class="alert ' + kind + '">' + text + '</div>';
    }

    function fieldHtml(label, value) {
        return '<div class="py-1">'
            + '<span class="text-xs text-muted d-block">' + label + '</span>'
            + '<span class="font-semibold">' + (value || '—') + '</span>'
            + '</div>';
    }

    function lookup() {
        var postcode = input.value.replace(/\D/g, '');

        if (postcode.length !== 8) {
            notice('alert-warning', 'A Brazilian postcode has eight digits.');
            return;
        }

        output.innerHTML = '<p class="text-muted m-0">Looking it up…</p>';

        sf.ajax.get('https://viacep.com.br/ws/' + postcode + '/json/', {
            onSuccess: function (text) {
                var data;

                try {
                    data = JSON.parse(text);
                } catch (error) {
                    notice('alert-danger', 'The answer did not come back as JSON.');
                    return;
                }

                /*
                 * ViaCEP answers 200 with {"erro": true} for a postcode that
                 * does not exist, so a successful request is not a successful
                 * lookup and the body has to be read.
                 */
                if (data.erro) {
                    notice('alert-warning', 'No address for that postcode.');
                    return;
                }

                output.innerHTML = '<div class="card">'
                    + '<div class="card-body">'
                    + fieldHtml('Street', data.logradouro)
                    + fieldHtml('District', data.bairro)
                    + fieldHtml('City', data.localidade)
                    + fieldHtml('State', data.uf)
                    + '</div></div>';
            },
            onError: function () {
                notice('alert-danger', 'The lookup could not be made right now.');
            }
        });
    }

    sf.dom.on(button, 'click', lookup);

    sf.dom.on(input, 'keydown', function (event) {
        if (event.key === 'Enter') {
            lookup();
        }
    });
});
