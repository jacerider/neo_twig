<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

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
 * one, so the extension is built directly from a twig-config array.
 */
#[Group('neo_twig')]
final class TwigExtensionChildrenWalkersTest extends UnitTestCase {

  /**
   * The extension under test.
   *
   * @var \Drupal\neo_twig\TwigExtension
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

}
