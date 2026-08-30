<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\ErrorHandler\BufferingLogger;
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
 *
 * **Two of those answers now say so, and two deliberately do not.** The `/`
 * fallback and the empty string for a **non-linking uri** are give-ups, and
 * each makes a **helper notice** when the **debug gate** is on. An absent uri
 * resolving the current route and a uri the second pass resolves after the
 * first threw are answers rather than jobs left undone, and stay silent — so
 * the silence is asserted here too, beside the notices, because the two are
 * one decision and would drift apart in two classes.
 *
 * All four criteria live here rather than beside the guard characterisation in
 * the unit class, for the reason that class already gives: the silent pair can
 * only be driven where the container can resolve a route. Reading the **notice
 * log** works the way the `neo_oembed` class reads its one unconditional
 * error, through a `BufferingLogger` tagged `logger`, because the seam logs
 * through `\Drupal::logger()` and there is no seam to inject through.
 */
#[Group('neo_twig')]
final class TwigExtensionUriTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The service id of the logger the notices are asserted against.
   */
  private const LOGGER_SERVICE = 'neo_twig_test.buffering_logger';

  /**
   * The extension under test.
   */
  private TwigExtension $extension;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The notice seam logs through `\Drupal::logger()`, so the assertions need
    // a logger inside the container rather than a database table to read back.
    $container->register(self::LOGGER_SERVICE, BufferingLogger::class)
      ->addTag('logger');
  }

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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Symfony's BufferingLogger prints whatever is still buffered when it is
    // destroyed, which would dress a failed assertion up as an unrelated
    // error. Emptying it here keeps a failure reading as the failure it is.
    if (isset($this->container) && $this->container->has(self::LOGGER_SERVICE)) {
      $this->cleanLogs();
    }
    parent::tearDown();
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
   * Tests that it answers the same url from every path, in both gate states.
   *
   * The criteria above drive these paths with the **debug gate** off, which is
   * the state every deployed site runs in. This one repeats all of
   * them with the gate on as well, because that is the state a notice exists
   * in and the state in which a diagnostic could accidentally become a
   * behaviour change.
   *
   * Nothing about any answer moves. `/` is still the answer when both
   * resolution passes threw — the fallback three past bug fixes were about —
   * and the empty string is still the answer for a **non-linking uri**, which
   * is the guard the most recent of those fixes added. Neither becomes a
   * different url, a `NULL` or an exception because a notice was written
   * beside it, and the four paths that were never give-ups answer exactly what
   * they always answered.
   */
  public function testAnswersTheSameUrlFromEveryPathInBothGateStates(): void {
    $this->setCurrentRoute();

    foreach (['off' => FALSE, 'on' => TRUE] as $state => $gate) {
      $extension = new TwigExtension(['debug' => $gate]);

      $this->assertSame(
        '/',
        $extension->getUrl('not a uri'),
        'With the gate ' . $state . ', a uri neither pass can resolve still answers "/".'
      );
      $this->assertSame(
        '/',
        $extension->getUrl('entity:node/1'),
        'With the gate ' . $state . ', a uri whose route does not exist still answers "/".'
      );

      foreach (['<nolink>', '<none>', '<button>', 'route:<nolink>'] as $route) {
        $this->assertSame(
          '',
          $extension->getUrl($route),
          'With the gate ' . $state . ', "' . $route . '" still answers the empty string.'
        );
      }

      $this->assertSame(
        '/admin',
        $extension->getUrl('route:system.admin'),
        'With the gate ' . $state . ', a routed uri still resolves on the first pass.'
      );
      $this->assertSame(
        '/admin',
        $extension->getUrl('/admin'),
        'With the gate ' . $state . ', a rooted path still resolves on the second.'
      );
      $this->assertSame(
        '/admin',
        $extension->getUrl(''),
        'With the gate ' . $state . ', an empty uri still resolves the current route.'
      );
      $this->assertSame(
        '/admin?destination=/admin',
        $extension->getUrl('route:system.admin', [], TRUE),
        'With the gate ' . $state . ', the destination flag still writes a destination.'
      );
    }
  }

  /**
   * Tests that it notices the fallback to "/" when both passes threw.
   *
   * The give-up this helper's own history argues hardest for. Three of the
   * module's commits are bug fixes to `neo_uri`, and the `/` fallback is what
   * every one of them was about: rather than fatalling the page it is printed
   * on, an unresolvable uri quietly becomes a link to the front page. From
   * inside a template that is indistinguishable from a link that was meant to
   * go there, which is why it is the one answer here that most needs a line.
   *
   * Both ways to reach it are driven, because they arrive from different
   * places and mean the same thing: a uri with no scheme that is not user
   * input either, and a uri whose `Url` builds happily and only raises when it
   * is asked to stringify. Both passes failed in each case, so both say the
   * same thing — one reason, said one way.
   *
   * The answer does not move. It is still `/`, and the notice is log-only:
   * `neo_uri` answers a string, so there is nothing to carry an **inline
   * notice**.
   */
  public function testNoticesTheFallbackToSlashWhenBothResolutionPathsThrew(): void {
    $both_threw = [
      'a uri with no scheme and no leading "/", "#" or "?"' => 'not a uri',
      'a uri whose route does not exist' => 'entity:node/1',
    ];

    $said = [];
    foreach ($both_threw as $label => $uri) {
      $this->cleanLogs();
      $extension = new TwigExtension(['debug' => TRUE]);

      $this->assertSame(
        '/',
        $extension->getUrl($uri),
        $label . ' still answers "/".'
      );

      $lines = $this->noticeLines();

      $this->assertCount(1, $lines, 'neo_uri says something about ' . $label . '.');
      $this->assertStringContainsString(
        'neo_uri',
        $lines[0],
        'The line for ' . $label . ' names neo_uri.'
      );
      $this->assertStringNotContainsString(
        'getUrl',
        $lines[0],
        'The line for ' . $label . ' never names the PHP method behind it.'
      );
      $this->assertStringContainsString(
        $uri,
        $lines[0],
        'The line for ' . $label . ' says which uri it could not resolve.'
      );
      $said[$label] = $this->expectationIn($lines[0]);
    }

    $this->assertCount(
      1,
      array_unique($said),
      'Both passes failing is one reason, however the second one came about.'
    );
  }

  /**
   * Tests that it notices the empty answer for a non-linking uri.
   *
   * `<nolink>`, `<none>` and `<button>` are deliberately path-less, and
   * answering the empty string for them is correct: an un-updated template
   * degrades to a dead href rather than navigating somewhere the author never
   * named. It is also invisible. An author who piped a link field holding
   * `<nolink>` into `neo_uri` sees an anchor that goes nowhere and has no way
   * to find out that the helper did that deliberately, which is the whole
   * complaint this plan is about.
   *
   * All three routes are one reason, in both the bare form a component author
   * writes into an examples block and the `route:` form a saved link field
   * produces, so they say one thing — and it is emphatically not the thing the
   * `/` fallback says, which is asserted here because those two are the pair
   * an author is most likely to confuse.
   */
  public function testNoticesTheEmptyAnswerForNonLinkingUris(): void {
    $non_linking = ['<nolink>', '<none>', '<button>', 'route:<nolink>', 'route:<nolink>;foo=bar'];

    $said = [];
    foreach ($non_linking as $uri) {
      $this->cleanLogs();
      $extension = new TwigExtension(['debug' => TRUE]);

      $this->assertSame(
        '',
        $extension->getUrl($uri),
        '"' . $uri . '" still answers the empty string.'
      );

      $lines = $this->noticeLines();

      $this->assertCount(1, $lines, 'neo_uri says something about "' . $uri . '".');
      $this->assertStringContainsString(
        'neo_uri',
        $lines[0],
        'The line for "' . $uri . '" names neo_uri.'
      );
      $this->assertStringNotContainsString(
        'getUrl',
        $lines[0],
        'The line for "' . $uri . '" never names the PHP method behind it.'
      );
      $said[$uri] = $this->expectationIn($lines[0]);
    }

    $this->assertCount(
      1,
      array_unique($said),
      'Three non-linking routes in two forms are one reason, said one way.'
    );

    $this->cleanLogs();
    $extension = new TwigExtension(['debug' => TRUE]);
    $extension->getUrl('not a uri');

    $this->assertNotSame(
      reset($said),
      $this->expectationIn($this->noticeLines()[0]),
      'A uri that links nowhere on purpose is not a uri that could not be resolved.'
    );
  }

  /**
   * Tests that it notices nothing for an absent uri or a second-pass answer.
   *
   * Two of `neo_uri`'s early answers are answers rather than give-ups, and the
   * plan is explicit that both stay silent. A uri that is not there at all
   * resolves the current route, which is documented behaviour and exactly what
   * a template printing a link to the page it is on wants. A uri the first
   * pass rejects and the second resolves is a **successful two-stage
   * resolution** — every rooted path, bare fragment and query string a
   * template holds takes that route, so a notice there would fire on the most
   * ordinary call this helper receives and drown the two that matter.
   *
   * The criterion ends by proving it is not passing on silence: the same
   * extension, on the same request, says something the moment both passes
   * really do fail. Without that line an implementation that noticed nothing
   * anywhere would satisfy this test.
   */
  public function testNoticesNothingForAnAbsentUriOrOneResolvedByItsSecondPass(): void {
    $this->setCurrentRoute();
    $this->cleanLogs();
    $extension = new TwigExtension(['debug' => TRUE]);

    foreach (['the empty string' => '', 'NULL' => NULL, 'the string "0"' => '0'] as $label => $uri) {
      $this->assertSame(
        '/admin',
        $extension->getUrl($uri),
        'An absent uri given as ' . $label . ' resolves the current route.'
      );
    }

    $second_pass = [
      '/admin' => '/admin',
      '/not/a/route' => '/not/a/route',
      '#frag' => '#frag',
      '?a=b' => '?a=b',
    ];
    foreach ($second_pass as $uri => $expected) {
      $this->assertSame(
        $expected,
        $extension->getUrl($uri),
        '"' . $uri . '" is resolved by the second pass after the first threw.'
      );
    }

    $this->assertSame(
      [],
      $this->noticeLines(),
      'Neither is a give-up, so neither says anything at all.'
    );

    // Not passing on silence: the same extension, on the same request, says
    // something the moment both passes really do fail.
    $this->assertSame('/', $extension->getUrl('not a uri'));
    $this->assertCount(
      1,
      $this->noticeLines(),
      'The give-up beside them is noticed, so the silence above is a decision.'
    );
  }

  /**
   * The notices on the module's own channel, as a reader would see them.
   *
   * @return string[]
   *   One line per notice, in the order written.
   */
  private function noticeLines(): array {
    $lines = [];
    foreach ($this->cleanLogs() as [$level, $message, $context]) {
      if (($context['channel'] ?? '') !== 'neo_twig') {
        continue;
      }
      $this->assertSame(RfcLogLevel::DEBUG, $level, 'A notice is logged at debug level.');
      $lines[] = strtr((string) $message, array_map(
        static fn ($replacement): string => (string) $replacement,
        array_filter($context, static fn ($key): bool => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY)
      ));
    }
    return $lines;
  }

  /**
   * The "expected …" half of a notice line, without the value it describes.
   *
   * Criteria about two reasons reading differently compare this rather than
   * the whole line, because the description of what arrived differs between
   * two reasons anyway — so comparing whole lines would pass even where both
   * reasons said they expected the same thing.
   *
   * @param string $line
   *   A notice line as a reader would see it.
   *
   * @return string
   *   What the notice said it expected.
   */
  private function expectationIn(string $line): string {
    $found = preg_match('/expected (.*), received /', $line, $matches);
    $this->assertSame(1, $found, 'The notice says what it expected: ' . $line);
    return $matches[1];
  }

  /**
   * Returns and discards everything logged since the last call.
   *
   * @return array
   *   The buffered log records, each `[level, message, context]`.
   */
  private function cleanLogs(): array {
    return $this->container->get(self::LOGGER_SERVICE)->cleanLogs();
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
