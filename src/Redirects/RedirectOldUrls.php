<?php

/*
 * This file is part of ernestdefoe/importer.
 */

namespace ErnestDefoe\Importer\Redirects;

use Flarum\Foundation\ErrorHandling\Registry;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Catches an address the old forum used to serve, and sends it where the
 * content went.
 *
 * 🚨 **Middleware rather than routes, and that is not a preference.** Half of
 * these addresses live in a QUERY STRING — `viewtopic.php?t=123`,
 * `index.php?topic=123.0` — which a router cannot express: to Flarum's router
 * every one of them is a request for `/index.php`. A middleware sees the whole
 * thing.
 *
 * 🚨 **It acts only once Flarum has failed to answer.** The old forum's shapes
 * are not Flarum's, but they are close enough to collide — Discourse's
 * `/t/slug/123` and Flarum's own `/t/{slug}` begin identically. Letting Flarum
 * go first means this can never take an address the site itself can serve.
 */
class RedirectOldUrls implements MiddlewareInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Resolver $resolver,
        protected UrlGenerator $url,
        protected Registry $errors,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /*
         * 🚨 **A missing page usually arrives here as an EXCEPTION rather than
         * as a 404, and this middleware was first written the other way round.**
         *
         * `ResolveRoute` THROWS when nothing matches; `HandleErrors`, further up
         * the stack, is what turns that into a 404 page. So a version of this
         * registered with `->add()` — at the END of the stack, below the
         * resolver — never had `process()` called for a single address it
         * exists to catch. It would have shipped, passed every test that did
         * not make a real request to a real missing page, and done nothing.
         *
         * 🚨 And there are TWO ways to be missing, which cost a second round of
         * this. `/t/123` from Discourse MATCHES Flarum's own `/t/{slug}` route,
         * so the router succeeds and the page fails afterwards, throwing
         * something else entirely. Catching only the router's exception left
         * every colliding shape — `/t/…`, `/u/…`, `/user/…` — still dead, which
         * is precisely the set this feature is hardest and most needed for.
         *
         * So: catch everything, and ask Flarum's OWN registry what the thing
         * would have become. If it was going to be a 404 there is nothing to
         * lose by trying; if it was going to be anything else it is rethrown
         * untouched, and the error page, its logging and its content
         * negotiation all behave as though this were not installed.
         */
        try {
            $response = $handler->handle($request);

            if ($response->getStatusCode() !== 404) {
                return $response;
            }
        } catch (Throwable $e) {
            if ($this->errors->handle($e)->getStatusCode() !== 404) {
                throw $e;
            }

            $target = $this->target($request);

            if ($target === null) {
                throw $e;
            }

            return $this->redirect($target);
        }

        $target = $this->target($request);

        return $target === null ? $response : $this->redirect($target);
    }

    /**
     * 🚨 301, not 302, and this is the whole point of the feature.
     *
     * A 302 tells a search engine the old address is still the canonical one
     * and this is a detour — so the old URL keeps a ranking it can no longer
     * serve and the new one never earns any. Everything anybody ever linked to
     * stays half-lost. 301 moves it.
     */
    protected function redirect(string $target): ResponseInterface
    {
        return new RedirectResponse($target, 301);
    }

    /** The absolute URL to send somebody to, or null. */
    protected function target(ServerRequestInterface $request): ?string
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return null;
        }

        $runId = (int) $this->settings->get('ernestdefoe-importer.redirect_run', 0);
        $source = (string) $this->settings->get('ernestdefoe-importer.redirect_source', '');

        // Off until somebody finished the wizard.
        if ($runId < 1 || $source === '' || ! Patterns::known($source)) {
            return null;
        }

        $uri = $request->getUri();
        $pathAndQuery = $uri->getPath().($uri->getQuery() !== '' ? '?'.$uri->getQuery() : '');

        $path = $this->resolver->resolve($runId, $source, $pathAndQuery);

        if ($path === null) {
            return null;
        }

        /*
         * 🚨 Flarum's own URL generator, never the request's Host header —
         * which is chosen by whoever is asking. A redirect assembled from an
         * attacker-supplied host would be an open redirect on every old address
         * the forum ever had.
         *
         * 🚨 And NOT the `forum_url` setting, which is what this reached for
         * first: on a normal install that row DOES NOT EXIST. The base URL
         * lives in `config.php`, so reading it from settings yielded an empty
         * string and every redirect went out as a bare path. Browsers follow
         * one, which is exactly why it would have survived a casual test.
         */
        return rtrim($this->url->to('forum')->base(), '/').$path;
    }
}
