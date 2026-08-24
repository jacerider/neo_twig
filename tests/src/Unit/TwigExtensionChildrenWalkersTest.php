<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the four children walkers.
 *
 * `neo_child_class` and `neo_child_attribute` iterate a render array's children
 * and hand each one to an **attribute writer**; `neo_property_class` walks the
 * items under a named property instead of the children; and `neo_children` is
 * the read-only member, returning the children rather than writing to them.
 *
 * None of them carries writing logic of its own, so what is asserted here is
 * **what each one walks** — which members it reaches, which it leaves alone,
 * and how the arguments it is handed reach the writer behind it. The writes
 * themselves are pinned by the `neo_class`, `neo_attributes` and
 * `neo_attribute` classes.
 *
 * No container: `Element` and everything the writers touch construct without
 * one, so the extension is built directly from a twig-config array — except
 * for the notice criteria below, which install one carrying a logger factory
 * double and nothing else.
 *
 * **The three writing walkers now say what they did not walk**, and the six
 * criteria after the five above are that. They live here, beside what each
 * walker walks, precisely so that the two fail together: a notice that changed
 * which members a walker reaches, or what it hands back, breaks a
 * characterisation criterion in the same run. None of those five is edited.
 *
 * What a walker notices is the walk it never made — an empty value, a render
 * array with no children, a named property that is absent or is not an array.
 * What it never notices is a walk it *did* make: `neo_children` says nothing
 * because finding no children is its answer rather than a job left undone, and
 * a walker whose delegated writes all trip a writer's own guard says nothing
 * either, because those notices are the writer's and carry the writer's name.
 */
#[Group('neo_twig')]
final class TwigExtensionChildrenWalkersTest extends UnitTestCase {

  /**
   * The three walkers that write, keyed by their Twig name.
   *
   * `neo_children` is not among them: it is the read-only member and the one
   * walker this ticket deliberately leaves silent.
   */
  private const WRITING_WALKERS = [
    'neo_child_class' => 'addChildClass',
    'neo_property_class' => 'addPropertyClass',
    'neo_child_attribute' => 'setChildAttribute',
  ];

  /**
   * The two walkers that iterate a render array's children.
   */
  private const CHILD_WALKERS = [
    'neo_child_class' => 'addChildClass',
    'neo_child_attribute' => 'setChildAttribute',
  ];

  /**
   * The extension under test.
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
    $this->extension = new TwigExtension();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * It adds classes to every child of a render array.
   *
   * The walk is over `Element::children()`, so it reaches every non-property
   * member and nothing else: the array's own `#attributes` are not a child and
   * are left exactly as they were. Each child is then handed to `neo_class`,
   * which merges rather than assigns, and the key the walker was given travels
   * through to it unchanged.
   */
  public function testAddsClassesToEveryChildOfRenderArray(): void {
    $build = [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['parent']],
      'first' => ['#markup' => 'one'],
      'second' => ['#markup' => 'two', '#attributes' => ['class' => ['kept']]],
    ];

    $result = $this->extension->addChildClass($build, 'child');

    $this->assertSame(
      ['class' => ['child']],
      $result['first']['#attributes'],
      'A child carrying no attributes gains them.'
    );
    $this->assertSame(
      ['class' => ['kept', 'child']],
      $result['second']['#attributes'],
      'A child that already had classes keeps them, with the new one appended.'
    );
    $this->assertSame(
      ['class' => ['parent']],
      $result['#attributes'],
      'The array handed to the walker is not itself a child and is untouched.'
    );

    $keyed = $this->extension->addChildClass($build, 'child', 'wrapper_attributes');

    $this->assertSame(
      ['class' => ['child']],
      $keyed['first']['#wrapper_attributes'],
      'The key the walker was given reaches the writer unchanged.'
    );
  }

  /**
   * It adds classes only to children carrying a named property with a value.
   *
   * The filter is a conjunction of both arguments, and it compares with `===`.
   * A child whose named property holds the value asked for is written to; a
   * child holding a different value, and a child holding no such property at
   * all, are passed over untouched.
   *
   * The conjunction is pinned in its own right: a property name given with no
   * value does not narrow the walk to the children carrying that property — it
   * disables the filter entirely and every child is written to, because
   * `$propValue` being falsy sends the walk down the unfiltered branch.
   */
  public function testAddsClassesOnlyToChildrenWithMatchingProperty(): void {
    $build = [
      'match' => ['#markup' => 'a', '#field_name' => 'title'],
      'other' => ['#markup' => 'b', '#field_name' => 'body'],
      'none' => ['#markup' => 'c'],
    ];

    $result = $this->extension->addChildClass($build, 'picked', 'attributes', 'field_name', 'title');

    $this->assertSame(
      ['class' => ['picked']],
      $result['match']['#attributes'],
      'The child whose property holds the value asked for is written to.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $result['other'],
      'A child whose property holds a different value is passed over.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $result['none'],
      'A child carrying no such property at all is passed over.'
    );

    $unfiltered = $this->extension->addChildClass($build, 'picked', 'attributes', 'field_name');

    $this->assertSame(
      ['match', 'other', 'none'],
      array_keys(array_filter($unfiltered, static fn ($child) => isset($child['#attributes']))),
      'A property name with no value disables the filter rather than narrowing it.'
    );
  }

  /**
   * It hash-prefixes the property name it filters children on.
   *
   * A template names the property the way a template thinks of it —
   * `field_name` — and the walker turns that into the `#field_name`
   * render-array property before comparing. A child carrying the bare key is
   * therefore not a match, and a name that already arrives prefixed is not
   * prefixed a second time, so both spellings pick out the same children.
   */
  public function testHashPrefixesThePropertyNameItFiltersChildrenOn(): void {
    $build = [
      'hashed' => ['#markup' => 'a', '#field_name' => 'title'],
      'bare' => ['#markup' => 'b', 'field_name' => 'title'],
    ];

    $result = $this->extension->addChildClass($build, 'picked', 'attributes', 'field_name', 'title');

    $this->assertArrayHasKey(
      '#attributes',
      $result['hashed'],
      'The bare name given is prefixed, so it matches the render-array property.'
    );
    $this->assertSame(
      ['class' => ['picked']],
      $result['hashed']['#attributes'],
      'The matched child is written to exactly as an unfiltered walk writes.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $result['bare'],
      'A child carrying the unprefixed key is not a match.'
    );

    $prefixed = $this->extension->addChildClass($build, 'picked', 'attributes', '#field_name', 'title');

    $this->assertSame(
      $result,
      $prefixed,
      'A name that already starts with a hash is not prefixed a second time.'
    );
  }

  /**
   * It sets an attribute on every child of a render array.
   *
   * The same walk as `neo_child_class`, delegating to `neo_attribute` instead:
   * every child is reached, the array handed to the walker is not, and an
   * attribute a child already carried survives beside the new one. This walker
   * takes no property filter — it writes to all the children or to none.
   */
  public function testSetsAnAttributeOnEveryChildOfRenderArray(): void {
    $build = [
      '#theme' => 'item_list',
      '#cache' => ['contexts' => ['url']],
      'first' => ['#markup' => 'one'],
      'second' => ['#markup' => 'two', '#attributes' => ['data-existing' => 'kept']],
    ];

    $result = $this->extension->setChildAttribute($build, 'data-neo', 'on');

    $this->assertSame(
      ['data-neo' => 'on'],
      $result['first']['#attributes'],
      'A child carrying no attributes gains the one that was set.'
    );
    $this->assertSame(
      ['data-existing' => 'kept', 'data-neo' => 'on'],
      $result['second']['#attributes'],
      'An attribute the child already carried survives beside the new one.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $result,
      'The array handed to the walker is not itself a child and is untouched.'
    );
    $this->assertSame(
      ['contexts' => ['url']],
      $result['#cache'],
      'A property holding an array is not a child either, and is walked past.'
    );

    $keyed = $this->extension->setChildAttribute($build, 'data-neo', 'on', 'wrapper_attributes');

    $this->assertSame(
      ['data-neo' => 'on'],
      $keyed['first']['#wrapper_attributes'],
      'The key the walker was given reaches the writer unchanged.'
    );
  }

  /**
   * It adds classes to each item under a named property, hash-prefixing it.
   *
   * `neo_property_class` walks a property, not the children — the members under
   * `#items` are reached and the array's actual children are not — and unlike
   * the key the writers resolve, the property name is **always** hash-prefixed.
   * There is no bare-key fallback here: a value carrying a bare `items` key is
   * returned exactly as it arrived, and a name that already starts with a hash
   * is not prefixed twice. The property defaults to `items`.
   */
  public function testAddsClassesToEachItemUnderNamedPropertyHashPrefixingIt(): void {
    $build = [
      '#items' => [
        ['#markup' => 'one'],
        ['#markup' => 'two', '#attributes' => ['class' => ['kept']]],
      ],
      'child' => ['#markup' => 'a child, not an item'],
    ];

    $result = $this->extension->addPropertyClass($build, 'item', 'items');

    $this->assertArrayHasKey(
      '#attributes',
      $result['#items'][0],
      'The name given is hash-prefixed, so it resolves the #items property.'
    );
    $this->assertSame(
      ['class' => ['item']],
      $result['#items'][0]['#attributes'],
      'The first item under the property is written to.'
    );
    $this->assertSame(
      ['class' => ['kept', 'item']],
      $result['#items'][1]['#attributes'],
      'So is the second, merging with what it already carried.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $result['child'],
      'The array\'s actual children are not what this walker walks.'
    );

    $this->assertSame(
      $result,
      $this->extension->addPropertyClass($build, 'item'),
      'The property defaults to items.'
    );
    $this->assertSame(
      $result,
      $this->extension->addPropertyClass($build, 'item', '#items'),
      'A name that already starts with a hash is not prefixed a second time.'
    );

    $bare = ['items' => [['#markup' => 'one']]];

    $this->assertSame(
      $bare,
      $this->extension->addPropertyClass($bare, 'item', 'items'),
      'There is no bare-key fallback: a bare items key is left exactly as it was.'
    );
  }

  /**
   * It returns a render array's children, weight-sorted when asked.
   *
   * The read-only member. `neo_children` returns the children themselves —
   * whole, keyed as they were — and drops every property, including the
   * `#sorted` marker the sort leaves behind. Unsorted it answers in declaration
   * order; asked to sort, it answers in weight order, a child carrying no
   * `#weight` counting as zero.
   *
   * It writes nothing. The sort happens on the copy the filter was handed, so
   * the array the template is still holding keeps its own order and gains no
   * `#sorted` marker of its own.
   */
  public function testReturnsChildrenAndWeightSortsThemWhenAsked(): void {
    $build = [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['parent']],
      'heavy' => ['#markup' => 'b', '#weight' => 10],
      'light' => ['#markup' => 'a', '#weight' => -10],
      'unweighted' => ['#markup' => 'c'],
    ];
    $original = $build;

    $children = TwigExtension::childrenFilter($build);

    $this->assertSame(
      ['heavy', 'light', 'unweighted'],
      array_keys($children),
      'Unsorted, the children come back in declaration order.'
    );
    $this->assertSame(
      ['#markup' => 'b', '#weight' => 10],
      $children['heavy'],
      'Each child comes back whole, not just its key.'
    );
    $this->assertArrayNotHasKey(
      '#theme',
      $children,
      'Nothing but children is returned — every property is dropped.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $children,
      'Including the properties that hold arrays of their own.'
    );

    $sorted = TwigExtension::childrenFilter($build, TRUE);

    $this->assertSame(
      ['light', 'unweighted', 'heavy'],
      array_keys($sorted),
      'Asked to sort, the children come back by weight, an absent weight as zero.'
    );
    $this->assertArrayNotHasKey(
      '#sorted',
      $sorted,
      'The marker the sort leaves behind is a property, so it is dropped too.'
    );

    $this->assertSame(
      $original,
      $build,
      'The filter writes nothing: the array it was handed is unchanged.'
    );
  }

  /**
   * It returns every value unchanged from all four walkers in both states.
   *
   * The five criteria above pin what each walker walks, against an extension
   * built with no twig configuration at all — the state roughly thirty
   * deployed sites run in. This one repeats every path on which a walker walks
   * **nothing** with the **debug gate** on as well, because that is the state a
   * notice exists in and the state in which a diagnostic could accidentally
   * become a behaviour change.
   *
   * Unchanged means the walk still did not happen: no child was reached, no
   * attribute set was seeded anywhere, no property was created, and a scalar
   * comes back with its type intact. With the gate off the value is
   * byte-identical; with it on the **inline notice** the seam attaches is the
   * only difference, and stripping that one property gives back the array that
   * went in, key for key and in the same order.
   *
   * `neo_children` is driven here too, and it is the strictest case of all: it
   * is a static callback that cannot read the gate, so its answer is the same
   * object graph in both states with nothing attached anywhere.
   *
   * That is the constraint the whole plan rests on, so it is asserted before
   * anything is asserted about a notice.
   */
  public function testReturnsEveryValueUnchangedFromAllFourWalkersInBothGateStates(): void {
    $this->installNoticeLogger();

    foreach (['off' => FALSE, 'on' => TRUE] as $state => $gate) {
      $extension = new TwigExtension(['debug' => $gate]);

      foreach (self::WRITING_WALKERS as $name => $method) {
        foreach (self::emptyValues() as $label => $value) {
          $this->assertSame(
            $value,
            $this->callWalkerOn($extension, $method, $value),
            'With the gate ' . $state . ', ' . $name . ' hands ' . $label . ' back, type intact.'
          );
        }
      }

      foreach (self::CHILD_WALKERS as $name => $method) {
        foreach (self::childlessArrays() as $label => $build) {
          $result = $this->callWalkerOn($extension, $method, $build);

          $this->assertIsArray($result);
          $written = $result;
          unset($written['#suffix']);

          $this->assertSame(
            $build,
            $written,
            'With the gate ' . $state . ', ' . $name . ' walked nothing in ' . $label . '.'
          );
          if ($gate === FALSE) {
            $this->assertSame(
              $build,
              $result,
              'With the gate off, ' . $name . ' returns ' . $label . ' byte-identically.'
            );
          }
        }
      }

      foreach (self::unwalkableProperties() as $label => [$build, $property]) {
        $result = $extension->addPropertyClass($build, 'added', $property);

        $this->assertIsArray($result);
        $written = $result;
        unset($written['#suffix']);

        $this->assertSame(
          $build,
          $written,
          'With the gate ' . $state . ', neo_property_class walked nothing under ' . $label . '.'
        );
        if ($gate === FALSE) {
          $this->assertSame(
            $build,
            $result,
            'With the gate off, neo_property_class returns ' . $label . ' byte-identically.'
          );
        }
      }

      foreach (self::childlessArrays() + self::walkableBuilds() as $label => $build) {
        $this->assertSame(
          TwigExtension::childrenFilter($build),
          TwigExtension::childrenFilter($build),
          'With the gate ' . $state . ', neo_children answers ' . $label . ' the same way twice.'
        );
        $this->assertSame(
          '',
          self::attachedTextOn(TwigExtension::childrenFilter($build)),
          'With the gate ' . $state . ', neo_children attaches nothing to ' . $label . '.'
        );
      }
    }
  }

  /**
   * It notices an empty value from each writing walker, silently inline.
   *
   * The empty guard is the one that fires most and the one that looks most
   * obviously harmless, and it is still the answer a stuck author cannot get
   * today: "your value was empty" is exactly what they went to the class to
   * find out. Every shape `empty()` answers TRUE for is driven through all
   * three writing walkers, because a template produces all of them out of a
   * field that is simply not filled in.
   *
   * The notice is **log-only**, and that rule does not bend. An empty value
   * cannot carry an **inline notice**, because attaching one would make the
   * value truthy and a dev template reading `{% if thing %}` would render a
   * branch production does not — which is the one thing this plan promises
   * never to do. So the value comes back exactly as it went in and the log
   * carries the line.
   *
   * The name in that line is the **registered name** a template author types
   * — `neo_child_class`, never `addChildClass`, the PHP method behind it,
   * which is not something a template author has ever seen. That is asserted
   * both ways round, because a message built from `__FUNCTION__` would read
   * perfectly well and still name the wrong thing.
   */
  public function testNoticesAnEmptyValueFromEachWritingWalkerAndAttachesNothingInline(): void {
    foreach (self::WRITING_WALKERS as $name => $method) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => TRUE]);

      foreach (self::emptyValues() as $label => $value) {
        $before = count($this->logged);
        $result = $this->callWalkerOn($extension, $method, $value);

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
        $this->assertStringNotContainsString(
          $method,
          $this->logged[$before],
          'The line for ' . $label . ' never names the PHP method behind ' . $name . '.'
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
   * It notices a childless render array and attaches that to what it returns.
   *
   * This is the mysterious one. A real render array went in, a real render
   * array came out, and nothing about it changed — no exception, no empty
   * value, nothing a template author can see from inside a template. It is
   * also the only guard on these two walkers with somewhere to put a notice,
   * so it is the only one that gets both surfaces: the line goes to the
   * **notice log** and the same line is attached to the array the walker hands
   * back, where it renders inside core's own Twig-debug output markers.
   *
   * Every shape a childless array arrives in is driven, because they are
   * different mistakes wearing the same face: an array holding only `#markup`,
   * an empty container, a themed array whose every member is a property, and —
   * the one an author is least likely to suspect — an array whose content is
   * under `#items`, where `neo_property_class` would have walked and a child
   * walker finds nothing at all.
   *
   * The two surfaces are asserted against each other rather than separately.
   * The log deduplicates per request, so a message that recurs leaves one line;
   * the inline notice never deduplicates, because it belongs to the element
   * that failed and suppressing the second copy would put it beside the wrong
   * element. Every array gets its own attachment, whatever the log did.
   *
   * `neo_property_class` is asserted alongside as the negative half: it does
   * not walk children, so a childless array is not its failure and it says
   * nothing about one.
   */
  public function testNoticesRenderArrayWithNoChildrenFromBothChildWalkersAndAttachesItInline(): void {
    foreach (self::CHILD_WALKERS as $name => $method) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => TRUE]);

      foreach (self::childlessArrays() as $label => $build) {
        $before = count($this->logged);
        $result = $this->callWalkerOn($extension, $method, $build);

        $this->assertStringContainsString(
          $name,
          self::attachedTextOn($result),
          $name . ' attaches its notice to the array it hands back for ' . $label . '.'
        );
        $this->assertGreaterThan(
          $before - 1,
          count($this->logged),
          $name . ' logged for ' . $label . ' unless the message had already been said.'
        );
      }

      $this->assertNotEmpty(
        $this->logged,
        $name . ' says something about a render array it found no children in.'
      );
      foreach ($this->logged as $line) {
        $this->assertStringContainsString(
          $name,
          $line,
          'Every line ' . $name . ' logged for a childless array names it.'
        );
        $this->assertStringNotContainsString(
          $method,
          $line,
          'And none of them names the PHP method behind ' . $name . '.'
        );
      }
    }

    $this->installNoticeLogger();
    $extension = new TwigExtension(['debug' => TRUE]);
    $walked = $extension->addPropertyClass(
      ['#items' => [['#markup' => 'one']], '#markup' => 'no children here'],
      'added'
    );

    $this->assertSame(
      [],
      $this->logged,
      'neo_property_class does not walk children, so a childless array is not its failure.'
    );
    $this->assertSame(
      '',
      self::attachedTextOn($walked),
      'And it attaches nothing to one either.'
    );
  }

  /**
   * It notices an absent or non-array property, naming the key looked for.
   *
   * `neo_property_class` walks the items under a named property rather than
   * the children, so its own way of walking nothing is a property that is not
   * there or that holds something other than an array. Like a childless array
   * it hands back a non-empty render array, so it gets both surfaces: the
   * **notice log** and an **inline notice** on the array it returns.
   *
   * The key the notice names is the **hash-prefixed** one the walker actually
   * looked for, not the bare name a template typed. That is the whole point of
   * the notice here, because the walker's hash-prefix rule has no bare-key
   * fallback: an author who wrote `items` and whose array holds `items` is
   * looking straight at the key they named while the walker looked for
   * `#items` and found nothing. So the bare-key case is driven and the notice
   * has to say `#items`, and a name that already arrived prefixed says the same
   * thing rather than `##items`.
   *
   * What arrived is described as **what was found at that key** — nothing, a
   * string, an object — because that is the difference between the mistakes an
   * author is trying to tell apart, and it is the same choice the writers'
   * resolver makes about an unreachable path.
   *
   * The two child walkers are asserted alongside as the negative half: neither
   * of them looks at a property, so neither says anything about one.
   */
  public function testNoticesPropertyThatIsAbsentOrNotAnArrayFromNeoPropertyClassNamingTheKey(): void {
    foreach (self::unwalkableProperties() as $label => [$build, $property]) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => TRUE]);
      $result = $extension->addPropertyClass($build, 'added', $property);

      $inline = self::attachedTextOn($result);

      $this->assertCount(
        1,
        $this->logged,
        'neo_property_class says something about ' . $label . '.'
      );

      foreach (['the log line' => $this->logged[0], 'the inline notice' => $inline] as $surface => $text) {
        $this->assertStringContainsString(
          'neo_property_class',
          $text,
          $surface . ' for ' . $label . ' names neo_property_class.'
        );
        $this->assertStringNotContainsString(
          'addPropertyClass',
          $text,
          $surface . ' for ' . $label . ' never names the PHP method behind it.'
        );
        $this->assertStringContainsString(
          '#' . ltrim($property, '#'),
          $text,
          $surface . ' for ' . $label . ' names the key the walker actually looked for.'
        );
        $this->assertStringNotContainsString(
          '##',
          $text,
          $surface . ' for ' . $label . ' does not prefix an already-prefixed name twice.'
        );
      }
    }

    $this->installNoticeLogger();
    $extension = new TwigExtension(['debug' => TRUE]);
    $bare = ['#type' => 'container', 'items' => [['#markup' => 'one']]];
    $extension->addPropertyClass($bare, 'added', 'items');

    $this->assertStringContainsString(
      'NULL',
      $this->logged[0] ?? '',
      'What arrived is what was found at the key — nothing, for a bare key nobody looked for.'
    );

    $this->installNoticeLogger();
    $scalar = ['#type' => 'container', '#items' => 'not an array'];
    $extension->addPropertyClass($scalar, 'added', 'items');

    $this->assertStringContainsString(
      'string: not an array',
      $this->logged[0] ?? '',
      'A property holding a scalar is described as that scalar, not as absent.'
    );

    foreach (self::CHILD_WALKERS as $name => $method) {
      $this->installNoticeLogger();
      $walked = $this->callWalkerOn($extension, $method, [
        '#type' => 'container',
        'child' => ['#markup' => 'a'],
      ]);

      $this->assertSame(
        [],
        $this->logged,
        $name . ' looks at no property, so an absent one is not its failure.'
      );
      $this->assertSame(
        '',
        self::attachedTextOn($walked),
        'And it attaches nothing about one either.'
      );
    }
  }

  /**
   * It notices nothing from neo_children, in either gate state.
   *
   * `neo_children` is the read-only member of the family and the one walker
   * this ticket deliberately leaves silent. Returning no children is its
   * **answer**, not a job it failed to do — an author who asks a childless
   * array for its children has been told the truth — so there is nothing for a
   * notice to add.
   *
   * Leaving it alone is also what keeps its callback static, which is the
   * mechanical half of the same decision: a static callback holds no
   * extension instance, so it could not read the **debug gate** even if it
   * wanted to. That is asserted directly rather than described, because it is
   * the reason the decision cannot quietly be reversed later without moving
   * the registration.
   *
   * Both gate states are driven and both ways of calling it — as the filter's
   * registered static callable, and off an extension built with the gate on —
   * because neither has anywhere to put a notice and neither may acquire one.
   */
  public function testNoticesNothingFromNeoChildren(): void {
    foreach (['off' => FALSE, 'on' => TRUE] as $state => $gate) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => $gate]);

      foreach (self::childlessArrays() + self::walkableBuilds() as $label => $build) {
        $children = TwigExtension::childrenFilter($build);
        $sorted = TwigExtension::childrenFilter($build, TRUE);

        $this->assertSame(
          [],
          $this->logged,
          'With the gate ' . $state . ', neo_children says nothing about ' . $label . '.'
        );
        $this->assertSame(
          [],
          $this->channels,
          'With the gate ' . $state . ', it does not even resolve a logger channel.'
        );
        $this->assertSame(
          '',
          self::attachedTextOn($children) . self::attachedTextOn($sorted),
          'With the gate ' . $state . ', it attaches nothing to what it hands back.'
        );
        $this->assertSame(
          '',
          self::attachedTextOn($build),
          'With the gate ' . $state . ', it attaches nothing to the array it was handed.'
        );
      }

      $registered = NULL;
      foreach ($extension->getFilters() as $filter) {
        if ($filter->getName() === 'neo_children') {
          $registered = $filter->getCallable();
        }
      }

      $this->assertSame(
        [TwigExtension::class, 'childrenFilter'],
        $registered,
        'neo_children stays a static callable, which could not read the gate anyway.'
      );
      $this->assertSame(
        [],
        $this->logged,
        'With the gate ' . $state . ', nothing about neo_children reached the log.'
      );
    }
  }

  /**
   * It notices nothing when a walker's delegated writes trip a writer's guard.
   *
   * A walker that found its children and handed each one to a writer has done
   * its own job. What the writer then makes of a child is the writer's
   * business, and it already says so in its own words: those notices name
   * `neo_class`, `neo_attribute` or `neo_attributes`, and they were built by
   * the previous ticket. A second notice from the walker on top of them would
   * be two lines for one failure, which is worse than one — and it would name
   * the wrong helper, because the walker did exactly what it was asked.
   *
   * Both halves are asserted, because "notices nothing" alone would also pass
   * against a walk that never happened. The writers' notices have to be there,
   * naming the writer; and no notice anywhere — log line or inline — may name
   * the walker.
   *
   * Two ways every delegated write can fail are driven: a key whose parents
   * path resolves to nothing in every child, and children that are empty
   * arrays, which trip the writer's empty guard instead. In both the walk
   * itself succeeded, so the walker stays quiet.
   */
  public function testNoticesNothingFromWalkerWhoseDelegatedWritesTripTheWritersOwnGuard(): void {
    $failing = [
      'a path that resolves to nothing in every child' => [
        ['#type' => 'container', 'first' => ['#markup' => 'a'], 'second' => ['#markup' => 'b']],
        ['nowhere', 'attributes'],
      ],
      'children that are empty arrays' => [
        ['#type' => 'container', 'first' => [], 'second' => []],
        'attributes',
      ],
    ];

    foreach (self::CHILD_WALKERS as $name => $method) {
      foreach ($failing as $label => [$build, $key]) {
        $this->installNoticeLogger();
        $extension = new TwigExtension(['debug' => TRUE]);
        $result = $this->callWalkerOn($extension, $method, $build, $key);

        $this->assertNotEmpty(
          $this->logged,
          'The writer behind ' . $name . ' said something about ' . $label . '.'
        );

        $surfaces = $this->logged;
        $surfaces[] = self::attachedTextOn($result);
        foreach (array_keys($build) as $child) {
          $surfaces[] = self::attachedTextOn($result[$child] ?? NULL);
        }

        foreach ($surfaces as $text) {
          $this->assertStringNotContainsString(
            $name,
            $text,
            $name . ' adds no notice of its own for ' . $label . ': the walk was made.'
          );
        }

        $this->assertSame(
          [self::CHILD_WALKERS['neo_child_class'] === $method ? 'neo_class' : 'neo_attribute'],
          array_values(array_unique(array_map(
            static fn(string $line): string => explode(':', $line)[0],
            $this->logged
          ))),
          'Every line for ' . $label . ' names the writer, and only the writer.'
        );
      }
    }

    $this->installNoticeLogger();
    $extension = new TwigExtension(['debug' => TRUE]);
    $walked = $extension->addPropertyClass(
      ['#type' => 'container', '#items' => [[], []]],
      'added'
    );

    $this->assertNotEmpty(
      $this->logged,
      'The writer behind neo_property_class said something about its empty items.'
    );
    foreach ($this->logged as $line) {
      $this->assertStringNotContainsString(
        'neo_property_class',
        $line,
        'neo_property_class adds no notice of its own: the property was there and was walked.'
      );
    }
    $this->assertSame(
      '',
      self::attachedTextOn($walked),
      'And it attaches nothing to an array whose property it did walk.'
    );
  }

  /**
   * Installs a container carrying a recording logger factory double.
   *
   * The notice seam resolves its logger lazily from the container, the way
   * `neo_oembed` resolves its services, so a criterion about what reaches the
   * **notice log** needs one. Nothing but the factory is in it: a walker
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
   * Calls one of the three writing walkers on a given extension.
   *
   * Each walker takes its own middle arguments, so this is the single place
   * that knows them; every criterion drives the walkers through it, so what
   * varies between cases is only the value and the key.
   *
   * @param \Drupal\neo_twig\TwigExtension $extension
   *   The extension to call, in whichever gate state the criterion needs.
   * @param string $method
   *   The method to call, as named in self::WRITING_WALKERS.
   * @param mixed $build
   *   The value to hand it.
   * @param string|array $key
   *   The key argument the walker passes through to the writer behind it.
   *
   * @return mixed
   *   Whatever the walker returned.
   */
  private function callWalkerOn(TwigExtension $extension, string $method, mixed $build, string|array $key = 'attributes'): mixed {
    return match ($method) {
      'addChildClass' => $extension->addChildClass($build, 'added', $key),
      'addPropertyClass' => $extension->addPropertyClass($build, 'added', 'items', $key),
      'setChildAttribute' => $extension->setChildAttribute($build, 'data-neo', 'on', $key),
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
   *   Whatever a walker answered with.
   *
   * @return string
   *   The attachment's text, or the empty string when there is none.
   */
  private static function attachedTextOn($build): string {
    $suffix = is_array($build) ? (string) ($build['#suffix'] ?? '') : '';
    return trim(html_entity_decode(strip_tags($suffix), ENT_QUOTES));
  }

  /**
   * Every shape `empty()` answers TRUE for that a template can hand a walker.
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
   * Non-empty render arrays that `Element::children()` finds nothing in.
   *
   * Every one of them is a perfectly real render array for a template to be
   * holding, which is exactly why a walk over it looks like a bug rather than
   * like an empty value.
   *
   * @return array<string, array>
   *   Render arrays, keyed by how a failure message should name them.
   */
  private static function childlessArrays(): array {
    return [
      'a render array holding only markup' => ['#markup' => 'no children here'],
      'a container with nothing in it' => ['#type' => 'container'],
      'a themed array whose members are all properties' => [
        '#theme' => 'item_list',
        '#attributes' => ['class' => ['parent']],
        '#cache' => ['contexts' => ['url']],
      ],
      'an array holding items rather than children' => [
        '#items' => [['#markup' => 'one']],
      ],
    ];
  }

  /**
   * Property names that resolve to nothing in the array they are looked for in.
   *
   * @return array<string, array>
   *   A render array and the property name to walk, keyed by how a failure
   *   message should name the case.
   */
  private static function unwalkableProperties(): array {
    return [
      'a property that is not there' => [
        ['#type' => 'container', 'child' => ['#markup' => 'a']],
        'items',
      ],
      'a property holding a string' => [
        ['#type' => 'container', '#items' => 'not an array'],
        'items',
      ],
      'a property holding an object' => [
        ['#type' => 'container', '#rows' => new \stdClass()],
        'rows',
      ],
      'a bare key that is never looked for' => [
        ['#type' => 'container', 'items' => [['#markup' => 'one']]],
        'items',
      ],
      'a hash-prefixed name that is not there' => [
        ['#type' => 'container', 'child' => ['#markup' => 'a']],
        '#items',
      ],
    ];
  }

  /**
   * Render arrays that a walk over does reach something in.
   *
   * @return array<string, array>
   *   Render arrays, keyed by how a failure message should name them.
   */
  private static function walkableBuilds(): array {
    return [
      'a render array with children' => [
        '#theme' => 'item_list',
        'first' => ['#markup' => 'one'],
        'second' => ['#markup' => 'two'],
      ],
    ];
  }

}
