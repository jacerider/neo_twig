<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the inline surface of the helper notice seam.
 *
 * The notice log takes every notice, and it is the only surface a helper
 * answering `NULL`, a string or a `Link` can reach. This is the other one: the
 * case where a helper is about to hand back a **non-empty render array** and
 * nothing about it changed, which is exactly the failure a template author
 * cannot see. The same line the log gets is attached to that array, so it
 * renders where the element renders — inside core's own Twig-debug output
 * markers, which name the template that made the call without any helper
 * needing to know what template it is in.
 *
 * A caller opts in by handing the seam the array it is about to return. One
 * that hands over nothing gets exactly the log-only behaviour the seam already
 * had, which is why every helper answering something that is not a render
 * array needs no change at all.
 *
 * Two rules here are absolute, and both are asserted:
 *
 * 1. **An empty value never carries an inline notice.** Attaching to one would
 *    make an empty value truthy, and a dev template that reads
 *    `{% if thing %}` would start rendering a branch it does not render in
 *    production. The whole promise of this plan is that nothing renders
 *    differently, so an empty value gets the log line and nothing else.
 * 2. **Inline notices are not deduplicated.** The log's per-request
 *    deduplication is what keeps a page from leaving fifty identical lines,
 *    but an inline notice belongs to the element that failed. Suppressing the
 *    second one would leave the notice beside the first element and nothing
 *    beside the second, which points an author at the wrong call site — worse
 *    than not having it.
 *
 * **Content, not markup.** Which element carries a notice, what the notice
 * says, that an empty value never carries one and that an existing attachment
 * survives are the behaviour. The box styling is `neo_inspect`'s and a
 * maintainer should stay free to restyle it, so nothing here pins a border, a
 * colour or a tag name — only the words a reader has to be able to find and
 * the escaping that keeps them safe to print.
 *
 * The seam is protected, so it is reached through a probe subclass at the
 * bottom of this file, the way a helper reaches it.
 */
#[Group('neo_twig')]
final class TwigExtensionInlineNoticeTest extends UnitTestCase {

  /**
   * The channel test double the container hands back, or NULL if not installed.
   */
  private ?object $channel = NULL;

  /**
   * The logger factory test double, or NULL if not installed.
   */
  private ?object $factory = NULL;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * It attaches nothing to the element when twig debugging is off.
   *
   * This is the half that every deployed site runs, and the criterion is
   * byte-identical output: the element handed over comes back as the same
   * array, with the same keys in the same order and nothing added to it, so
   * the renderer emits exactly what it emitted before this plan existed.
   *
   * The array is what comes back, not the value that arrived — a caller that
   * hands over the array it is about to return is handed that array back so
   * the guard can stay a single `return`. That holds in both gate states, and
   * asserting it here is what stops the gate-off path quietly answering the
   * wrong one of the two.
   *
   * Both ways the gate can be off are driven: explicitly `FALSE`, and an
   * extension constructed with no twig configuration at all.
   */
  public function testAttachesNothingToTheElementWhenTwigDebuggingIsOff(): void {
    $this->installLogger();

    foreach (['explicitly off' => ['debug' => FALSE], 'absent entirely' => []] as $label => $config) {
      $probe = new InlineNoticeProbe($config);
      $build = ['#type' => 'link', '#title' => 'Read on', '#url' => 'https://example.com'];

      $returned = $probe->emit('neo_property_class', 'a key that resolves to a render array', NULL, $build);

      $this->assertSame(
        $build,
        $returned,
        'With the gate ' . $label . ', the element comes back exactly as it went in.'
      );
      $this->assertArrayNotHasKey(
        '#suffix',
        $returned,
        'With the gate ' . $label . ', nothing is attached to the element.'
      );
      $this->assertSame(
        [],
        $this->entries(),
        'With the gate ' . $label . ', nothing reaches the log either.'
      );
    }
  }

  /**
   * It attaches the notice to a non-empty render array it is about to return.
   *
   * The case the inline surface exists for. A real value went in, a real value
   * came out, and nothing about it changed — the one failure a template author
   * has no way to see, and the one where there is something to hang an answer
   * on.
   *
   * The attachment carries the same three things the log line does, because a
   * notice read on the page and a notice read in the log have to be the same
   * notice: the **registered name** a template author types, what the helper
   * expected, and what actually turned up, in `neo_inspect`'s own words. That
   * last one is compared against the line the log received rather than against
   * a second copy of the wording, so the two surfaces cannot drift apart.
   *
   * The element itself survives untouched: every key it arrived with is still
   * there, still with the same value, because a diagnostic that edited the
   * element would be the behaviour change this plan promises never to make.
   */
  public function testAttachesTheNoticeToTheNonEmptyRenderArrayItIsAboutToReturn(): void {
    $this->installLogger();
    $probe = new InlineNoticeProbe(['debug' => TRUE]);

    $build = ['#type' => 'link', '#title' => 'Read on', '#url' => 'https://example.com'];

    $returned = $probe->emit('neo_property_class', 'a key that resolves to a render array', NULL, $build);

    $this->assertIsArray($returned, 'The element is what the helper is handed back.');
    foreach ($build as $key => $value) {
      $this->assertSame($value, $returned[$key], 'The element still carries its own ' . $key . '.');
    }

    $text = self::attachedTextOn($returned);

    $this->assertNotSame('', $text, 'The element carries a notice.');
    $this->assertStringContainsString(
      'neo_property_class',
      $text,
      'The notice names the registered name a template author types.'
    );
    $this->assertStringContainsString(
      'a key that resolves to a render array',
      $text,
      'The notice says what the helper expected.'
    );
    $this->assertStringContainsString(
      'NULL',
      $text,
      "The notice says what arrived, in neo_inspect's words."
    );
    $this->assertSame(
      [$this->lines()[0]],
      [$text],
      'The notice on the page and the notice in the log are the same line.'
    );
  }

  /**
   * It never attaches a notice to an empty value.
   *
   * The rule that cannot bend. An empty value is falsy, an inline notice makes
   * it truthy, and a dev template guarding on `{% if thing %}` would start
   * rendering a branch that production does not render — which is the one
   * thing this plan promises never to do, for the one audience that has the
   * gate on.
   *
   * Every shape `empty()` answers TRUE for is driven, not just the empty array,
   * because a helper hands over whatever it is about to return and the seam is
   * the thing that decides. Each comes back identical and with its type intact:
   * `'0'` is not `0`, `FALSE` is not `NULL`, and an empty array is still an
   * empty array rather than an array of one attachment.
   *
   * The log is unaffected. An empty value is the most common early return in
   * the module and "your value was empty" is exactly the answer a stuck author
   * cannot get today, so it still gets its line — it just gets it in the one
   * place that cannot change what renders.
   */
  public function testNeverAttachesTheNoticeToAnEmptyValue(): void {
    $this->installLogger();
    $probe = new InlineNoticeProbe(['debug' => TRUE]);

    $empty = [
      'an empty array' => [],
      'an empty string' => '',
      'the string zero' => '0',
      'integer zero' => 0,
      'float zero' => 0.0,
      'FALSE' => FALSE,
    ];

    foreach ($empty as $label => $value) {
      $this->assertSame(
        $value,
        $probe->emit('neo_class', 'a render array or a Link', $value, $value),
        $label . ' piped into a helper comes straight back, type intact and nothing attached.'
      );
      $this->assertSame(
        $value,
        $probe->emit('neo_field', 'a field name on the entity', 'field_' . $label, $value),
        $label . ' handed back after something else arrived is still handed back untouched.'
      );
    }

    $this->assertCount(
      2 * count($empty),
      $this->entries(),
      'Every one of them still said its piece in the log.'
    );
  }

  /**
   * It keeps whatever the element already had attached and adds the notice.
   *
   * An element that already carries an attachment is an ordinary element —
   * a wrapper a preprocess added, a marker some other module put there — and
   * a diagnostic that quietly deleted it would be a rendering change dressed
   * up as a message. The notice arrives beside what was already there, after
   * it, so the element's own trailing output still comes out first.
   *
   * Both shapes an attachment arrives as are driven: the plain string most
   * code writes, and the safe-markup object the render system hands around.
   * Neither is escaped into visibility by the notice landing next to it —
   * a `<span>` that rendered as a span keeps rendering as a span.
   */
  public function testKeepsWhateverTheElementAlreadyHadAttachedAndAddsTheNoticeBesideIt(): void {
    $this->installLogger();
    $probe = new InlineNoticeProbe(['debug' => TRUE]);

    $existing = [
      'a plain string' => '<span class="already-here">kept</span>',
      'a markup object' => Markup::create('<span class="already-here">kept</span>'),
    ];

    foreach ($existing as $label => $attachment) {
      $build = ['#markup' => 'the element', '#suffix' => $attachment];

      $returned = $probe->emit('neo_class', 'a render array or a Link', 'not a render array', $build);

      $raw = self::attachedTo($returned);

      $this->assertStringContainsString(
        '<span class="already-here">kept</span>',
        $raw,
        $label . ' that was already attached is still attached, and still markup.'
      );
      $this->assertStringContainsString(
        'neo_class',
        self::attachedTextOn($returned),
        'The notice is attached beside ' . $label . ' rather than instead of it.'
      );
      $this->assertLessThan(
        strpos($raw, 'neo_class'),
        strpos($raw, 'already-here'),
        "The element's own trailing output still comes first.",
      );
      $this->assertSame(
        'the element',
        $returned['#markup'],
        'The element itself is untouched.'
      );
    }
  }

  /**
   * It escapes the value description it renders.
   *
   * The description is the only part of a notice that carries content the
   * module did not write. A field's value, a token's output or a stray string
   * from a template all reach it, so it is printed as text and never as
   * markup — otherwise the diagnostic for "this did not work" becomes a way
   * to inject script into a developer's own page.
   *
   * Asserted both ways: the dangerous form never appears in what is attached,
   * and the words themselves survive the escaping, so a reader still sees the
   * value they piped in rather than a hole where it was.
   */
  public function testEscapesTheValueDescriptionItRenders(): void {
    $this->installLogger();
    $probe = new InlineNoticeProbe(['debug' => TRUE]);

    $build = ['#markup' => 'the element'];
    $returned = $probe->emit(
      'neo_class',
      'a render array or a Link',
      '<script>alert(1)</script>',
      $build
    );

    $raw = self::attachedTo($returned);

    $this->assertStringNotContainsString(
      '<script>',
      $raw,
      'What arrived is printed as text, never as markup.'
    );
    $this->assertStringContainsString(
      '&lt;script&gt;',
      $raw,
      'It is escaped rather than stripped.'
    );
    $this->assertStringContainsString(
      '<script>alert(1)</script>',
      self::attachedTextOn($returned),
      'A reader still sees the value they piped in.'
    );
  }

  /**
   * It attaches a second notice for a second failing element.
   *
   * The log is deduplicated per request because fifty identical lines make a
   * log useless. The inline surface is deliberately not, because an inline
   * notice belongs to the element that failed: suppress the second one and the
   * author finds a notice beside the first element and nothing beside the
   * second, which sends them to the wrong call site — worse than having no
   * notice at all.
   *
   * Driven with the message that deduplicates hardest: the same helper, the
   * same expectation and two values that describe identically, so the log
   * writes one line and both elements still carry their own copy of it.
   */
  public function testAttachesTheSecondNoticeToTheSecondFailingElement(): void {
    $this->installLogger();
    $probe = new InlineNoticeProbe(['debug' => TRUE]);

    $first = ['#markup' => 'the first element'];
    $second = ['#markup' => 'the second element'];

    $first = $probe->emit('neo_class', 'a render array or a Link', 'not a render array', $first);
    $second = $probe->emit('neo_class', 'a render array or a Link', 'not a render array', $second);

    $this->assertStringContainsString(
      'neo_class',
      self::attachedTextOn($first),
      'The first failing element carries the notice.'
    );
    $this->assertSame(
      self::attachedTextOn($first),
      self::attachedTextOn($second),
      'So does the second, and it is the same notice.'
    );
    $this->assertCount(
      1,
      $this->entries(),
      'The log still writes one line for a message it has already written.'
    );
  }

  /**
   * It still logs every notice it attaches.
   *
   * The two surfaces are not alternatives. The log is the complete transcript
   * of everything a request tripped — the only place an author can read the
   * whole story of a page rather than the parts that happened to have a render
   * array to hang on — so a notice going inline never means it skipped the log.
   *
   * Three distinct notices are driven across three elements, so the assertion
   * is about a set rather than about one line surviving: each element carries
   * its own notice, and the log carries all three, in the order they happened,
   * at debug level.
   */
  public function testStillLogsEveryNoticeItAttaches(): void {
    $this->installLogger();
    $probe = new InlineNoticeProbe(['debug' => TRUE]);

    $attached = [];
    $notices = [
      ['neo_class', 'a render array or a Link', 'not a render array'],
      ['neo_attribute', 'a render array or a Link', 42],
      ['neo_property_class', 'a key that resolves to a render array', NULL],
    ];

    foreach ($notices as $index => [$name, $expected, $received]) {
      $element = $probe->emit($name, $expected, $received, ['#markup' => 'element ' . $index]);
      $attached[] = self::attachedTextOn($element);
    }

    foreach ($attached as $index => $text) {
      $this->assertNotSame('', $text, 'Element ' . $index . ' carries a notice.');
    }
    $this->assertSame(
      $attached,
      $this->lines(),
      'Every notice attached to an element is in the log too, in the order it happened.'
    );
    $this->assertSame(
      ['debug', 'debug', 'debug'],
      array_column($this->entries(), 'level'),
      'A developer diagnostic behind a developer switch stays at debug level.'
    );
  }

  /**
   * Installs a container carrying a logger factory test double.
   *
   * The seam resolves its logger lazily from the container. Nothing but the
   * factory is in it, so a seam reaching for any other service is caught
   * rather than silently served.
   */
  private function installLogger(): void {
    $this->channel = new class() implements LoggerChannelInterface {
      use LoggerTrait;

      /**
       * Every entry written, as level, message and context.
       *
       * @var array[]
       */
      public array $entries = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->entries[] = [
          'level' => $level,
          'message' => (string) $message,
          'context' => $context,
        ];
      }

      /**
       * {@inheritdoc}
       */
      public function setRequestStack(?RequestStack $requestStack = NULL) {}

      /**
       * {@inheritdoc}
       */
      public function setCurrentUser(?AccountInterface $current_user = NULL) {}

      /**
       * {@inheritdoc}
       */
      public function setLoggers(array $loggers) {}

      /**
       * {@inheritdoc}
       */
      public function addLogger(LoggerInterface $logger, $priority = 0) {}

    };

    $this->factory = new class($this->channel) implements LoggerChannelFactoryInterface {

      /**
       * Constructs the factory double.
       *
       * @param object $target
       *   The channel every request is answered with.
       */
      public function __construct(protected object $target) {}

      /**
       * {@inheritdoc}
       */
      public function get($channel) {
        return $this->target;
      }

      /**
       * {@inheritdoc}
       */
      public function addLogger(LoggerInterface $logger, $priority = 0) {}

    };

    $container = new ContainerBuilder();
    $container->set('logger.factory', $this->factory);
    \Drupal::setContainer($container);
  }

  /**
   * The entries written to the channel double.
   *
   * @return array[]
   *   Level, message and context per entry, in the order written.
   */
  private function entries(): array {
    return $this->channel?->entries ?? [];
  }

  /**
   * The entries written, interpolated down to the line a reader would see.
   *
   * @return string[]
   *   One line per entry, in the order written.
   */
  private function lines(): array {
    return array_map(
      static fn(array $entry): string => strtr($entry['message'], array_map(
        static fn($replacement): string => (string) $replacement,
        $entry['context']
      )),
      $this->entries()
    );
  }

  /**
   * The raw markup a notice was attached to an element as.
   *
   * Anything that is not an element carries nothing, so a seam that answered
   * the wrong one of its two candidates fails on the criterion rather than on
   * a type error in this file.
   *
   * @param mixed $build
   *   Whatever the seam answered with.
   *
   * @return string
   *   Everything attached after the element's own output.
   */
  private static function attachedTo($build): string {
    return is_array($build) ? (string) ($build['#suffix'] ?? '') : '';
  }

  /**
   * What an element's attachment says, as a reader would read it.
   *
   * Tags stripped and entities decoded, because the criteria here are about
   * the words a notice carries and never about the box they arrive in.
   *
   * @param mixed $build
   *   Whatever the seam answered with.
   *
   * @return string
   *   The attachment's text.
   */
  private static function attachedTextOn($build): string {
    return trim(html_entity_decode(strip_tags(self::attachedTo($build)), ENT_QUOTES));
  }

}

/**
 * Exposes the protected notice seam the way a helper reaches it.
 */
final class InlineNoticeProbe extends TwigExtension {

  /**
   * Calls the seam, optionally handing over an array it is about to return.
   *
   * @param string $name
   *   The helper's registered name.
   * @param string $expected
   *   One short phrase saying what the helper expected.
   * @param mixed $received
   *   The value that actually arrived.
   * @param mixed $build
   *   The render array the helper is about to hand back, or NULL when it has
   *   none to hand over.
   *
   * @return mixed
   *   Whatever the seam answers.
   */
  public function emit(string $name, string $expected, $received, $build = NULL) {
    return $this->notice($name, $expected, $received, $build);
  }

}
