<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\OEmbed\Provider;
use Drupal\media\OEmbed\Resource;
use Drupal\media\OEmbed\ResourceException;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media_test_oembed\UrlResolver;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Tests what neo_oembed builds from an oEmbed resource.
 *
 * The module's only network-shaped **Twig helper**, and the only place it
 * resolves services statically out of the container — five `\Drupal::` lookups
 * inside one method. Three resource types produce three different elements: a
 * link element, an image element, and an iframe element carrying core's oEmbed
 * formatter library. Two paths produce nothing at all — an empty url returns
 * before any service is touched, and a resource that cannot be fetched is
 * logged and answered with an empty array — and the configured iframe domain,
 * when a site sets one, overrides the base url the iframe is built against.
 *
 * **Nothing leaves the container.** `media_test_oembed` swaps the oEmbed url
 * resolver and provider repository for versions that answer from state, and
 * every test below pins the resource url through
 * `UrlResolver::setEndpointUrl()`, so the resolver never looks a provider up.
 * The resource fetcher — the one service that would make the request — is
 * replaced per test with a double returning the resource that case is about.
 *
 * Installing `media` here declares no dependency on it: `neo_twig.info.yml`
 * and `composer.json` are untouched, and the lazy static lookups the method
 * makes stay exactly as they are. It is also the reason this is a kernel test
 * rather than a unit one — there is no seam to inject through.
 *
 * **One of those two silent paths now says so and the other is left alone.**
 * The url that was never given makes a **helper notice** when the **debug
 * gate** is on, log-only, like every other silent give-up in the module. The
 * resource that could not be fetched already logs an error unconditionally and
 * loudly, which is right for a remote call, so it is pinned here as unchanged
 * rather than routed through the seam — a second line beside it would be a
 * regression, not an improvement.
 */
#[Group('neo_twig')]
final class TwigExtensionOembedTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'media_test_oembed',
  ];

  /**
   * The service id of the logger the failure path is asserted against.
   */
  private const LOGGER_SERVICE = 'neo_twig_test.buffering_logger';

  /**
   * The url every test resolves, and the one the elements are built against.
   */
  private const OEMBED_URL = 'https://example.com/watch/12345';

  /**
   * The extension under test.
   */
  private TwigExtension $extension;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The failure path logs through `\Drupal::logger()`, so the assertion needs
    // a logger inside the container rather than a database table to read back.
    $container->register(self::LOGGER_SERVICE, BufferingLogger::class)
      ->addTag('logger');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // `media.settings` carries `iframe_domain`, which the last test sets and
    // the rest rely on being its installed default of NULL.
    $this->installConfig(['media']);
    // The iframe element is built with `Url::fromRoute('media.oembed_iframe')`,
    // and the provider cannot answer for that route until the router is built.
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
   * Tests that it answers an empty array for an empty url.
   *
   * The guard sits above the try block, so no service is resolved at all —
   * which is asserted by leaving the resource fetcher as the real one that
   * would have to make a request.
   */
  public function testAnswersEmptyArrayForEmptyUrl(): void {
    $this->assertSame(
      [],
      $this->extension->getOembed(''),
      'An empty url is answered with an empty array.'
    );
  }

  /**
   * Tests that it answers an empty array and logs an unfetchable resource.
   *
   * The one branch that says anything at all when it declines to build. What
   * it says is the resource url and the exception message, at error level, on
   * the module's own channel.
   */
  public function testAnswersEmptyArrayAndLogsWhenResourceCannotBeFetched(): void {
    $this->stubResourceFetcher(
      $this->createFailingFetcher(new ResourceException('Unable to fetch.', self::OEMBED_URL))
    );
    $this->cleanLogs();

    $this->assertSame(
      [],
      $this->extension->getOembed(self::OEMBED_URL),
      'A resource that cannot be fetched is answered with an empty array.'
    );

    $logs = $this->cleanLogs();
    $this->assertCount(1, $logs, 'The failure is logged exactly once.');
    $this->assertSame(
      'Could not retrieve the remote URL (@url): %error',
      $logs[0][1],
      'The message names the url and the error.'
    );
    $this->assertSame(
      self::OEMBED_URL,
      $logs[0][2]['@url'],
      'The url logged is the one the helper was given.'
    );
    $this->assertSame(
      'Unable to fetch.',
      $logs[0][2]['%error'],
      'With no previous exception, the ResourceException message is logged.'
    );
  }

  /**
   * Tests that it builds a link element for a link resource.
   *
   * The url the element links to is the one the helper was given, not the one
   * the resource itself reports — so the resource below deliberately carries a
   * different url, and the element must not pick it up.
   */
  public function testBuildsLinkElementForLinkResource(): void {
    $this->stubResource(
      Resource::link('https://example.com/canonical', $this->provider(), 'A linked resource')
    );

    $build = $this->extension->getOembed(self::OEMBED_URL);

    $this->assertSame('link', $build['#type'], 'A link resource builds a link element.');
    $this->assertSame('A linked resource', $build['#title'], 'The resource title is the link text.');
    $this->assertInstanceOf('Drupal\Core\Url', $build['#url']);
    $this->assertSame(
      self::OEMBED_URL,
      $build['#url']->toString(),
      'The element links to the url given, not the url the resource reports.'
    );
  }

  /**
   * Tests that it builds an image element for a photo resource.
   *
   * Asserted as the whole array, because what the branch leaves out matters as
   * much as what it puts in: the resource title is dropped, so nothing here
   * carries an alt or a title attribute.
   */
  public function testBuildsImageElementForPhotoResource(): void {
    $this->stubResource(
      Resource::photo('https://example.com/photo.jpg', 480, 360, $this->provider(), 'A photo resource')
    );

    $this->assertSame(
      [
        '#theme' => 'image',
        '#uri' => 'https://example.com/photo.jpg',
        '#width' => 480,
        '#height' => 360,
        '#attributes' => [
          'loading' => 'lazy',
        ],
      ],
      $this->extension->getOembed(self::OEMBED_URL),
      'A photo resource builds an image element from the resource url and its dimensions.'
    );
  }

  /**
   * Tests that it builds an iframe element for a video resource.
   *
   * Everything that is not a link and not a photo lands here, and the element
   * is core's: an iframe pointed at the `media.oembed_iframe` route, signed
   * with the hash that route validates, carrying core's oEmbed formatter
   * library. The resource's own dimensions win over the maximums given.
   */
  public function testBuildsIframeElementForVideoResourceWithFormatterLibrary(): void {
    $this->stubResource(
      Resource::video('<iframe src="https://example.com/embed"></iframe>', 640, 480, $this->provider(), 'A video resource')
    );

    $build = $this->extension->getOembed(self::OEMBED_URL, 320, 240);

    $this->assertSame('html_tag', $build['#type']);
    $this->assertSame('iframe', $build['#tag'], 'A video resource builds an iframe.');
    $this->assertSame(
      ['media/oembed.formatter'],
      $build['#attached']['library'],
      'The element attaches core oEmbed formatter library.'
    );
    $this->assertSame(640, $build['#attributes']['width'], 'The resource width wins over the maximum.');
    $this->assertSame(480, $build['#attributes']['height'], 'The resource height wins over the maximum.');
    $this->assertSame(['media-oembed-content'], $build['#attributes']['class']);
    $this->assertSame('lazy', $build['#attributes']['loading']);
    $this->assertFalse($build['#attributes']['scrolling']);
    $this->assertSame(
      'A video resource',
      $build['#attributes']['title'],
      'A resource with a title titles the iframe.'
    );

    $src = $build['#attributes']['src'];
    $this->assertStringStartsWith(
      'http://localhost/media/oembed?',
      $src,
      'With no iframe domain configured the iframe is built against the site itself.'
    );
    parse_str((string) parse_url($src, PHP_URL_QUERY), $query);
    $this->assertSame(
      [
        'url' => self::OEMBED_URL,
        'max_width' => '320',
        'max_height' => '240',
        'hash' => $this->container->get('media.oembed.iframe_url_helper')
          ->getHash(self::OEMBED_URL, 320, 240),
      ],
      $query,
      'The url, both maximums and the signing hash are carried in the query.'
    );
  }

  /**
   * Tests that it honours the configured iframe domain when one is set.
   *
   * A site that serves oEmbed iframes from a separate domain sets
   * `media.settings:iframe_domain`, and it replaces the base url the iframe is
   * generated against — the path, the query and the hash are unchanged.
   */
  public function testHonoursConfiguredIframeDomain(): void {
    $this->config('media.settings')
      ->set('iframe_domain', 'https://media.example.com')
      ->save();
    $this->stubResource(
      Resource::video('<iframe src="https://example.com/embed"></iframe>', 640, 480, $this->provider(), 'A video resource')
    );

    $src = $this->extension->getOembed(self::OEMBED_URL, 320, 240)['#attributes']['src'];

    $this->assertStringStartsWith(
      'https://media.example.com/media/oembed?',
      $src,
      'The configured iframe domain replaces the base url.'
    );
    parse_str((string) parse_url($src, PHP_URL_QUERY), $query);
    $this->assertSame(
      [
        'url' => self::OEMBED_URL,
        'max_width' => '320',
        'max_height' => '240',
        'hash' => $this->container->get('media.oembed.iframe_url_helper')
          ->getHash(self::OEMBED_URL, 320, 240),
      ],
      $query,
      'Only the base url moves; the query and the hash are untouched.'
    );
  }

  /**
   * Tests that it answers an empty array for an absent url, in both states.
   *
   * The criterion above drives this path with the **debug gate** off, which is
   * the state every deployed site runs in. This one repeats it with
   * the gate on as well, because that is the state a notice exists in and the
   * state in which a diagnostic could accidentally become a behaviour change.
   *
   * The answer does not move: an empty array, which a template renders as
   * nothing at all, whichever state the gate is in. `empty()` is what decides
   * the branch, so the string `'0'` is an absent url too and answers the same.
   */
  public function testAnswersEmptyArrayForAnAbsentUrlInBothGateStates(): void {
    foreach (['off' => FALSE, 'on' => TRUE] as $state => $gate) {
      $extension = new TwigExtension(['debug' => $gate]);

      $this->assertSame(
        [],
        $extension->getOembed(''),
        'With the gate ' . $state . ', an empty url is still answered with an empty array.'
      );
      $this->assertSame(
        [],
        $extension->getOembed('0'),
        'With the gate ' . $state . ', "0" is an absent url too, because empty() decides.'
      );
    }
  }

  /**
   * Tests that it notices an absent url and leaves the failure log untouched.
   *
   * Two paths answer nothing, and this plan treats them completely
   * differently, which is the whole content of this criterion.
   *
   * **The url that was never given** is a silent give-up like every other in
   * the module: a template piped a field that turned out to be empty, the
   * helper answered an empty array, and nothing said so. It gains a **helper
   * notice**, log-only — there is no render array to carry an **inline
   * notice**, and an empty array could never carry one anyway. The guard sits
   * above the try block, so the resource fetcher is left as the real one that
   * would have to make a request: a notice that resolved a service on its way
   * out would be caught here rather than passing quietly.
   *
   * **The resource that could not be fetched** already says so, at error
   * level, unconditionally, on the module's own channel — which is right for a
   * remote call that failed and is nothing this plan improves. So it is
   * asserted to be **exactly as it was**: one record, still an error and not a
   * debug notice, still worded the way the module has always worded it, and
   * with no second line beside it. An implementation that routed it through
   * the seam "for consistency" would double a log entry on every deployed site
   * with debugging on and change an error into two lines.
   */
  public function testNoticesTheEmptyAnswerForAnAbsentUrlAndLeavesTheResourceFailureLogUntouched(): void {
    $extension = new TwigExtension(['debug' => TRUE]);
    $this->cleanLogs();

    $this->assertSame(
      [],
      $extension->getOembed(''),
      'An absent url is still answered with an empty array.'
    );

    $notices = $this->neoTwigLogs();

    $this->assertCount(1, $notices, 'neo_oembed says something about a url it was never given.');
    $this->assertSame(
      RfcLogLevel::DEBUG,
      $notices[0][0],
      'A developer diagnostic behind a developer switch is logged at debug level.'
    );

    $line = $this->lineOf($notices[0]);

    $this->assertStringContainsString('neo_oembed', $line, 'The line names neo_oembed.');
    $this->assertStringNotContainsString(
      'getOembed',
      $line,
      'And never the PHP method behind the registered name.'
    );

    // The resource failure is untouched: one record, still an error, still
    // worded exactly as it always was, and nothing beside it.
    $this->stubResourceFetcher(
      $this->createFailingFetcher(new ResourceException('Unable to fetch.', self::OEMBED_URL))
    );
    $this->cleanLogs();

    $this->assertSame(
      [],
      $extension->getOembed(self::OEMBED_URL),
      'A resource that cannot be fetched is still answered with an empty array.'
    );

    $failure = $this->neoTwigLogs();

    $this->assertCount(
      1,
      $failure,
      'The failure is still logged exactly once, with no notice added beside it.'
    );
    $this->assertSame(
      RfcLogLevel::ERROR,
      $failure[0][0],
      'It is still an error, not a debug notice.'
    );
    $this->assertSame(
      'Could not retrieve the remote URL (@url): %error',
      $failure[0][1],
      'The message is worded exactly as it always was.'
    );
    $this->assertSame(
      self::OEMBED_URL,
      $failure[0][2]['@url'],
      'And it still names the url the helper was given.'
    );
  }

  /**
   * Everything on the module's own channel since the last call, discarded.
   *
   * @return array
   *   The buffered records for `neo_twig`, each `[level, message, context]`.
   */
  private function neoTwigLogs(): array {
    return array_values(array_filter(
      $this->cleanLogs(),
      static fn (array $record): bool => ($record[2]['channel'] ?? '') === 'neo_twig'
    ));
  }

  /**
   * A log record as a reader would see it, placeholders filled in.
   *
   * @param array $record
   *   A buffered record, `[level, message, context]`.
   *
   * @return string
   *   The interpolated line.
   */
  private function lineOf(array $record): string {
    return strtr((string) $record[1], array_map(
      static fn ($replacement): string => (string) $replacement,
      array_filter($record[2], static fn ($key): bool => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY)
    ));
  }

  /**
   * Returns the provider the fixture resources are attributed to.
   *
   * @return \Drupal\media\OEmbed\Provider
   *   A provider whose single endpoint is the one pinned in state.
   */
  private function provider(): Provider {
    return new Provider('Example', 'https://example.com', [
      ['url' => 'https://example.com/oembed'],
    ]);
  }

  /**
   * Pins the resource url in state and answers the given resource for it.
   *
   * @param \Drupal\media\OEmbed\Resource $resource
   *   The resource the fetcher answers with.
   */
  private function stubResource(Resource $resource): void {
    $fetcher = $this->createMock(ResourceFetcherInterface::class);
    $fetcher->method('fetchResource')->willReturn($resource);
    $this->stubResourceFetcher($fetcher);
  }

  /**
   * Builds a resource fetcher that throws instead of answering.
   *
   * @param \Drupal\media\OEmbed\ResourceException $exception
   *   The exception the fetcher throws.
   *
   * @return \Drupal\media\OEmbed\ResourceFetcherInterface
   *   The failing fetcher.
   */
  private function createFailingFetcher(ResourceException $exception): ResourceFetcherInterface {
    $fetcher = $this->createMock(ResourceFetcherInterface::class);
    $fetcher->method('fetchResource')->willThrowException($exception);
    return $fetcher;
  }

  /**
   * Replaces the resource fetcher, and pins the resource url in state.
   *
   * Pinning the url is what keeps the url resolver — `media_test_oembed`'s,
   * which reads state first — from looking a provider up over the network.
   *
   * @param \Drupal\media\OEmbed\ResourceFetcherInterface $fetcher
   *   The fetcher to put in the container.
   */
  private function stubResourceFetcher(ResourceFetcherInterface $fetcher): void {
    UrlResolver::setEndpointUrl(self::OEMBED_URL, 'https://example.com/oembed?url=' . urlencode(self::OEMBED_URL));
    $this->container->set('media.oembed.resource_fetcher', $fetcher);
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

}
