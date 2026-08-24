<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests how neo_uri resolves a uri once the guard clauses have let it through.
 *
 * The resolution half of the module's most-called **Twig helper**. It is two
 * passes and a fallback: `Url::fromUri()` first, `Url::fromUserInput()` for
 * anything the first pass rejects, and the literal string `/` when neither
 * succeeds — the answer a past bug fix added so that an invalid uri degrades to
 * the front page instead of fatalling a page. Above them sits the empty-uri
 * branch, which resolves the current route, and the destination flag, which is
 * the most recently shipped of the three fixes to this function.
 *
 * @see \Drupal\Tests\neo_twig\Unit\TwigExtensionUriGuardsTest
 *
 * **Why this needs a container.** Every assertion below runs `Url::toString()`,
 * and each of the four paths into it resolves a different service:
 * `route:` and the empty-uri branch need the route provider and the url
 * generator, `internal:` needs the path validator, `base:` and an external uri
 * need the unrouted url assembler. `system` supplies the routes — including
 * `<current>`, which the destination is built from — and the router is rebuilt
 * so the provider can answer for them.
 *
 * `system` alone is wider than `neo_twig.info.yml`, which declares no
 * dependencies at all. That is deliberate: the module resolves everything here
 * through `\Drupal\Core\Url`'s static constructors, so a test can only reach
 * them where a container is real.
 */
#[Group('neo_twig')]
final class TwigExtensionUriTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The extension under test.
   */
  private TwigExtension $extension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The routes every branch below resolves against — including `<current>`,
    // `<front>` and the three **non-linking uri** routes — are system's, and
    // the provider cannot answer for them until the router is built.
    $this->container->get('router.builder')->rebuild();
    $this->extension = new TwigExtension();
  }

  /**
   * Tests that it resolves a valid uri to its url.
   *
   * One case per scheme the first pass understands, because each takes a
   * different route through `Url::fromUri()` and out to a different service.
   * The last case is the one that proves the options array is not merely
   * carried: a query and a fragment both arrive in the generated url.
   */
  public function testResolvesValidUriToItsUrl(): void {
    $this->assertSame(
      '/admin',
      $this->extension->getUrl('route:system.admin'),
      'A routed uri resolves through the route provider.'
    );
    $this->assertSame(
      '/admin',
      $this->extension->getUrl('internal:/admin'),
      'An internal path resolves to the same route.'
    );
    $this->assertSame(
      '/robots.txt',
      $this->extension->getUrl('base:robots.txt'),
      'A base: uri resolves relative to the site root, route or no route.'
    );
    $this->assertSame(
      'https://example.com/docs',
      $this->extension->getUrl('https://example.com/docs'),
      'An external uri is assembled unrouted and comes back whole.'
    );
    $this->assertSame(
      '/admin?a=b#x',
      $this->extension->getUrl('route:system.admin', [
        'query' => ['a' => 'b'],
        'fragment' => 'x',
      ]),
      'The options array reaches Url::fromUri() and shapes the result.'
    );
  }

  /**
   * Tests the second pass, and the '/' answer when that fails too.
   *
   * `Url::fromUri()` requires a scheme, so the paths a template most often
   * holds — anything beginning `/`, `#` or `?` — reject on the first pass and
   * are resolved by the second. What neither pass can resolve answers `/`,
   * which is the fix that stopped an invalid uri fatalling the page it was
   * printed on.
   *
   * The `entity:` case is the one worth reading twice: the first pass builds a
   * `Url` quite happily and it is `toString()` that raises, which is why the
   * try block wraps the whole expression rather than just the constructor.
   */
  public function testFallsBackToUserInputWhenTheFirstPassRejects(): void {
    $this->assertSame(
      '/admin',
      $this->extension->getUrl('/admin'),
      'A rooted path has no scheme, so the second pass resolves it.'
    );
    $this->assertSame(
      '/not/a/route',
      $this->extension->getUrl('/not/a/route'),
      'The second pass does not require the path to be a route.'
    );
    $this->assertSame(
      '#frag',
      $this->extension->getUrl('#frag'),
      'A bare fragment is user input too.'
    );
    $this->assertSame(
      '?a=b',
      $this->extension->getUrl('?a=b'),
      'So is a bare query string.'
    );

    $this->assertSame(
      '/',
      $this->extension->getUrl('not a uri'),
      'No scheme and no leading "/", "#" or "?": both passes reject it.'
    );
    $this->assertSame(
      '/',
      $this->extension->getUrl('entity:node/1'),
      'A uri whose route does not exist raises from toString(), inside the'
      . ' first try, and lands on the same fallback.'
    );
  }

  /**
   * Tests that it resolves the current route when the uri is empty.
   *
   * An empty uri is answered above both passes, from `<current>`, so a template
   * printing a link to nowhere in particular links to the page it is on. The
   * options array is honoured on this branch as well.
   *
   * PHP's `empty()` is what decides, so NULL and the string `'0'` take this
   * branch alongside the empty string.
   */
  public function testResolvesTheCurrentRouteWhenTheUriIsEmpty(): void {
    $this->setCurrentRoute();

    $this->assertSame(
      '/admin',
      $this->extension->getUrl(''),
      'The empty string resolves to the page being rendered.'
    );
    $this->assertSame(
      '/admin',
      $this->extension->getUrl(NULL),
      'So does NULL, which is what a missing template variable arrives as.'
    );
    $this->assertSame(
      '/admin',
      $this->extension->getUrl('0'),
      'And so does "0", because the branch is chosen by empty().'
    );
    $this->assertSame(
      '/admin?z=1',
      $this->extension->getUrl('', ['query' => ['z' => '1']]),
      'Options reach Url::fromRoute() on this branch too.'
    );
  }

  /**
   * Tests the destination query parameter, and where it is never added.
   *
   * The flag adds the current page to the options as `destination`, which is
   * how a link comes back to where it was clicked. It is written before the
   * **non-linking uri** guard runs, so the guard is the only thing standing
   * between a `<nolink>` and a query parameter pointing at a page it will never
   * navigate to — which is exactly the bug the most recent fix to this function
   * closed, and the assertion that must not flip.
   */
  public function testAddsDestinationQueryParameterWhenAsked(): void {
    $this->setCurrentRoute();

    $this->assertSame(
      '/admin',
      $this->extension->getUrl('route:system.admin'),
      'Nothing is added unless the flag is passed.'
    );
    $this->assertSame(
      '/admin?destination=/admin',
      $this->extension->getUrl('route:system.admin', [], TRUE),
      'With the flag, the current page is added as a destination.'
    );
    $this->assertSame(
      '/admin?a=b&destination=/admin',
      $this->extension->getUrl('route:system.admin', ['query' => ['a' => 'b']], TRUE),
      'It joins the query the caller already had rather than replacing it.'
    );
    $this->assertSame(
      '/admin?destination=/admin',
      $this->extension->getUrl('route:system.admin', NULL, TRUE),
      'NULL options are normalised before the destination is written into them.'
    );

    foreach (['<nolink>', '<none>', '<button>', 'route:<nolink>'] as $route) {
      $this->assertSame(
        '',
        $this->extension->getUrl($route, [], TRUE),
        sprintf('A **non-linking uri** never carries a destination: "%s".', $route)
      );
    }
  }

  /**
   * Puts a real routed request on the stack for `<current>` to resolve from.
   *
   * `KernelTestBase` boots with a request whose route is `<none>`, which
   * `<current>` renders as the escaped path `/%3Cnone%3E` — a real answer, but
   * an artefact of the scaffolding rather than of this module. Pushing a
   * request that matches an actual route gives the two branches that read
   * `<current>` something a reader can recognise.
   */
  private function setCurrentRoute(string $route_name = 'system.admin', string $path = '/admin'): void {
    $stack = $this->container->get('request_stack');
    $request = Request::create($path);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(
      RouteObjectInterface::ROUTE_OBJECT,
      $this->container->get('router.route_provider')->getRouteByName($route_name)
    );
    // Carry the booted request's session across: this request becomes the
    // current one, and KernelTestBase::tearDown() clears the session off
    // whichever request that is.
    $current = $stack->getCurrentRequest();
    if ($current !== NULL && $current->hasSession()) {
      $request->setSession($current->getSession());
    }
    $stack->push($request);
  }

}
