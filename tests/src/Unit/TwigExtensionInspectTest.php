<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests neo_inspect, the helper that answers what a template can print.
 *
 * `neo_inspect` is the only helper in the module that answers a template
 * author's question from inside the template, and the only one gated on
 * `twig.config.debug` — the same switch that turns on core's FILE NAME
 * SUGGESTIONS comments. The **debug gate** is the load-bearing criterion here:
 * with debugging off the function returns an empty string and does nothing
 * else, which is what makes a call committed by accident inert on every
 * deployed environment across every installing site. A backlog candidate wants
 * to reuse that gate for every helper, so it is covered first and hardest —
 * every calling shape, and every way the gate can be off, including a
 * constructor given no configuration at all.
 *
 * **These assertions are about content, not markup.** The output is inline
 * debug styling a maintainer should stay free to restyle, so nothing here
 * pins a colour, a border, a `style` attribute or an element name. What is
 * pinned is which keys are listed, how each one is described, and how deep the
 * walk goes. Every assertion therefore runs through the small readers at the
 * bottom of this class, which reduce the output to row text: the tags and the
 * entities come off, and non-breaking spaces — which are how the walk draws
 * indentation — are treated as whitespace rather than as data. Depth is
 * asserted by *which keys appear at which depth argument*, never by counting
 * that indentation, precisely because indentation is the part a restyle would
 * move to CSS.
 *
 * The extension constructs with a twig-config array and nothing else, and
 * `Html`, `Unicode`, `Attribute`, `Markup` and `Url` all work without a
 * container, so no container is installed.
 *
 * **Two findings recorded, not fixed.** Both are pinned below as the current
 * behaviour, named at the assertion, so that the commit which repairs either
 * one shows up as a deliberate test diff:
 *
 * 1. A class in the **global namespace** loses its first character in the
 *    context listing — `stdClass` is described as `tdClass` — because
 *    `describe()` shortens the class with a `substr()` offset taken from
 *    `strrpos()`, and `strrpos()` answers FALSE, not -1, when there is no
 *    backslash to find. The non-array branch of `inspect()` prints the full
 *    class name and is unaffected.
 * 2. A render array whose children are keyed by **integer delta** — which is
 *    the shape of every multi-value field's render array — reports "no
 *    printable children", because `inspectRows()` skips any key that is not a
 *    string. So `{{ neo_inspect(content.field_tags) }}` says a field renders
 *    as a whole even when it has several items to walk.
 *
 * @see \Drupal\neo_twig\TwigExtension::inspect()
 */
#[Group('neo_twig')]
final class TwigExtensionInspectTest extends UnitTestCase {

  /**
   * The extension under test, with the debug gate on.
   *
   * @var \Drupal\neo_twig\TwigExtension
   */
  private TwigExtension $extension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Every criterion but the first is a claim about what the helper says once
    // a developer has deliberately opted into Twig debugging.
    $this->extension = new TwigExtension(['debug' => TRUE]);
  }

  /**
   * It returns an empty string when twig debugging is off.
   *
   * The **debug gate** is the whole reason a `neo_inspect()` left behind in a
   * committed template is harmless. It is checked before anything else the
   * function does, so it has to hold for every calling shape: no argument, a
   * render array, a value that is not an array at all, and any depth. The
   * answer is the empty string in each case — not NULL, not the input, not a
   * comment — because Twig prints whatever it is given and anything else would
   * be visible on a production page.
   *
   * Off means every way of being off. `twig.config.debug` is read with
   * `!empty()`, so FALSE, `0`, an empty array of configuration and a
   * constructor called with no arguments at all are the same answer; the last
   * matters because it is what a `new TwigExtension()` in someone else's test
   * or a mis-wired service definition would produce.
   *
   * The same calls are then made with the gate on and asserted to be
   * non-empty, so that a fixture which had merely stopped producing output
   * could not pass this test by accident.
   */
  public function testReturnsAnEmptyStringWhenTwigDebuggingIsOff(): void {
    $off = [
      'debug FALSE' => new TwigExtension(['debug' => FALSE]),
      'debug 0' => new TwigExtension(['debug' => 0]),
      'debug absent' => new TwigExtension(['auto_reload' => TRUE]),
      'empty config' => new TwigExtension([]),
      'no config at all' => new TwigExtension(),
    ];
    $context = ['content' => ['#markup' => 'body'], 'title' => 'Page'];
    $calls = [
      'no argument' => [$context],
      'a render array' => [$context, ['#type' => 'container', 'child' => []]],
      'an object' => [$context, new Attribute(['class' => ['a']])],
      'a scalar' => [$context, 'a string'],
      'a deep walk' => [$context, ['a' => ['b' => ['c' => []]]], 9],
    ];

    foreach ($off as $label => $extension) {
      foreach ($calls as $shape => $arguments) {
        $this->assertSame(
          '',
          $extension->inspect(...$arguments),
          'With ' . $label . ', neo_inspect(' . $shape . ') is the empty string.'
        );
      }
    }

    foreach ($calls as $shape => $arguments) {
      $this->assertNotSame(
        '',
        $this->extension->inspect(...$arguments),
        'With the gate on, neo_inspect(' . $shape . ') does answer something.'
      );
    }
  }

  /**
   * It lists every variable in scope when called with no argument.
   *
   * Called with no argument — which is `needs_context` doing its work — the
   * answer is the quickest one a template author wants: the names they can
   * type. Every public name in the context is listed, in the order the context
   * carries them, and nothing is filtered on its *value*: a variable holding
   * NULL, the empty string or FALSE is still a name that can be typed, and is
   * still listed.
   *
   * `neo_inspect(nope)` for a variable a template does not have arrives here
   * too, because Twig resolves the unknown name to NULL and NULL is the
   * "no argument" signal — so the answer to a typo is the list of what does
   * exist, which is what the author actually needed.
   *
   * The listing closes with a worked example naming the first variable it
   * listed, so the next thing to type is on screen rather than in a docblock.
   */
  public function testListsEveryVariableInScopeWhenCalledWithNoArgument(): void {
    $context = [
      'content' => ['#markup' => 'body'],
      'attributes' => new Attribute(['class' => ['node']]),
      'title' => 'A page title',
      'label' => NULL,
      'blank' => '',
      'off' => FALSE,
      'count' => 0,
      'items' => [1, 2, 3],
    ];

    $listed = self::scope($this->extension->inspect($context));

    $this->assertSame(
      ['content', 'attributes', 'title', 'label', 'blank', 'off', 'count', 'items'],
      array_keys($listed),
      'Every public name in the context is listed, in context order.'
    );
    $this->assertCount(
      count($context),
      $listed,
      'No variable is dropped for holding NULL, the empty string or FALSE.'
    );

    $this->assertContains(
      'Pass one in to walk its children, e.g. {{ neo_inspect(content) }}',
      self::notes($this->extension->inspect($context)),
      'The worked example names the first variable listed.'
    );
  }

  /**
   * It skips context variables whose name begins with an underscore.
   *
   * Twig puts its own plumbing in the context — `_self`, `_context`,
   * `_charset`, and whatever a template's `{% for %}` loop or an extension
   * leaves behind. None of it is a name a template author can usefully print,
   * so a name beginning with an underscore is passed over. The same guard
   * skips any key that is not a string at all, which is how an integer-keyed
   * entry stays out of the list.
   *
   * A context holding nothing else says so, rather than answering an empty
   * table, and its worked example falls back to `form` — the name the guard
   * suggests when there is no real one to suggest.
   */
  public function testSkipsContextVariablesWhoseNameBeginsWithAnUnderscore(): void {
    $context = [
      '_self' => 'template@node.html.twig',
      'content' => ['#markup' => 'body'],
      '_context' => ['recursive' => TRUE],
      '_charset' => 'UTF-8',
      '_loop_helper' => 'plumbing',
      7 => 'an integer key',
      'title' => 'A page title',
    ];
    $out = $this->extension->inspect($context);

    $this->assertSame(
      ['content', 'title'],
      array_keys(self::scope($out)),
      'Only the two names a template author can type are listed.'
    );
    foreach (['_self', '_context', '_charset', '_loop_helper', 'template@node.html.twig'] as $internal) {
      $this->assertStringNotContainsString(
        $internal,
        self::text($out),
        $internal . ' is Twig plumbing and is nowhere in the answer.'
      );
    }
    $this->assertStringNotContainsString(
      'an integer key',
      self::text($out),
      'A key that is not a string is skipped by the same guard.'
    );

    $internals = $this->extension->inspect(['_self' => 'x', '_charset' => 'UTF-8']);
    $this->assertSame(
      [
        'no variables in scope',
        'Pass one in to walk its children, e.g. {{ neo_inspect(form) }}',
      ],
      self::notes($internals),
      'A context of nothing but plumbing says so, and suggests form.'
    );
    $this->assertSame(
      [],
      self::scope($internals),
      'It lists no variables rather than listing the plumbing.'
    );
  }

  /**
   * It describes a render array by its type, theme, markup or plain-text.
   *
   * `#type`, `#theme`, `#markup`, `#plain_text` — in that order of precedence,
   * first one found wins — is how an array with any `#` property is described,
   * and it is the same four-property list on all three surfaces: the context
   * listing, the caption over a walked value, and each row of the walk.
   *
   * The three surfaces phrase it differently, and that is the behaviour:
   *
   * - The **context listing** names the property it used, as
   *   `render array (#theme: item_list)`. An array carrying `#` properties but
   *   none of those four is `render array` with nothing after it; an array
   *   carrying no `#` property at all is not a render array to this helper and
   *   is counted instead, as `array (3 items)`.
   * - The **caption** carries `#type`, `#theme` and `#theme_wrappers` — a
   *   different list, and the only place `#theme_wrappers` appears — and is
   *   the bare word `neo_inspect` when the value has none of them.
   * - A **walk row** prints the *value* for `#type` and `#theme`, but the bare
   *   property name for `#markup` and `#plain_text`, because the value there
   *   is the content itself rather than a name for it.
   *
   * A property holding an array is flattened to a comma-joined list, and one
   * holding anything else — an object, a closure — becomes an ellipsis rather
   * than a fatal string conversion.
   */
  public function testDescribesRenderArraysByTypeThemeMarkupOrPlainText(): void {
    $described = self::scope($this->extension->inspect([
      'typed' => ['#type' => 'form', '#theme' => 'ignored'],
      'themed' => ['#theme' => 'item_list', '#markup' => 'ignored'],
      'marked' => ['#markup' => 'hello', '#plain_text' => 'ignored'],
      'plain' => ['#plain_text' => 'yo'],
      'other' => ['#cache' => ['tags' => ['node:1']]],
      'listy' => [1, 2, 3],
      'multi' => ['#type' => ['first', 'second']],
      'objecty' => ['#type' => new Attribute()],
    ]));

    $this->assertSame(
      [
        'typed' => 'render array (#type: form)',
        'themed' => 'render array (#theme: item_list)',
        'marked' => 'render array (#markup: hello)',
        'plain' => 'render array (#plain_text: yo)',
        'other' => 'render array',
        'listy' => 'array (3 items)',
        'multi' => 'render array (#type: first, second)',
        'objecty' => 'render array (#type: …)',
      ],
      $described,
      'Each array is described by the first of the four properties it carries.'
    );

    $this->assertSame(
      'neo_inspect — #type: container #theme: wrapper #theme_wrappers: outer, inner',
      self::caption($this->extension->inspect([], [
        '#type' => 'container',
        '#theme' => 'wrapper',
        '#theme_wrappers' => ['outer', 'inner'],
        'child' => [],
      ])),
      'The caption carries #type, #theme and #theme_wrappers.'
    );
    $this->assertSame(
      'neo_inspect',
      self::caption($this->extension->inspect([], ['child' => []])),
      'With none of the three, the caption is the bare word.'
    );

    $this->assertSame(
      [
        'typed' => 'form',
        'themed' => 'item_list',
        'marked' => 'markup',
        'plain' => 'plain_text',
        'other' => '',
      ],
      self::walkTypes($this->extension->inspect([], [
        'typed' => ['#type' => 'form', '#theme' => 'ignored'],
        'themed' => ['#theme' => 'item_list', '#markup' => 'ignored'],
        'marked' => ['#markup' => 'hello', '#plain_text' => 'ignored'],
        'plain' => ['#plain_text' => 'yo'],
        'other' => ['#cache' => ['tags' => ['node:1']]],
      ], 1)),
      'A row prints the value for #type and #theme, the name for the others.'
    );
  }

  /**
   * It walks a render array's printable children to the requested depth.
   *
   * The walk answers the second half of "what can I print in here?": the keys
   * *inside* the value, in document order, depth-first, each with the property
   * it is described by and its `#title` if it has one. A child that is not an
   * array is listed too, described by its PHP type, because it is still
   * something the template can print.
   *
   * Printable means addressable by name. A `#`-prefixed key is a property
   * rather than a child, and a key that is not a string is skipped by the same
   * guard — see the second recorded finding in this class's docblock, because
   * integer keys are exactly how a multi-value field's items are keyed.
   *
   * Depth is what the argument says, and it is a count of *levels*, not of
   * recursion steps: 1 lists the immediate children only, 2 — the default —
   * reaches their children, 3 one further. It is floored at 1, so `0` and a
   * negative number both answer the immediate children rather than nothing.
   */
  public function testWalksPrintableChildrenToTheRequestedDepth(): void {
    $element = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wrapper']],
      'top' => [
        '#type' => 'fieldset',
        '#title' => 'Top level',
        'middle' => [
          '#theme' => 'item_list',
          'bottom' => ['#markup' => 'deepest'],
        ],
      ],
      'scalar' => 'a plain string child',
      4 => ['#markup' => 'a child keyed by delta'],
    ];

    $this->assertSame(
      ['top', 'middle', 'scalar'],
      self::walkKeys($this->extension->inspect([], $element)),
      'The default depth of 2 reaches the children of the children.'
    );
    $this->assertSame(
      ['top', 'scalar'],
      self::walkKeys($this->extension->inspect([], $element, 1)),
      'Depth 1 lists the immediate children only.'
    );
    $this->assertSame(
      ['top', 'middle', 'bottom', 'scalar'],
      self::walkKeys($this->extension->inspect([], $element, 3)),
      'Depth 3 reaches one level further, still in document order.'
    );
    foreach ([0, -1, -100] as $depth) {
      $this->assertSame(
        ['top', 'scalar'],
        self::walkKeys($this->extension->inspect([], $element, $depth)),
        'Depth ' . $depth . ' is floored at 1 rather than answering nothing.'
      );
    }

    $keys = self::walkKeys($this->extension->inspect([], $element, 3));
    $this->assertNotContains('#type', $keys, 'A # property is not a child.');
    $this->assertNotContains('#attributes', $keys, 'Nor is #attributes.');
    $this->assertNotContains('4', $keys, 'Nor is a key that is not a string.');

    $this->assertSame(
      [
        ['key' => 'top', 'type' => 'fieldset', 'title' => 'Top level'],
        ['key' => 'middle', 'type' => 'item_list', 'title' => ''],
        ['key' => 'scalar', 'type' => 'string', 'title' => ''],
      ],
      self::walkRows($this->extension->inspect([], $element)),
      'Each row carries the key, its description and its #title.'
    );
  }

  /**
   * It says a value has no printable children when it has none.
   *
   * The question a template author asked was "what can I print in here?", and
   * "nothing, print the whole thing" is a real answer to it — so the walk says
   * so in words instead of answering an empty table, which reads as the helper
   * having failed. The wording carries the advice as well as the finding:
   * *this value renders as a whole*.
   *
   * Three shapes reach it. A leaf render array, which is the honest case; an
   * empty array, which is a template author looking at a field that is not
   * filled in; and an array whose children are all keyed by integer delta,
   * which is **not** honest — see the second recorded finding in this class's
   * docblock. All three are pinned, the third one named as the defect it is.
   *
   * The line is absent whenever there is something to list, so it cannot be
   * mistaken for a header.
   */
  public function testSaysValueHasNoPrintableChildrenWhenItHasNone(): void {
    $message = 'no printable children — this value renders as a whole';

    $this->assertSame(
      [$message],
      self::notes($this->extension->inspect([], ['#markup' => 'the whole thing'])),
      'A leaf render array says so in words, not as an empty table.'
    );
    $this->assertSame(
      [$message],
      self::notes($this->extension->inspect([], [])),
      'So does an empty array.'
    );
    $this->assertSame(
      [$message],
      self::notes($this->extension->inspect([], [
        '#theme' => 'field',
        0 => ['#markup' => 'first item'],
        1 => ['#markup' => 'second item'],
      ])),
      'Pinned defect: children keyed by delta are reported as no children.'
    );

    $walked = $this->extension->inspect([], ['child' => ['#markup' => 'x']]);
    $this->assertSame([], self::notes($walked), 'The line is absent when there is something to list.');
    $this->assertSame(['child'], self::walkKeys($walked), 'And the child is listed instead.');
  }

  /**
   * It describes a non-array value by its class or its type.
   *
   * A value that is not an array has no children to walk, so the answer is one
   * line describing what it is. Objects are named by class and everything else
   * by `gettype()` — which is why the answer for a number is `integer` or
   * `double` rather than the number itself: the question was what the value
   * *is*, and a template already prints what it says.
   *
   * NULL never arrives here. It is the signal for "no argument", so it answers
   * the context listing instead, and that boundary is asserted rather than
   * assumed.
   *
   * The context listing describes the same values differently, and both
   * phrasings are the behaviour: it shortens the class to its last segment,
   * appends the rendered text of anything stringable, spells booleans and NULL
   * out, and truncates a long scalar to forty characters on a word boundary.
   *
   * **Pinned defect.** That shortening drops the first character of a class in
   * the global namespace — `stdClass` is described as `tdClass` — because
   * `strrpos()` answers FALSE rather than -1 when there is no backslash to
   * find. The non-array branch prints the full name and is unaffected, so the
   * two are asserted side by side.
   */
  public function testDescribesNonArrayValuesByClassOrType(): void {
    $this->assertSame(
      [
        'string' => 'string',
        'integer' => 'integer',
        'double' => 'double',
        'boolean' => 'boolean',
      ],
      [
        'string' => self::lead($this->extension->inspect([], 'a string')),
        'integer' => self::lead($this->extension->inspect([], 42)),
        'double' => self::lead($this->extension->inspect([], 1.5)),
        'boolean' => self::lead($this->extension->inspect([], TRUE)),
      ],
      'A value that is not an object is described by its PHP type.'
    );
    $this->assertSame(
      [
        'Drupal\Core\Template\Attribute',
        'Drupal\Core\Render\Markup',
        'Drupal\Core\Url',
        'stdClass',
      ],
      [
        self::lead($this->extension->inspect([], new Attribute(['class' => ['a']]))),
        self::lead($this->extension->inspect([], Markup::create('<b>hi</b>'))),
        self::lead($this->extension->inspect([], Url::fromRoute('<front>'))),
        self::lead($this->extension->inspect([], new \stdClass())),
      ],
      'An object is described by its class name, in full and unshortened.'
    );

    $this->assertSame(
      'neo_inspect — variables in scope',
      self::caption($this->extension->inspect(['title' => 'Page'], NULL)),
      'NULL is the no-argument signal and never reaches the non-array branch.'
    );

    $this->assertSame(
      [
        'url' => 'Url',
        'markup' => 'Markup: Hello there this is quite a long piece…',
        'yes' => 'bool: TRUE',
        'no' => 'bool: FALSE',
        'nothing' => 'NULL',
        'number' => 'integer: 42',
        'fraction' => 'double: 1.5',
        'sentence' => 'string: a fairly long string value that should…',
        'global' => 'tdClass',
      ],
      self::scope($this->extension->inspect([
        'url' => Url::fromRoute('<front>'),
        'markup' => Markup::create('<b>Hello there this is quite a long piece of markup indeed</b>'),
        'yes' => TRUE,
        'no' => FALSE,
        'nothing' => NULL,
        'number' => 42,
        'fraction' => 1.5,
        'sentence' => 'a fairly long string value that should be truncated here',
        // Pinned defect: a class with no namespace loses its first character,
        // because strrpos() answers FALSE rather than -1. Reported in full by
        // the non-array branch asserted above.
        'global' => new \stdClass(),
      ])),
      'The listing shortens the class, spells out bool and NULL, truncates.'
    );
  }

  /**
   * Reduces a fragment of the answer to its text.
   *
   * Tags and entities come off, and the non-breaking spaces the walk draws
   * indentation with are treated as whitespace, so that nothing an assertion
   * sees is a styling decision a maintainer should be free to change.
   *
   * @param string $html
   *   A fragment of neo_inspect's answer.
   *
   * @return string
   *   Its text, with runs of whitespace collapsed and the ends trimmed.
   */
  private static function text(string $html): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    $text = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $text);
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * The rows of the answer, as lists of cell text.
   *
   * @param string $html
   *   The answer neo_inspect gave.
   *
   * @return array
   *   One entry per row, each a list of that row's cell text.
   */
  private static function rows(string $html): array {
    $rows = [];
    preg_match_all('#<tr>(.*?)</tr>#s', $html, $matches);
    foreach ($matches[1] as $row) {
      preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $row, $cells);
      $rows[] = array_map([self::class, 'text'], $cells[1]);
    }
    return $rows;
  }

  /**
   * The variables a context listing names, keyed by name.
   *
   * @param string $html
   *   The answer neo_inspect gave to a call with no argument.
   *
   * @return array
   *   The description of each listed variable, keyed by variable name, in the
   *   order the answer lists them.
   */
  private static function scope(string $html): array {
    $listed = [];
    foreach (self::rows($html) as $row) {
      if (count($row) === 2 && preg_match('/^\{\{ (.+) \}\}$/', $row[0], $name)) {
        $listed[$name[1]] = $row[1];
      }
    }
    return $listed;
  }

  /**
   * The rows a walk lists, each with its key, description and title.
   *
   * @param string $html
   *   The answer neo_inspect gave to a call with a render array.
   *
   * @return array
   *   One entry per listed child, each with `key`, `type` and `title`.
   */
  private static function walkRows(string $html): array {
    $walked = [];
    foreach (self::rows($html) as $row) {
      if (count($row) === 3) {
        $walked[] = ['key' => $row[0], 'type' => $row[1], 'title' => $row[2]];
      }
    }
    return $walked;
  }

  /**
   * The keys a walk lists, in the order it lists them.
   *
   * @param string $html
   *   The answer neo_inspect gave to a call with a render array.
   *
   * @return array
   *   The listed keys.
   */
  private static function walkKeys(string $html): array {
    return array_column(self::walkRows($html), 'key');
  }

  /**
   * The description each walked key is given, keyed by key.
   *
   * @param string $html
   *   The answer neo_inspect gave to a call with a render array.
   *
   * @return array
   *   The description of each listed child, keyed by child key.
   */
  private static function walkTypes(string $html): array {
    return array_column(self::walkRows($html), 'type', 'key');
  }

  /**
   * The single-cell rows — the notes the answer adds in prose.
   *
   * @param string $html
   *   The answer neo_inspect gave.
   *
   * @return array
   *   The text of every row that spans the table rather than listing a value.
   */
  private static function notes(string $html): array {
    $notes = [];
    foreach (self::rows($html) as $row) {
      if (count($row) === 1) {
        $notes[] = $row[0];
      }
    }
    return $notes;
  }

  /**
   * The caption over the answer.
   *
   * @param string $html
   *   The answer neo_inspect gave.
   *
   * @return string
   *   The caption text, or the empty string when there is no caption.
   */
  private static function caption(string $html): string {
    return preg_match('#<caption\b[^>]*>(.*?)</caption>#s', $html, $match)
      ? self::text($match[1])
      : '';
  }

  /**
   * The one-line answer given for a value that is not an array.
   *
   * @param string $html
   *   The answer neo_inspect gave.
   *
   * @return string
   *   What the line says the value is, with the helper's own name stripped.
   */
  private static function lead(string $html): string {
    $text = preg_match('#<pre\b[^>]*>(.*?)</pre>#s', $html, $match)
      ? self::text($match[1])
      : '';
    return preg_replace('/^neo_inspect: /', '', $text) ?? '';
  }

}
