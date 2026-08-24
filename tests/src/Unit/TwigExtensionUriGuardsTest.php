<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Core\Render\Markup;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the guard clauses neo_uri answers before it resolves anything.
 *
 * `neo_uri` is the module's most-called **Twig helper** — seventy call sites in
 * one site — and the one its own history argues hardest for: three of the
 * module's commits are bug fixes to it, and every one of them was a shape the
 * author had not considered. Two of those three are pinned here.
 *
 * The split from @see \Drupal\Tests\neo_twig\Kernel\TwigExtensionUriTest
 * follows what needs a container. Everything below is reached before any `Url`
 * call the container could answer: a **non-linking uri** returns before the
 * first `Url::fromUri()`, and the two shapes that fall through to resolution
 * are rejected by `Url::fromUri()` and `Url::fromUserInput()` on their argument
 * alone, so both throw and the `/` fallback answers without a router. The
 * destination flag is off in every test here, because that is the one branch
 * above the guards that does reach the container.
 *
 * The extension is constructed directly with no twig-config array. `getUrl()`
 * never reads the **debug gate**, so nothing here needs one.
 */
#[Group('neo_twig')]
final class TwigExtensionUriGuardsTest extends UnitTestCase {

  /**
   * The extension under test.
   */
  private TwigExtension $extension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->extension = new TwigExtension();
  }

  /**
   * Tests that it answers the empty string for a non-linking route.
   *
   * Both forms a template can hold: the bare name a component author writes
   * into an examples block, and the `route:` form `Url::toUriString()` produces
   * when a link field is saved. The routed form may carry route parameters
   * after a `;`, which the check discards before comparing.
   */
  public function testAnswersTheEmptyStringForNonLinkingRoutes(): void {
    foreach (['<nolink>', '<none>', '<button>'] as $route) {
      $this->assertSame(
        '',
        $this->extension->getUrl($route),
        sprintf('The bare form "%s" is a **non-linking uri**.', $route)
      );
      $this->assertSame(
        '',
        $this->extension->getUrl('route:' . $route),
        sprintf('The routed form "route:%s" is the same **non-linking uri**.', $route)
      );
      $this->assertSame(
        '',
        $this->extension->getUrl('route:' . $route . ';foo=bar'),
        'Route parameters after the ";" do not stop the routed form matching.'
      );
    }

    // The list is exactly those three. `<front>` is a route that goes
    // somewhere, so it falls through to resolution like any other uri — which
    // container-free is the '/' fallback, and is emphatically not ''.
    $this->assertNotSame(
      '',
      $this->extension->getUrl('<front>'),
      '<front> links somewhere, so it is not a **non-linking uri**.'
    );
  }

  /**
   * Tests that it treats a non-string uri as something to resolve.
   *
   * The check is `is_string()`-gated on purpose: a template passes whatever it
   * holds, and a `Url` object or a `MarkupInterface` is not a route name even
   * when it prints like one. The same three characters answer differently
   * depending only on their PHP type, which is the behaviour a later change to
   * this guard would have to say out loud.
   */
  public function testTreatsNonStringUrisAsSomethingToResolve(): void {
    $this->assertSame(
      '',
      $this->extension->getUrl('<nolink>'),
      'As a string, "<nolink>" is a **non-linking uri**.'
    );
    $this->assertSame(
      '/',
      $this->extension->getUrl(Markup::create('<nolink>')),
      'As markup it is not, so it falls through to resolution and both passes'
      . ' reject it — the "/" fallback, not the empty string.'
    );
    $this->assertSame(
      '/',
      $this->extension->getUrl(123),
      'A non-string that is not even stringable resolves the same way.'
    );
  }

  /**
   * Tests that it accepts NULL options in place of an options array.
   *
   * Templates commonly read a value that does not exist — `link.options` on a
   * link that has none — and it arrives here as NULL. The parameter is
   * nullable and the value is normalised on the first line, so every branch
   * below it sees an array. Answering exactly what an empty array answers is
   * the whole of the behaviour.
   */
  public function testAcceptsNullOptionsInPlaceOfAnOptionsArray(): void {
    $this->assertSame(
      '',
      $this->extension->getUrl('<nolink>', NULL),
      'NULL options reach the **non-linking uri** guard without raising.'
    );
    $this->assertSame(
      '/',
      $this->extension->getUrl('not a uri', NULL),
      'NULL options reach both resolution passes and the "/" fallback.'
    );
    $this->assertSame(
      $this->extension->getUrl('not a uri', []),
      $this->extension->getUrl('not a uri', NULL),
      'NULL and an empty array are the same call.'
    );
  }

}
