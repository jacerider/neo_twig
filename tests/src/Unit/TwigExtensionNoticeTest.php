<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\LoggerTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the helper notice seam and the debug gate in front of it.
 *
 * Every silent early return in this module is about to hand two things to one
 * protected method — the helper's **registered name** and one short phrase
 * saying what it expected — plus the value that actually arrived. That method
 * is the whole diagnostic: it decides whether anything happens at all, what
 * the line reads, and how often it is written. Seventeen guards call it and
 * none of them repeats any of that, so every criterion the plan makes about a
 * notice is a criterion about this one seam.
 *
 * Three properties are what the seam exists for, and each is asserted here:
 *
 * 1. **The gate comes first.** Before a value is described, a message is
 *    formatted or a logger is resolved, `twig.config.debug` is read. That is
 *    what makes it acceptable to call this from the module's hottest surface
 *    on every deployed site, where the gate is off and the only cost
 *    is one boolean. "Describes nothing" is asserted rather than assumed: the
 *    value handed over counts the times it is asked to stringify itself, so a
 *    seam that describes first and gates second fails here.
 * 2. **It changes nothing.** The value the helper handed over comes straight
 *    back, with its type intact, in both gate states — and nothing is raised
 *    or thrown, including when the container cannot answer for a logger at
 *    all. A template that worked keeps working everywhere.
 * 3. **The log is deduplicated per request.** The guard that fires most is the
 *    benign empty-value one, and fifty identical lines on one page make a log
 *    useless. One entry per distinct message, however many call sites produce
 *    it; a different helper or a different expectation is a different message
 *    and gets its own line.
 *
 * The vocabulary for "what it received" is not invented here. It is
 * `neo_inspect`'s own one-phrase description, which the characterisation suite
 * already pins through that helper, so the criterion for it is written as a
 * comparison against what `neo_inspect` prints for the very same value rather
 * than as a second copy of the wording. A notice that drifted from
 * `neo_inspect` would fail even if both still read plausibly.
 *
 * This is the one place in the module's unit coverage that needs a container,
 * because the seam resolves the logger lazily the way `neo_oembed` already
 * does. It is still a `UnitTestCase`: the container carries a logger factory
 * test double and nothing else.
 *
 * The seam is protected, so it is reached through a probe subclass at the
 * bottom of this file — the same way a helper reaches it, and without pinning
 * any of the fifteen public signatures the plan promises not to move.
 */
#[Group('neo_twig')]
final class TwigExtensionNoticeTest extends UnitTestCase {

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
   * It logs nothing and describes nothing when twig debugging is off.
   *
   * The gate is the first thing the seam does, and this criterion is what
   * says so. Two observations, because "no log line" alone would also be true
   * of a seam that built the whole message and then threw it away:
   *
   * - the logger factory is never asked for a channel, so no service is
   *   resolved, and
   * - the value that arrived is never asked to describe itself, so
   *   `neo_inspect`'s description was never run over it.
   *
   * The second is the load-bearing half. Describing a value walks it, reads
   * its class and stringifies it, and doing that on every early return across
   * a page's worth of helper calls is exactly the cost deployed sites
   * must not pay. The value handed over here counts every stringification, so
   * a seam that describes before it reads the gate fails on a count, not on a
   * message.
   *
   * Both ways the gate can be off are covered: explicitly `FALSE`, and an
   * extension constructed with no twig configuration at all, which is the
   * shape every other unit test in this module builds.
   */
  public function testLogsNothingAndDescribesNothingWhenTwigDebuggingIsOff(): void {
    $this->installLogger();

    foreach (['explicitly off' => ['debug' => FALSE], 'absent entirely' => []] as $label => $config) {
      $probe = new NoticeProbe($config);
      $spy = new DescribeSpy('a value nobody should have looked at');

      $probe->emit('neo_class', 'a render array or a Link', $spy);
      $probe->emit('neo_field', 'a field name on the entity', NULL);
      $probe->emit('neo_uri', 'a resolvable uri', ['#type' => 'link']);

      $this->assertSame(
        0,
        $spy->described,
        'With the gate ' . $label . ', the value that arrived is never described.'
      );
      $this->assertSame(
        [],
        $this->entries(),
        'With the gate ' . $label . ', nothing reaches the log.'
      );
      $this->assertSame(
        [],
        $this->channels(),
        'With the gate ' . $label . ', the logger factory is never asked for a channel.'
      );
    }
  }

  /**
   * It logs one line naming the helper, what it expected and what it received.
   *
   * With the gate on, one call to the seam leaves exactly one entry on the
   * module's own logger channel, at **debug** level — a developer diagnostic
   * behind a developer switch, where anything higher would make an ordinary
   * empty value look like a fault.
   *
   * The entry is one line. Seventeen guards feed this seam and a notice that
   * wrapped would be unreadable next to the rest of a request's log, so the
   * line carries all three things and nothing a reader has to decode: the
   * helper, what it expected, and what actually turned up.
   *
   * The name is the **registered name** a template author types — `neo_class`
   * — and never `addClass`, the PHP method behind it, which is not something a
   * template author has ever seen. That is asserted both ways round, because a
   * message built from `__FUNCTION__` would read plausibly and still name the
   * wrong thing.
   */
  public function testLogsOneLineNamingTheHelperWhatItExpectedAndWhatItReceived(): void {
    $this->installLogger();

    (new NoticeProbe(['debug' => TRUE]))
      ->emit('neo_class', 'a render array or a Link', 'not a render array');

    $this->assertSame(
      ['neo_twig'],
      $this->channels(),
      "The notice goes to the module's own channel, asked for once."
    );
    $this->assertCount(1, $this->entries(), 'One call to the seam leaves one entry.');
    $this->assertSame(
      LogLevel::DEBUG,
      $this->entries()[0]['level'],
      'A developer diagnostic behind a developer switch is logged at debug level.'
    );

    $line = $this->lines()[0];

    $this->assertStringNotContainsString("\n", $line, 'A notice is one line.');
    $this->assertStringContainsString(
      'neo_class',
      $line,
      'It names the registered name a template author types.'
    );
    $this->assertStringNotContainsString(
      'addClass',
      $line,
      'It never names the PHP method behind the registered name.'
    );
    $this->assertStringContainsString(
      'a render array or a Link',
      $line,
      'It says what the helper expected, in the words the helper supplied.'
    );
    $this->assertStringContainsString(
      'string: not a render array',
      $line,
      'It says what actually arrived.'
    );
  }

  /**
   * It describes the value that arrived in the same words neo_inspect uses.
   *
   * The module has already taught one vocabulary for "what is this value" —
   * `neo_inspect`'s one-phrase description, which reads "render array (#type:
   * link)", "Url", "string: …". A notice reuses it exactly as it stands rather
   * than inventing a second way to say the same thing, so an author who has
   * read one has read both.
   *
   * That is asserted as a **comparison against `neo_inspect` itself**, not as
   * a second copy of the wording. Each value is put into a Twig context, run
   * through `neo_inspect`, and the phrase it prints for that value is the
   * phrase the notice has to end with. A notice that drifted from
   * `neo_inspect` fails here even if both still read plausibly — and the
   * characterisation suite's assertions about that vocabulary stay the only
   * place it is pinned, because this plan consumes it rather than editing it.
   *
   * Every shape a helper actually receives is driven: the render array a
   * filter was piped, the `Url` and `Markup` objects a preprocess routinely
   * puts into scope, the plain array that is not a render array at all, and
   * the scalars an unfilled field arrives as.
   */
  public function testDescribesTheValueThatArrivedInTheSameWordsNeoInspectUses(): void {
    $this->installLogger();
    $probe = new NoticeProbe(['debug' => TRUE]);

    $seen = [];
    foreach (self::arrivingValues() as $label => $value) {
      $before = count($this->entries());
      $probe->emit('neo_class', 'a render array or a Link', $value);
      $written = array_slice($this->lines(), $before);

      $this->assertCount(1, $written, $label . ' produced its own notice.');

      $description = $this->inspectDescriptionOf($value);

      $this->assertNotSame(
        '',
        $description,
        'neo_inspect has a phrase for ' . $label . ', so the comparison is real.'
      );
      $this->assertStringEndsWith(
        $description,
        $written[0],
        'The notice describes ' . $label . " in neo_inspect's own words."
      );
      $seen[$label] = $description;
    }

    $this->assertSame(
      $seen,
      array_unique($seen),
      'Every shape driven describes differently, so no assertion above passed by collision.'
    );
  }

  /**
   * It logs one entry per distinct message however many times it recurs.
   *
   * The guard that fires most is the benign empty-value one, and a page that
   * pipes fifty empty values through a helper would otherwise leave fifty
   * identical lines — which is the same as leaving none, because nobody reads
   * a log like that. The same message is written once, no matter how many call
   * sites produce it.
   *
   * Two ways a duplicate arrives are both covered: the identical call repeated,
   * and two different values that describe identically — two separate empty
   * arrays are not the same value, but "array (0 items)" is the same notice and
   * the author learns nothing from the second one.
   *
   * Deduplication is **per request**, not forever: the extension is one service
   * per request, so a second instance starts with a clean slate and says its
   * piece. A static cache would silence the notice for the rest of the process,
   * which on a long-running worker means the author never sees it at all.
   */
  public function testLogsOneEntryPerDistinctMessageHoweverManyTimesItRecurs(): void {
    $this->installLogger();
    $probe = new NoticeProbe(['debug' => TRUE]);

    for ($call = 0; $call < 50; $call++) {
      $probe->emit('neo_class', 'a render array or a Link', '');
    }

    $this->assertCount(
      1,
      $this->entries(),
      'Fifty identical guards on one page leave one line, not fifty.'
    );

    $probe->emit('neo_property_class', 'a render array', []);
    $probe->emit('neo_property_class', 'a render array', []);

    $this->assertCount(
      2,
      $this->entries(),
      'Two distinct values that describe identically are one notice, not two.'
    );

    $this->assertSame(
      ['neo_twig'],
      $this->channels(),
      'A suppressed duplicate does not even resolve the channel again.'
    );

    (new NoticeProbe(['debug' => TRUE]))->emit('neo_class', 'a render array or a Link', '');

    $this->assertCount(
      3,
      $this->entries(),
      'Deduplication is per request: the next request says its piece again.'
    );
  }

  /**
   * It logs a second entry when a different message is produced.
   *
   * Deduplication keys on the whole message, not on the helper, because the
   * three things a notice carries are the three things an author is trying to
   * tell apart. A different helper, a different expectation from the same
   * helper, and a different value arriving at the same guard are three
   * different answers, and collapsing any of them would hide the one the
   * author needed.
   *
   * Driven as one sequence with a duplicate in the middle, so that suppression
   * and emission are asserted against each other rather than in separate runs.
   */
  public function testLogsAnotherEntryWhenAnotherHelperOrExpectationProducesAnotherMessage(): void {
    $this->installLogger();
    $probe = new NoticeProbe(['debug' => TRUE]);

    $probe->emit('neo_class', 'a render array or a Link', '');
    $probe->emit('neo_class', 'a render array or a Link', '');
    $probe->emit('neo_attribute', 'a render array or a Link', '');
    $probe->emit('neo_class', 'a key that resolves to a render array', '');
    $probe->emit('neo_class', 'a render array or a Link', 42);

    $lines = $this->lines();

    $this->assertCount(
      4,
      $lines,
      'Four distinct messages, one duplicate: a different answer is never suppressed.'
    );
    $this->assertSame(
      $lines,
      array_values(array_unique($lines)),
      'No line repeats, and the four kept their order.'
    );
  }

  /**
   * It returns the value the helper handed it, unchanged, in both gate states.
   *
   * The seam sits directly in front of an early return, so whatever it answers
   * is what the template gets. That makes "changes nothing" the property the
   * whole plan rests on: a template that worked keeps working, on every
   * environment, in both gate states.
   *
   * Unchanged means identical **and** untouched. Scalars come back with their
   * type intact — `'0'` is not `0`, `FALSE` is not `NULL` — arrays come back
   * with the same keys in the same order, and an object comes back as the same
   * instance still carrying the same state, because describing it must be a
   * read and nothing more.
   *
   * Both gate states are driven from one list, because the gate-on half is the
   * one that makes this plan safe to ship and the gate-off half is what thirty
   * deployed sites actually run.
   */
  public function testReturnsTheValueTheHelperHandedItUnchangedInBothGateStates(): void {
    $this->installLogger();

    foreach (['on' => TRUE, 'off' => FALSE] as $state => $gate) {
      $probe = new NoticeProbe(['debug' => $gate]);

      foreach (self::arrivingValues() + self::emptyValues() as $label => $value) {
        $this->assertSame(
          $value,
          $probe->emit('neo_class', 'a render array or a Link', $value),
          'With the gate ' . $state . ', ' . $label . ' comes straight back, type intact.'
        );
      }

      $attribute = new Attribute(['class' => ['kept']]);
      $url = Url::fromRoute('<front>');

      $this->assertSame($attribute, $probe->emit('neo_attributes', 'an Attribute or an array', $attribute));
      $this->assertSame($url, $probe->emit('neo_uri', 'a resolvable uri', $url));
      $this->assertSame(
        ['kept'],
        $attribute['class']->value(),
        'With the gate ' . $state . ', describing an Attribute is a read and nothing more.'
      );
      $this->assertSame(
        [],
        $url->getOptions(),
        'With the gate ' . $state . ', describing a Url is a read and nothing more.'
      );
    }
  }

  /**
   * It raises nothing and throws nothing when the logger is unavailable.
   *
   * The logger is resolved lazily from the container, the way `neo_oembed`
   * already resolves its services, which is what keeps the service definition
   * — and therefore a container rebuild across every site — out of this
   * plan. The cost of that choice is that resolution can fail, and a
   * diagnostic that takes a template down is worse than the silence it
   * replaces.
   *
   * All three ways it can fail are driven: no container at all, a container
   * with no logger factory in it, and a factory that throws when asked. In
   * every one the value still comes back and nothing is raised — no PHP error,
   * warning, notice or deprecation, which is asserted with a handler in force
   * so that a suppressed diagnostic still counts as raised.
   */
  public function testRaisesNothingAndThrowsNothingWhenTheLoggerIsUnavailable(): void {
    $probe = new NoticeProbe(['debug' => TRUE]);

    $throwing = new class() implements LoggerChannelFactoryInterface {

      /**
       * {@inheritdoc}
       */
      public function get($channel) {
        throw new \RuntimeException('The logger is not available.');
      }

      /**
       * {@inheritdoc}
       */
      public function addLogger(LoggerInterface $logger, $priority = 0) {}

    };

    $bare = new ContainerBuilder();
    $breaking = new ContainerBuilder();
    $breaking->set('logger.factory', $throwing);

    $raised = [];
    set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
      $raised[] = $severity . ': ' . $message;
      return TRUE;
    });

    // Collected rather than asserted inside the loop, so the handler above is
    // in force around the seam and nothing else.
    $returned = [];
    try {
      \Drupal::unsetContainer();
      $returned['no container at all'] = $probe->emit('neo_class', 'a render array or a Link', 'a');

      \Drupal::setContainer($bare);
      $returned['a container with no logger factory'] = $probe->emit('neo_class', 'a render array or a Link', 'b');

      \Drupal::setContainer($breaking);
      $returned['a logger factory that throws'] = $probe->emit('neo_class', 'a render array or a Link', 'c');
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame(
      [
        'no container at all' => 'a',
        'a container with no logger factory' => 'b',
        'a logger factory that throws' => 'c',
      ],
      $returned,
      'The value comes back from every way the logger can be unavailable.'
    );
    $this->assertSame(
      [],
      $raised,
      'No PHP error, warning, notice or deprecation was raised on the way.'
    );
  }

  /**
   * Installs a container carrying a logger factory test double.
   *
   * The seam resolves its logger lazily from the container, so this is the one
   * unit test in the module that needs one. Nothing but the factory is in it:
   * a seam reaching for any other service is caught rather than silently
   * served.
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
       * The channel names asked for, in order.
       *
       * @var string[]
       */
      public array $names = [];

      /**
       * Constructs the factory double.
       */
      public function __construct(protected object $target) {}

      /**
       * {@inheritdoc}
       */
      public function get($channel) {
        $this->names[] = $channel;
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
   * The channel names the logger factory was asked for, in order.
   *
   * @return string[]
   *   Channel names, in the order asked for.
   */
  private function channels(): array {
    return $this->factory?->names ?? [];
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
   * The phrase neo_inspect prints for a value listed in a template's scope.
   *
   * Read out of `neo_inspect`'s own output rather than reimplemented, so the
   * comparison is against the running helper and not against a copy of it.
   *
   * @param mixed $value
   *   The value to describe.
   *
   * @return string
   *   The description cell's text, entities decoded.
   */
  private function inspectDescriptionOf($value): string {
    $listing = (new TwigExtension(['debug' => TRUE]))->inspect(['arrived' => $value]);
    $found = preg_match(
      '#<code>\{\{ arrived \}\}</code></td>\s*<td[^>]*>(.*?)</td>#s',
      $listing,
      $matches
    );
    return $found ? trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES)) : '';
  }

  /**
   * Every shape a helper's guard can be handed, keyed by how to name it.
   *
   * @return array<string, mixed>
   *   Values, keyed by how a failure message should name them.
   */
  private static function arrivingValues(): array {
    return [
      'a render array' => ['#type' => 'link', '#title' => 'Read on'],
      'a plain array' => ['one', 'two', 'three'],
      'a Url object' => Url::fromRoute('<front>'),
      'a Markup object' => Markup::create('<em>rendered</em>'),
      'a string' => 'not a render array',
      'an integer' => 42,
      'a boolean' => TRUE,
      'NULL' => NULL,
    ];
  }

  /**
   * Every shape `empty()` answers TRUE for that a template can hand a helper.
   *
   * The empty guard is the one that fires most, so the seam's pass-through is
   * driven over every value that trips it rather than over a representative
   * one.
   *
   * @return array<string, mixed>
   *   Values, keyed by how a failure message should name them.
   */
  private static function emptyValues(): array {
    return [
      'an empty array' => [],
      'an empty string' => '',
      'the string zero' => '0',
      'integer zero' => 0,
      'float zero' => 0.0,
      'FALSE' => FALSE,
    ];
  }

}

/**
 * Exposes the protected notice seam the way a helper reaches it.
 */
final class NoticeProbe extends TwigExtension {

  /**
   * Calls the seam.
   *
   * @param string $name
   *   The helper's registered name.
   * @param string $expected
   *   One short phrase saying what the helper expected.
   * @param mixed $received
   *   The value that actually arrived.
   *
   * @return mixed
   *   Whatever the seam answers.
   */
  public function emit(string $name, string $expected, $received) {
    return $this->notice($name, $expected, $received);
  }

}

/**
 * A value that counts how many times it was asked to describe itself.
 *
 * `neo_inspect`'s description stringifies a `MarkupInterface`, so the count is
 * zero exactly when the value was never described.
 */
final class DescribeSpy implements MarkupInterface {

  /**
   * How many times the value was stringified.
   */
  public int $described = 0;

  /**
   * Constructs the spy.
   *
   * @param string $text
   *   The text to answer with.
   */
  public function __construct(protected string $text) {}

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    $this->described++;
    return $this->text;
  }

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): mixed {
    return $this->text;
  }

}
