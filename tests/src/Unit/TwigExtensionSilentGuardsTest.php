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
 * **The three attribute writers now speak, and this class holds both halves.**
 * The four criteria above are unchanged and still run against an extension
 * built with the gate explicitly off, which is what roughly thirty deployed
 * sites run. Six more follow them, driving the same three guards on the same
 * three writers with the gate **on**: an empty value and a value that is
 * neither a render array nor a `Link` reach the **notice log** only, because
 * neither has a render array to attach anything to, and a parents path that
 * resolves to nothing reaches the log *and* the array the writer hands back.
 * They live here rather than in a class of their own precisely so that the
 * constraint and the new behaviour fail together: a notice that changed what
 * a writer returns breaks a characterisation criterion in the same run.
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
   * Notice lines written to the logger double, interpolated, in order.
   *
   * @var string[]
   */
  private array $logged = [];

  /**
   * The channel names the logger factory double was asked for, in order.
   *
   * @var string[]
   */
  private array $channels = [];

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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
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
   * answers `NULL` for a path that is not there, and the resolver they share
   * sits behind `if ($element && is_array($element))` — so a path that misses
   * does nothing whatsoever: no key is created along the way, no attribute set
   * is seeded at the end of it, and the array comes back exactly as it went in.
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
      ['resolveWriteTarget'],
      self::methodsCarrying('if ($element && is_array($element)) {'),
      'That one resolver is every place in the class resolving a parents path.'
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
   * It returns every value unchanged from all three writers in both states.
   *
   * The three criteria above pin the same pass-throughs with the **debug
   * gate** off, which is what roughly thirty deployed sites run. This one
   * repeats every guard path the three writers carry with the gate **on** as
   * well, because that is the state a notice exists in and the state in which
   * a diagnostic could accidentally become a behaviour change.
   *
   * Unchanged means the writer's job was not done: no attribute set was
   * seeded, no key was created along a path that missed, and a scalar or an
   * object comes back with its type and its state intact. With the gate off
   * the array is byte-identical; with it on the **inline notice** the seam
   * attaches is the only difference, and stripping that one property gives
   * back the array that went in, key for key and in the same order.
   *
   * That is the constraint the whole plan rests on, so it is asserted before
   * anything is asserted about a notice.
   */
  public function testReturnsEveryValueUnchangedFromAllThreeWritersInBothGateStates(): void {
    $this->installNoticeLogger();

    foreach (['off' => FALSE, 'on' => TRUE] as $state => $gate) {
      $extension = new TwigExtension(['debug' => $gate]);

      foreach (self::ATTRIBUTE_WRITERS as $name => $method) {
        foreach (self::emptyValues() as $label => $value) {
          $this->assertSame(
            $value,
            $this->callWriterOn($extension, $method, $value),
            'With the gate ' . $state . ', ' . $name . ' hands ' . $label . ' back, type intact.'
          );
        }

        foreach (self::nonArrayValues() as $label => $value) {
          $this->assertSame(
            $value,
            $this->callWriterOn($extension, $method, $value),
            'With the gate ' . $state . ', ' . $name . ' hands ' . $label . ' back, untouched.'
          );
        }

        foreach (self::unreachablePaths() as $label => $key) {
          $build = self::fixtureBuild();
          $result = $this->callWriterOn($extension, $method, $build, $key);

          $this->assertIsArray($result);
          $written = $result;
          unset($written['#suffix']);

          $this->assertSame(
            $build,
            $written,
            'With the gate ' . $state . ', ' . $name . ' wrote nothing through ' . $label . '.'
          );
          if ($gate === FALSE) {
            $this->assertSame(
              $build,
              $result,
              'With the gate off, ' . $name . ' returns the array byte-identically.'
            );
          }
        }
      }

      $attribute = new Attribute(['class' => ['kept']]);
      $url = Url::fromRoute('<front>');
      foreach (self::ATTRIBUTE_WRITERS as $method) {
        $this->callWriterOn($extension, $method, $attribute);
        $this->callWriterOn($extension, $method, $url);
      }

      $this->assertSame(
        ['kept'],
        $attribute['class']->value(),
        'With the gate ' . $state . ', an Attribute piped as the value is read, never written.'
      );
      $this->assertSame(
        [],
        $url->getOptions(),
        'With the gate ' . $state . ', a Url piped as the value is read, never written.'
      );
    }
  }

  /**
   * It notices an empty value from each writer, and attaches nothing inline.
   *
   * The empty guard is the one that fires most and the one that looks most
   * obviously harmless, and it is still the answer a stuck author cannot get
   * today: "your value was empty" is exactly what they went to the class to
   * find out. Every shape `empty()` answers TRUE for is driven through all
   * three writers, because a template produces all of them out of a field
   * that is simply not filled in.
   *
   * The notice is **log-only**, and that rule does not bend. An empty value
   * cannot carry an **inline notice**, because attaching one would make the
   * value truthy and a dev template reading `{% if thing %}` would render a
   * branch production does not — which is the one thing this plan promises
   * never to do. So the value comes back exactly as it went in and the log
   * carries the line.
   */
  public function testNoticesAnEmptyValueFromEachWriterAndAttachesNothingInline(): void {
    foreach (self::ATTRIBUTE_WRITERS as $name => $method) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => TRUE]);

      foreach (self::emptyValues() as $label => $value) {
        $before = count($this->logged);
        $result = $this->callWriterOn($extension, $method, $value);

        $this->assertCount(
          $before + 1,
          $this->logged,
          $name . ' says something when it is handed ' . $label . '.'
        );
        $this->assertStringContainsString(
          $name,
          $this->logged[$before],
          'The line for ' . $label . ' names ' . $name . '.'
        );
        $this->assertSame(
          $value,
          $result,
          $name . ' still hands ' . $label . ' straight back, type intact.'
        );
        $this->assertSame(
          '',
          self::attachedTextOn($result),
          'An empty value never carries an inline notice, so ' . $label . ' carries none.'
        );
      }

      $this->assertSame(
        ['neo_twig'],
        array_unique($this->channels),
        $name . " writes its notices to the module's own channel."
      );
    }
  }

  /**
   * It notices a non-array, non-Link value from each writer, silently inline.
   *
   * This guard sits after the `Link` branch, so what reaches it is everything
   * a template can pipe that the writer has nothing to write into: a string, a
   * number, `TRUE`, and any object at all — including the `Attribute`, `Url`
   * and `Markup` objects a preprocess routinely puts into scope, which are the
   * shapes most likely to arrive here by mistake and the ones whose failure
   * looks least like a mistake.
   *
   * Log-only again, and for a plainer reason than the empty guard's: there is
   * no render array to attach anything to. The value is handed back as the
   * same instance carrying the same state, because describing it is a read.
   *
   * A `Link` is asserted alongside, because the "non-Link" half of the
   * criterion is what keeps a notice off a value the writer handled: a `Link`
   * is reached through by the branch above and says nothing.
   */
  public function testNoticesNonArrayNonLinkValueFromEachWriterAndAttachesNothingInline(): void {
    foreach (self::ATTRIBUTE_WRITERS as $name => $method) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => TRUE]);

      foreach (self::nonArrayValues() as $label => $value) {
        $before = count($this->logged);
        $result = $this->callWriterOn($extension, $method, $value);

        $this->assertCount(
          $before + 1,
          $this->logged,
          $name . ' says something when it is handed ' . $label . '.'
        );
        $this->assertStringContainsString(
          $name,
          $this->logged[$before],
          'The line for ' . $label . ' names ' . $name . '.'
        );
        $this->assertSame(
          $value,
          $result,
          $name . ' still hands ' . $label . ' straight back.'
        );
        $this->assertSame(
          '',
          self::attachedTextOn($result),
          'There is no render array to attach to, so ' . $label . ' carries nothing.'
        );
      }

      $attribute = new Attribute(['class' => ['kept']]);
      $url = Url::fromRoute('<front>');
      $this->callWriterOn($extension, $method, $attribute);
      $this->callWriterOn($extension, $method, $url);

      $this->assertSame(
        ['kept'],
        $attribute['class']->value(),
        'Describing an Attribute on the way past ' . $name . ' is a read and nothing more.'
      );
      $this->assertSame(
        [],
        $url->getOptions(),
        'Describing a Url on the way past ' . $name . ' is a read and nothing more.'
      );

      $before = count($this->logged);
      $link = Link::fromTextAndUrl('Read on', Url::fromRoute('<front>'));
      $this->callWriterOn($extension, $method, $link);

      $this->assertCount(
        $before,
        $this->logged,
        $name . ' says nothing about a Link: the branch above handled it.'
      );
    }
  }

  /**
   * It notices an unreachable path and attaches that to the array handed back.
   *
   * This is the mysterious one. A real render array went in, a real render
   * array came out, and nothing about it changed — no exception, no empty
   * value, nothing a template author can see from inside a template. It is
   * also the only one of the three guards with somewhere to put a notice, so
   * it is the only one that gets both surfaces: the line goes to the **notice
   * log** and the same line is attached to the array the writer hands back,
   * where it renders inside core's own Twig-debug output markers.
   *
   * All three ways a path misses are driven, because they are three different
   * mistakes: a parent that is simply not there, a parent holding a scalar,
   * and a parent holding an array that is merely **empty** — which is a
   * perfectly real render array for a template to be holding and the one an
   * author is least likely to suspect.
   *
   * The two surfaces are asserted against each other rather than separately.
   * The log deduplicates per request, so the two paths that both resolve to
   * nothing leave one line between them; the inline notice never
   * deduplicates, because it belongs to the element that failed and
   * suppressing the second copy would put it beside the wrong element. Four
   * attachments and three lines per writer is that rule, stated as a count.
   */
  public function testNoticesAnUnreachableParentsPathFromEachWriterAndAttachesItInline(): void {
    foreach (self::ATTRIBUTE_WRITERS as $name => $method) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => TRUE]);

      foreach (self::unreachablePaths() as $label => $key) {
        $result = $this->callWriterOn($extension, $method, self::fixtureBuild(), $key);

        $this->assertStringContainsString(
          $name,
          self::attachedTextOn($result),
          $name . ' attaches its notice to the array it hands back for ' . $label . '.'
        );
      }

      $this->assertCount(
        3,
        $this->logged,
        $name . ' logs one line per distinct message: two paths resolve to nothing.'
      );
      foreach ($this->logged as $line) {
        $this->assertStringContainsString(
          $name,
          $line,
          'Every line ' . $name . ' logged for an unreachable path names it.'
        );
      }
    }
  }

  /**
   * It names the writer's registered name and what it expected, every time.
   *
   * A notice has one job, and it is not "something went wrong": it is to tell
   * an author which filter they typed and what that filter wanted, in the
   * vocabulary they typed it in. So the name in the line is `neo_class`, never
   * `addClass` — the PHP method behind the registered name is not something a
   * template author has ever seen, and a message built out of `__FUNCTION__`
   * would read perfectly well and still name the wrong thing. That is asserted
   * both ways round for exactly that reason.
   *
   * Every guard path all three writers carry is driven through this at once —
   * every empty value, every non-array value and every unreachable path — and
   * both surfaces are read, because an inline notice that dropped the name
   * would be a line beside an element saying nothing an author can act on.
   *
   * The two expectations are checked as words rather than as identity: the
   * two guards that hand nothing writable back say they wanted a render array
   * or a `Link`, and the resolver says it wanted a key that reaches an element
   * it can write into. Which of the two a path gets is the actual diagnosis,
   * so the criterion pins that each path gets its own.
   */
  public function testNamesTheWritersRegisteredNameAndWhatItExpectedInEveryNotice(): void {
    $writable = 'a render array or a Link';
    $reachable = 'a key that resolves to an element it can write into';

    foreach (self::ATTRIBUTE_WRITERS as $name => $method) {
      foreach (self::guardPathsPerWriter() as $label => [$value, $key, $expected]) {
        $this->installNoticeLogger();
        $extension = new TwigExtension(['debug' => TRUE]);
        $result = $this->callWriterOn($extension, $method, $value, $key);

        $surfaces = ['the log line' => $this->logged[0] ?? ''];
        if ($expected === $reachable) {
          $surfaces['the inline notice'] = self::attachedTextOn($result);
        }

        foreach ($surfaces as $surface => $text) {
          $this->assertNotSame('', $text, $name . ' produced ' . $surface . ' for ' . $label . '.');
          $this->assertStringContainsString(
            $name,
            $text,
            $surface . ' for ' . $label . ' names ' . $name . '.'
          );
          $this->assertStringNotContainsString(
            $method,
            $text,
            $surface . ' for ' . $label . ' never names the PHP method behind ' . $name . '.'
          );
          $this->assertStringContainsString(
            $expected,
            $text,
            $surface . ' for ' . $label . ' says what ' . $name . ' expected.'
          );
        }
      }
    }

    $this->assertSame(
      [$writable, $reachable],
      array_values(array_unique(array_column(self::guardPathsPerWriter(), 2))),
      'Two expectations, and each guard path gets the one that diagnoses it.'
    );
  }

  /**
   * Every guard path one attribute writer carries, with what it diagnoses.
   *
   * The three writers carry the same three guards, so the list is written
   * once and driven through each of them rather than repeated per writer.
   * Each row is the value to hand over, the key argument, and the phrase the
   * notice for that path has to carry — which is the actual diagnosis, and
   * therefore the part a criterion has to pin rather than assume.
   *
   * @return array<string, array>
   *   Value, key argument and expected phrase, keyed by how a failure message
   *   should name the path.
   */
  private static function guardPathsPerWriter(): array {
    $writable = 'a render array or a Link';
    $reachable = 'a key that resolves to an element it can write into';

    $paths = [];
    foreach (self::emptyValues() as $label => $value) {
      $paths['an empty value, ' . $label] = [$value, 'attributes', $writable];
    }
    foreach (self::nonArrayValues() as $label => $value) {
      $paths['a value that is ' . $label] = [$value, 'attributes', $writable];
    }
    foreach (self::unreachablePaths() as $label => $key) {
      $paths['a path through ' . $label] = [self::fixtureBuild(), $key, $reachable];
    }

    return $paths;
  }

  /**
   * It notices nothing when a writer writes successfully.
   *
   * A notice is made by a guard, and a guard only fires on an early return, so
   * a write that lands says nothing at all — not a log line, and not so much
   * as a resolved logger channel. That is what keeps the diagnostic a
   * diagnostic: on a page where the filters are doing their job the log is
   * empty even with the **debug gate** on, and the only lines in it belong to
   * calls that genuinely did nothing.
   *
   * Both shapes of a successful write are driven, because they take different
   * routes through the resolver: a bare key, which is prefixed and written
   * onto the element itself, and a parents path that resolves, which is
   * written into a child and committed back through it. The write is asserted
   * too, so that "nothing was noticed" cannot be passing because nothing
   * happened.
   *
   * A write that lands somewhere nobody reads is deliberately outside this:
   * no guard fires there either, so no notice can see it. That is the failure
   * the target resolver removed rather than one a notice reports.
   */
  public function testNoticesNothingWhenTheWriterWritesSuccessfully(): void {
    foreach (self::ATTRIBUTE_WRITERS as $name => $method) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => TRUE]);

      $onElement = $this->callWriterOn($extension, $method, self::fixtureBuild(), 'attributes');
      $onChild = $this->callWriterOn($extension, $method, self::fixtureBuild(), ['child', 'attributes']);

      $this->assertNotEmpty(
        $onElement['#attributes'] ?? [],
        $name . ' wrote to the element itself, so there is a success to be quiet about.'
      );
      $this->assertNotEmpty(
        $onChild['child']['#attributes'] ?? [],
        $name . ' wrote through a path that resolves, so there is a success to be quiet about.'
      );

      $this->assertSame(
        '',
        self::attachedTextOn($onElement),
        $name . ' attaches nothing to an element it wrote to.'
      );
      $this->assertSame(
        '',
        self::attachedTextOn($onChild),
        $name . ' attaches nothing to an array it wrote through.'
      );
      $this->assertSame(
        '',
        self::attachedTextOn($onChild['child']),
        $name . ' attaches nothing to the child it wrote into either.'
      );
      $this->assertSame(
        [],
        $this->logged,
        $name . ' logs nothing at all when it does its job.'
      );
      $this->assertSame(
        [],
        $this->channels,
        $name . ' does not even resolve a logger channel when it does its job.'
      );
    }
  }

  /**
   * Installs a container carrying a recording logger factory double.
   *
   * The notice seam resolves its logger lazily from the container, the way
   * `neo_oembed` resolves its services, so a criterion about what reaches the
   * **notice log** needs one. Nothing but the factory is in it: a helper
   * reaching for any other service is caught rather than silently served.
   */
  private function installNoticeLogger(): void {
    $this->logged = [];
    $this->channels = [];

    $channel = $this->createMock(LoggerChannelInterface::class);
    $channel->method('debug')->willReturnCallback(
      function ($message, $context = []): void {
        $this->logged[] = strtr((string) $message, array_map(
          static fn($replacement): string => (string) $replacement,
          $context
        ));
      }
    );

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturnCallback(
      function ($name) use ($channel): LoggerChannelInterface {
        $this->channels[] = $name;
        return $channel;
      }
    );

    $container = new ContainerBuilder();
    $container->set('logger.factory', $factory);
    \Drupal::setContainer($container);
  }

  /**
   * Calls one of the three attribute writers on a given extension.
   *
   * The class's own `callHelper()` drives the gate-off extension built in
   * `setUp()`. The notice criteria need the same three writers driven on an
   * extension with the gate on, so the instance is a parameter here and the
   * middle arguments stay in one place.
   *
   * @param \Drupal\neo_twig\TwigExtension $extension
   *   The extension to call, in whichever gate state the criterion needs.
   * @param string $method
   *   The method to call, as named in self::ATTRIBUTE_WRITERS.
   * @param mixed $build
   *   The value to hand it.
   * @param string|array $key
   *   The key argument: a bare attribute-set name, or a parents path whose
   *   last entry is that name.
   *
   * @return mixed
   *   Whatever the writer returned.
   */
  private function callWriterOn(TwigExtension $extension, string $method, mixed $build, string|array $key = 'attributes'): mixed {
    return match ($method) {
      'addClass' => $extension->addClass($build, 'added', $key),
      'mergeAttributes' => $extension->mergeAttributes($build, ['data-neo' => 'on'], $key),
      'setAttribute' => $extension->setAttribute($build, 'data-neo', 'on', $key),
    };
  }

  /**
   * What an element carries after its own output, as a reader would read it.
   *
   * Tags stripped and entities decoded, because every criterion here is about
   * the words a notice carries and never about the box they arrive in — the
   * same reasoning `neo_inspect`'s own coverage took about its styling.
   *
   * @param mixed $build
   *   Whatever a writer answered with.
   *
   * @return string
   *   The attachment's text, or the empty string when there is none.
   */
  private static function attachedTextOn($build): string {
    $suffix = is_array($build) ? (string) ($build['#suffix'] ?? '') : '';
    return trim(html_entity_decode(strip_tags($suffix), ENT_QUOTES));
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
