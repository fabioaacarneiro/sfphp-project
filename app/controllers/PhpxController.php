<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\ClientException;
use SfphpProject\src\Http\Http;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\View\Sfht;

use function SfphpProject\app\components\postcode\lookup\Address;
use function SfphpProject\app\components\postcode\lookup\Notice;
use function SfphpProject\app\components\postcode\PostcodePage;

/**
 * Serves the page that demonstrates .phpx, SFCSS and SFJS together.
 *
 * The page is a component, so the action has nothing to assemble: it asks for
 * the markup and answers with it.
 */
final class PhpxController
{
    /**
     * Show the demonstration.
     *
     * @param Request $request The incoming request
     * @return Response The page
     */
    public function index(Request $request): Response
    {
        return Response::html((string) PostcodePage());
    }

    /**
     * Look an address up, and answer with the markup for it.
     *
     * Two answers from one action: the fragment, when SFJS asked and will swap
     * it into place, and the whole page when a browser submitted the form with
     * no JavaScript running. The address itself is the same component either
     * way, which is what keeps a page and its updates from drifting apart.
     *
     * @param Request $request The incoming request
     * @return Response The address, or a notice explaining why there is none
     */
    public function postcode(Request $request): Response
    {
        $digits = preg_replace('/\D/', '', (string) $request->query('postcode', '')) ?? '';

        if (strlen($digits) !== 8) {
            return $this->answer($request, Notice('A Brazilian postcode has eight digits.'));
        }

        try {
            /*
             * The framework's own client, with a timeout, because a service
             * that stops answering must not take this page down with it. A
             * real application would also cache: an address does not change,
             * and a request per visitor is a request per visitor.
             */
            $response = Http::timeout(5)->get('https://viacep.com.br/ws/' . $digits . '/json/');
        } catch (ClientException $exception) {
            return $this->answer($request, Notice('The lookup could not be made right now.', 'danger'));
        }

        $address = $response->ok() ? $response->json() : null;

        /*
         * ViaCEP answers 200 with {"erro": true} for a postcode nobody uses, so
         * a successful request is not a successful lookup and the body has to
         * be read.
         */
        if (!is_array($address) || ($address['erro'] ?? false)) {
            return $this->answer($request, Notice('No address for that postcode.'));
        }

        return $this->answer($request, Address(
            (string) ($address['logradouro'] ?? ''),
            (string) ($address['bairro'] ?? ''),
            (string) ($address['localidade'] ?? ''),
            (string) ($address['uf'] ?? '')
        ));
    }

    /**
     * Send a fragment to SFJS, and a whole page to a browser.
     *
     * @param Request $request The incoming request
     * @param Sfht $result The markup to place in the result area
     * @return Response The response
     */
    private function answer(Request $request, Sfht $result): Response
    {
        return Response::fragment(
            $request,
            $result,
            page: static fn (Sfht $inner): Sfht => PostcodePage($inner)
        );
    }
}
