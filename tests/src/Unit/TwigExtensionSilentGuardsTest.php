<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * Tests the silent guards the writing helpers open with.
 *
 * Six of the module's helpers open with `if (empty($build)) return $build;`;
 * three of those six follow it with `if (!is_array($build)) return $build;`;
 * and the same three then resolve a parents path behind
 * `if ($element && is_array($element))`. Nine guards, and not one of them says
 * anything. An empty value, a value that is neither an array nor a `Link`, and
 * a parents path that resolves to nothing all produce the same outcome — the
 * input, handed straight back, with no exception, no message and no log entry.
 *
 * This class covers that as one seam rather than repeating a criterion in five
 * other test classes, and it covers **every** helper carrying each guard
 * rather than a representative sample: each criterion drives its whole helper
 * list, and then checks that list against the class's own source, so a helper
 * that acquires a guard or loses one shows up here as a failure rather than as
 * silently missing coverage.
 *
 * The assertions are written to be **tier-stable**. A backlog candidate
 * proposes making these guards speak under the **debug gate**, and the
 * constraint that candidate has to be held to is that every criterion here
 * still holds with debugging off. So the extension is built with the gate
 * explicitly off, and what is asserted is the returned value and the absence
 * of a *signal* — a raised error, a logger channel, a message — never the
 * absence of any output whatsoever.
 *
 * No container is needed for the first three criteria: `Attribute`, `Element`,
 * `Link`, `NestedArray` and `Url` all construct without one. The fourth
 * installs one precisely so that a guard reaching for a logger or for the
 * messenger would be caught doing it.
 *
 * **One finding recorded, not fixed.** The family is not uniformly silent. The
 * three **children walkers** carry the empty guard but no non-array guard, so
 * `neo_child_class` and `neo_child_attribute` reach `Element::children()` with
 * whatever survives it — and `Element::children()` raises, rather than
 * shrugging: a `TypeError` for a value that is not an array at all, and an
 * `InvalidArgumentException` for a render array holding a scalar child.
 * `neo_property_class`, given the same non-array, is silent. That asymmetry is
 * the absence of a guard rather than the behaviour of one, so it is outside
 * these criteria and gets no assertion here; it belongs to a backlog candidate
 * of its own.
 */
#[Group('neo_twig')]
final class TwigExtensionSilentGuardsTest extends UnitTestCase {

  /**
   * The six helpers guarding on an empty value, keyed by their Twig name.
   */
  private const EMPTY_GUARDED = [
    'neo_class' => 'addClass',
    'neo_child_class' => 'addChildClass',
    'neo_property_class' => 'addPropertyClass',
    'neo_attributes' => 'mergeAttributes',
    'neo_attribute' => 'setAttribute',
    'neo_child_attribute' => 'setChildAttribute',
  ];

  /**
   * The three attribute writers, keyed by their Twig name.
   */
  private const ATTRIBUTE_WRITERS = [
    'neo_class' => 'addClass',
    'neo_attributes' => 'mergeAttributes',
    'neo_attribute' => 'setAttribute',
  ];

  /**
   * The extension under test, with the debug gate off.
   *
   * @var \Drupal\neo_twig\TwigExtension
   */
  private TwigExtension $extension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Explicitly off rather than merely absent: every criterion in this class
    // is a claim about what the guards do on a deployed environment.
    $this->extension = new TwigExtension(['debug' => FALSE]);
  }

  /**
   * It returns an empty value unchanged from every helper that guards on one.
   *
   * `empty()` is a wider net than the docblocks imply. It answers TRUE for
   * `NULL`, for the empty string, for `'0'`, for zero and for `FALSE` — all of
   * them shapes a template can produce out of a field that is simply not
   * filled in — and every one of them comes back out the way it went in, with
   * its type intact. Nothing is normalised to an array along the way.
   */
  public function testReturnsAnEmptyValueUnchangedFromEveryHelperThatGuardsOnOne(): void {
    foreach (self::EMPTY_GUARDED as $name => $method) {
      foreach (self::emptyValues() as $label => $value) {
        $this->assertSame(
          $value,
          $this->callHelper($method, $value),
          $name . ' hands ' . $label . ' straight back, with its type intact.'
        );
      }
    }

    $this->assertSame(
      [
        'addChildClass',
        'addClass',
        'addPropertyClass',
        'mergeAttributes',
        'setAttribute',
        'setChildAttribute',
      ],
      self::methodsCarrying('empty($build)'),
      'Those six are every helper in the class carrying the empty guard.'
    );
  }

  /**
   * It returns a non-array, non-Link value unchanged from each writer.
   *
   * The three **attribute writers** are the only helpers reaching this guard,
   * because they are the only ones that go on to resolve a key. It sits
   * *after* the `Link` branch, so what it catches is everything a template can
   * pipe that is neither a render array nor a `Link`: a string, a number,
   * `TRUE`, and any object at all — including the `Attribute`, `Url` and
   * `Markup` objects a preprocess function routinely puts into scope, which
   * are the shapes most likely to arrive here by mistake.
   *
   * Unchanged means untouched, not merely identical: an object that arrives is
   * the same object on the way out *and* still carries the same state, because
   * the writers never get as far as reaching into it.
   *
   * The `Link` half of the criterion is load-bearing, so it is asserted too: a
   * `Link` is reached through rather than passed over. That `neo_attributes`
   * nonetheless answers a `Link` unchanged is a separate, pinned defect inside
   * that branch, not this guard.
   *
   * `neo_field` tests `is_array($build)` as well, but answers `NULL` instead
   * of handing the value back, so it belongs to the **field-shape gate**'s
   * criteria rather than to this one. The census is keyed to the whole guard
   * for exactly that reason.
   *
   * @see \Drupal\Tests\neo_twig\Unit\TwigExtensionAttributesTest
   */
  public function testReturnsNonArrayNonLinkValueUnchangedFromEachAttributeWriter(): void {
    foreach (self::ATTRIBUTE_WRITERS as $name => $method) {
      foreach (self::nonArrayValues() as $label => $value) {
        $this->assertSame(
          $value,
          $this->callHelper($method, $value),
          $name . ' hands ' . $label . ' straight back.'
        );
      }
    }

    $attribute = new Attribute(['class' => ['kept']]);
    $url = Url::fromRoute('<front>');
    foreach (self::ATTRIBUTE_WRITERS as $method) {
      $this->callHelper($method, $attribute);
      $this->callHelper($method, $url);
    }

    $this->assertSame(
      ['kept'],
      $attribute['class']->value(),
      'An Attribute piped as the value is returned untouched, not written into.'
    );
    $this->assertSame(
      [],
      $url->getOptions(),
      'A bare Url piped as the value is untouched too: the reach-through is for a Link.'
    );

    $link = Link::fromTextAndUrl('Read on', Url::fromRoute('<front>'));

    $this->assertSame(
      $link,
      $this->extension->addClass($link, 'reached'),
      'A Link comes back as well — but by the branch above the guard, not by the guard.'
    );
    $this->assertSame(
      ['reached'],
      $link->getUrl()->getOptions()['attributes']['class'],
      'The non-Link half of the claim binds: a Link is written into, not passed over.'
    );

    $this->assertSame(
      ['addClass', 'mergeAttributes', 'setAttribute'],
      self::methodsCarrying('if (!is_array($build)) {'),
      'Those three are every helper whose non-array guard hands the value back.'
    );
  }

  /**
   * It leaves a render array untouched when the parents path resolves to none.
   *
   * Handed an array as the key, the three writers treat the last entry as the
   * key and everything before it as a parents path. `NestedArray::getValue()`
   * answers `NULL` for a path that is not there, and the mutation then sits
   * behind `if ($element && is_array($element))` — so a path that misses does
   * nothing whatsoever: no key is created along the way, no attribute set is
   * seeded at the end of it, and the array comes back exactly as it went in.
   *
   * Three shapes miss, not one. The path can be absent; it can land on a value
   * that is not an array; and — because the guard tests truthiness before it
   * tests type — it can land on an array that is merely **empty**, which is a
   * perfectly real render array for a template to be holding. All three are
   * silent and all three are covered.
   *
   * The three **children walkers** resolve no path of their own but pass the
   * key straight through to a writer, so an unreachable path is silent through
   * them too, and all six helpers are driven here. That also puts the empty
   * guard under the same key: the walk reaches a child holding an empty array
   * and hands it to a writer that gives it straight back. A child holding a
   * *scalar* is not reachable that way at all — `Element::children()` rejects
   * one outright — so the scalar case is a parents path landing on a render
   * property instead.
   */
  public function testLeavesRenderArrayUntouchedWhenParentsPathResolvesToNothing(): void {
    $build = self::fixtureBuild();

    foreach (self::EMPTY_GUARDED as $name => $method) {
      foreach (self::unreachablePaths() as $label => $key) {
        $this->assertSame(
          $build,
          $this->callHelper($method, $build, $key),
          $name . ' writes nothing and creates nothing when the path is ' . $label . '.'
        );
      }
    }

    $reached = $this->extension->addClass($build, 'added', ['child', 'attributes']);

    $this->assertSame(
      ['class' => ['added']],
      $reached['child']['#attributes'],
      'A path of the same shape that does resolve writes: the no-ops are the guard, not the key.'
    );

    $this->assertSame(
      ['addClass', 'mergeAttributes', 'setAttribute'],
      self::methodsCarrying('if ($element && is_array($element)) {'),
      'Those three are every helper in the class that resolves a parents path.'
    );
  }

  /**
   * It raises nothing, logs nothing and returns no message on any of them.
   *
   * The three criteria above say what each guard answers. This one says what
   * none of them does on the way there, over every path all three describe at
   * once — every empty value through all six helpers, every non-array value
   * through all three writers, and every unreachable path through all six.
   *
   * Silence is asserted as the absence of a **signal**, not as the absence of
   * output: no PHP error, warning, notice or deprecation is raised; no logger
   * channel is asked for; and the messenger is left with nothing in it. Those
   * are the three routes by which a template author could otherwise find out
   * that a filter did nothing, and today there are none.
   *
   * A container is installed for this criterion alone, so that a helper
   * reaching for `\Drupal::logger()` or `\Drupal::messenger()` is caught doing
   * it rather than fatalling on a missing container and being read as a
   * different failure.
   */
  public function testRaisesNothingLogsNothingAndReturnsNoMessageOnAnyGuardPath(): void {
    $channels = [];
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturnCallback(
      function (string $channel) use (&$channels): LoggerChannelInterface {
        $channels[] = $channel;
        return $this->createMock(LoggerChannelInterface::class);
      }
    );
    $messenger = new Messenger(new FlashBag(), new KillSwitch());

    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    $container->set('messenger', $messenger);
    \Drupal::setContainer($container);

    $raised = [];
    set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
      $raised[] = $severity . ': ' . $message;
      return TRUE;
    });

    // Collected rather than asserted inside the loop, so that the handler above
    // is in force around the helpers and nothing else.
    $returned = [];
    try {
      foreach (self::everyGuardPath() as $label => [$method, $build, $key]) {
        $returned[$label] = [$build, $this->callHelper($method, $build, $key)];
      }
    }
    finally {
      restore_error_handler();
    }

    foreach ($returned as $label => [$build, $result]) {
      $this->assertSame($build, $result, $label . ' came back exactly as it went in.');
    }

    $this->assertSame(
      [],
      $raised,
      'No guard path raised a PHP error, warning, notice or deprecation.'
    );
    $this->assertSame(
      [],
      $channels,
      'No guard path asked the logger factory for a channel.'
    );
    $this->assertSame(
      [],
      $messenger->all(),
      'No guard path left a message behind for the visitor.'
    );
  }

  /**
   * Every shape `empty()` answers TRUE for that a template can hand a helper.
   *
   * @return array<string, mixed>
   *   Values, keyed by how a failure message should name them.
   */
  private static function emptyValues(): array {
    return [
      'an empty array' => [],
      'NULL' => NULL,
      'an empty string' => '',
      'the string zero' => '0',
      'integer zero' => 0,
      'float zero' => 0.0,
      'FALSE' => FALSE,
    ];
  }

  /**
   * Values that are neither empty, nor an array, nor a `Link`.
   *
   * @return array<string, mixed>
   *   Values, keyed by how a failure message should name them.
   */
  private static function nonArrayValues(): array {
    return [
      'a string' => 'not a render array',
      'an integer' => 42,
      'a float' => 1.5,
      'TRUE' => TRUE,
      'a plain object' => new \stdClass(),
      'an Attribute object' => new Attribute(['class' => ['kept']]),
      'a Url object' => Url::fromRoute('<front>'),
      'a Markup object' => Markup::create('<em>rendered</em>'),
    ];
  }

  /**
   * A render array with something to reach and several things to miss.
   *
   * @return array
   *   A render array carrying a property holding a scalar, a property holding
   *   items, a child that resolves and a child holding an empty array.
   */
  private static function fixtureBuild(): array {
    return [
      '#type' => 'container',
      '#plain_text' => 'text',
      '#items' => [['#markup' => 'one']],
      'child' => ['#markup' => 'a'],
      'blank' => [],
    ];
  }

  /**
   * Parents paths that resolve to nothing in the fixture array.
   *
   * @return array<string, string[]>
   *   Key arguments, keyed by how a failure message should name them.
   */
  private static function unreachablePaths(): array {
    return [
      'a parent that is not there' => ['nowhere', 'attributes'],
      'two parents that are not there' => ['nowhere', 'deeper', 'attributes'],
      'a parent holding a scalar' => ['#plain_text', 'attributes'],
      'a parent holding an empty array' => ['blank', 'attributes'],
    ];
  }

  /**
   * Every guard path the three criteria above describe, as one flat list.
   *
   * @return array<string, array>
   *   Method name, value and key argument, keyed by how a failure message
   *   should name the path.
   */
  private static function everyGuardPath(): array {
    $paths = [];
    foreach (self::EMPTY_GUARDED as $name => $method) {
      foreach (self::emptyValues() as $label => $value) {
        $paths[$name . ' given ' . $label] = [$method, $value, 'attributes'];
      }
    }
    foreach (self::ATTRIBUTE_WRITERS as $name => $method) {
      foreach (self::nonArrayValues() as $label => $value) {
        $paths[$name . ' given ' . $label] = [$method, $value, 'attributes'];
      }
    }
    foreach (self::EMPTY_GUARDED as $name => $method) {
      foreach (self::unreachablePaths() as $label => $key) {
        $paths[$name . ' given a path through ' . $label] = [
          $method,
          self::fixtureBuild(),
          $key,
        ];
      }
    }

    return $paths;
  }

  /**
   * Names the methods whose bodies carry a given guard expression.
   *
   * Read out of the class's own source rather than hard-coded, because the
   * point of this ticket is that no helper is quietly exempt: a guard added to
   * a seventh helper, or renamed out of an existing one, has to show up as a
   * failure here rather than as coverage nobody notices is missing.
   *
   * @param string $guard
   *   The guard expression to look for.
   *
   * @return string[]
   *   The names of the methods carrying it, sorted.
   */
  private static function methodsCarrying(string $guard): array {
    $class = new \ReflectionClass(TwigExtension::class);
    $file = $class->getFileName();
    $source = $file ? file($file) : FALSE;
    if ($source === FALSE) {
      throw new \RuntimeException('The TwigExtension source could not be read.');
    }

    $found = [];
    foreach ($class->getMethods() as $method) {
      if ($method->getDeclaringClass()->getName() !== TwigExtension::class) {
        continue;
      }
      $start = $method->getStartLine();
      $end = $method->getEndLine();
      if ($start === FALSE || $end === FALSE) {
        continue;
      }
      $body = implode('', array_slice($source, $start - 1, $end - $start + 1));
      if (str_contains($body, $guard)) {
        $found[] = $method->getName();
      }
    }
    sort($found);

    return $found;
  }

  /**
   * Calls one of the guarded helpers with a value and a key.
   *
   * Each helper takes its own middle arguments, so this is the single place
   * that knows them; every criterion drives the helpers through it, so what
   * varies between cases is only the value and the key.
   *
   * @param string $method
   *   The method to call, as named in one of this class's helper lists.
   * @param mixed $build
   *   The value to hand it.
   * @param string|array $key
   *   The key argument: a bare attribute-set name, or a parents path whose
   *   last entry is that name.
   *
   * @return mixed
   *   Whatever the helper returned.
   */
  private function callHelper(string $method, mixed $build, string|array $key = 'attributes'): mixed {
    return match ($method) {
      'addClass' => $this->extension->addClass($build, 'added', $key),
      'addChildClass' => $this->extension->addChildClass($build, 'added', $key),
      'addPropertyClass' => $this->extension->addPropertyClass($build, 'added', 'items', $key),
      'mergeAttributes' => $this->extension->mergeAttributes($build, ['data-neo' => 'on'], $key),
      'setAttribute' => $this->extension->setAttribute($build, 'data-neo', 'on', $key),
      'setChildAttribute' => $this->extension->setChildAttribute($build, 'data-neo', 'on', $key),
    };
  }

}
