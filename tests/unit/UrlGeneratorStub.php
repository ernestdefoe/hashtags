<?php

namespace Ernestdefoe\Hashtags\Tests\unit;

use Flarum\Http\RouteCollection;
use Flarum\Http\RouteCollectionUrlGenerator;
use Flarum\Http\UrlGenerator;

/**
 * ConfigureHashtags needs one thing from the URL generator: the `hashtag`
 * route prefix it bakes into the XSL template. Booting Flarum's container to
 * produce a string would make these tests need a whole application.
 *
 * Uses the real RouteCollection and RouteCollectionUrlGenerator rather than
 * returning a hand-written string, so the route name here has to actually
 * resolve — if `hashtag` were ever renamed in extend.php without updating the
 * formatter, this would throw rather than quietly pass.
 */
class UrlGeneratorStub extends UrlGenerator
{
    public function __construct()
    {
        // Deliberately does not call parent::__construct(): the Application it
        // wants exists only inside a booted Flarum.
    }

    public function to(string $collection): RouteCollectionUrlGenerator
    {
        $routes = new RouteCollection;
        $routes->get('/hashtag/{name}', 'hashtag', fn () => null);
        $routes->get('/hashtags', 'hashtags', fn () => null);

        return new RouteCollectionUrlGenerator('https://forum.test', $routes);
    }
}
