<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Core\Link;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `neo_class` filter — the widest of the three attribute writers.
 *
 * `addClass()` accepts a render array, a `Link`, or a value holding an
 * `Attribute` or a `Url` at the key it was pointed at, resolves that key —
 * optionally through a parents path — and merges classes into it. Seven
 * branches, no container: `Attribute`, `Url`, `Link` and `NestedArray` all
 * construct without one, so the extension is built directly from a twig-config
 * array and nothing else is needed.
 *
 * Two of the branches are pinned here as **current** behaviour rather than as
 * behaviour anybody defends:
 *
 * - The **hash-prefix rule**. `neo_class` prefixes the key with `#` only when
 *   the bare key is absent, so a value carrying a bare `attributes` key gets
 *   written there. Its two sibling writers always prefix. This class pins this
 *   half of that disagreement; the other half belongs to the `neo_attributes`
 *   and `neo_attribute` tests.
 * - The `#options` mirror. On a `#type: link` element the merged classes are
 *   copied into the link's `#options` as well. `neo_attribute` does the same;
 *   `neo_attributes` does not.
 *
 * A later candidate is going to unify both. The point of pinning them is that
 * when it does, the diff says so out loud.
 */
#[Group('neo_twig')]
final class TwigExtensionAddClassTest extends UnitTestCase {

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
   * It adds a single class, a list and a nested list to attributes.
   *
   * The three shapes a template can hand the filter. A bare string is wrapped;
   * a list passes through; a list holding a list has that member imploded on a
   * space, which is how a nested list still arrives as a flat class list.
   */
  public function testAddsSingleListAndNestedListToAttributes(): void {
    $single = $this->extension->addClass(['#markup' => 'x'], 'one');
    $this->assertSame(
      ['class' => ['one']],
      $single['#attributes'],
      'A bare string is wrapped into a one-entry class list.'
    );

    $list = $this->extension->addClass(['#markup' => 'x'], ['one', 'two']);
    $this->assertSame(
      ['class' => ['one', 'two']],
      $list['#attributes'],
      'A list of classes is written through in order.'
    );

    $nested = $this->extension->addClass(['#markup' => 'x'], ['one', ['two', 'three']]);
    $this->assertSame(
      ['class' => ['one', 'two three']],
      $nested['#attributes'],
      'A nested list is imploded on a space, leaving the class list flat.'
    );
  }

  /**
   * It merges with the classes the element already carries.
   *
   * The filter is a merge, not an assignment: whatever the element arrived
   * with survives, and the new classes are appended after it in order.
   */
  public function testMergesWithClassesTheElementAlreadyCarries(): void {
    $build = [
      '#markup' => 'x',
      '#attributes' => [
        'class' => ['first'],
        'id' => 'kept',
      ],
    ];

    $result = $this->extension->addClass($build, ['second', 'third']);

    $this->assertSame(
      ['first', 'second', 'third'],
      $result['#attributes']['class'],
      'The classes already on the element survive, with the new ones appended.'
    );
    $this->assertSame(
      'kept',
      $result['#attributes']['id'],
      'Sibling attributes on the same property are left alone.'
    );
  }

  /**
   * It writes into a Link's url options rather than into a render array.
   *
   * A `Link` is not a render array and has no `#attributes` to write to, so
   * the filter reaches through to the URL it wraps and merges the classes into
   * that URL's `attributes` option. The `Link` itself comes back, mutated in
   * place, so a template can keep piping it.
   */
  public function testWritesIntoLinkUrlOptionsRatherThanRenderArray(): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => 1], [
      'attributes' => ['class' => ['first']],
    ]);
    $link = Link::fromTextAndUrl('Read more', $url);

    $result = $this->extension->addClass($link, ['second', 'third']);

    $this->assertInstanceOf(Link::class, $result, 'A Link comes back as a Link, not as a render array.');
    $this->assertSame($link, $result, 'The same Link object is returned, mutated in place.');
    $this->assertSame(
      ['first', 'second', 'third'],
      $result->getUrl()->getOptions()['attributes']['class'],
      'The classes are merged into the wrapped URL\'s attributes option.'
    );
  }

  /**
   * It writes to a bare attributes key, or to the hash-prefixed one otherwise.
   *
   * The **hash-prefix rule**, pinned as current behaviour. `neo_class` prefixes
   * the key with `#` only when the bare key is absent, so a value carrying a
   * bare `attributes` key — a preprocessed template variable, say — is written
   * there rather than gaining a second, hash-prefixed one nothing reads. Its
   * two sibling writers always prefix; a later candidate unifies the two, and
   * this assertion is the half of the disagreement that lives here.
   */
  public function testWritesToBareAttributesKeyWhenPresentAndHashPrefixedOtherwise(): void {
    $bare = $this->extension->addClass(['attributes' => ['class' => ['first']]], 'second');

    $this->assertSame(
      ['first', 'second'],
      $bare['attributes']['class'],
      'A bare attributes key already on the value is the one written to.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $bare,
      'No second, hash-prefixed key is invented beside the bare one.'
    );

    $absent = $this->extension->addClass(['#markup' => 'x'], 'second');

    $this->assertSame(
      ['second'],
      $absent['#attributes']['class'],
      'With no bare key present the hash-prefixed property is created.'
    );
    $this->assertArrayNotHasKey(
      'attributes',
      $absent,
      'A bare key is never created when one was not already there.'
    );
  }

  /**
   * It adds classes to an Attribute object and to a Url at the resolved key.
   *
   * The resolved key does not have to hold an array. An `Attribute` — what a
   * preprocessed template variable usually carries — is handed the classes
   * through its own `addClass()`, and a `Url` gets them merged into its
   * `attributes` option, mutated in place, the same reach-through the `Link`
   * branch performs one level up.
   */
  public function testAddsClassesToAttributeObjectAndToUrl(): void {
    $attribute = new Attribute(['class' => ['first'], 'id' => 'kept']);

    $withAttribute = $this->extension->addClass(['#attributes' => $attribute], ['second', 'third']);

    $this->assertInstanceOf(
      Attribute::class,
      $withAttribute['#attributes'],
      'The Attribute object stays an Attribute; it is not flattened to an array.'
    );
    $this->assertSame(
      ['first', 'second', 'third'],
      $withAttribute['#attributes']['class']->value(),
      'The classes are merged through the Attribute object\'s own addClass().'
    );
    $this->assertSame(
      'kept',
      (string) $withAttribute['#attributes']['id'],
      'Other attributes on the object are untouched.'
    );

    $url = Url::fromRoute('entity.node.canonical', ['node' => 1], [
      'attributes' => ['class' => ['first']],
    ]);

    $withUrl = $this->extension->addClass(['#url' => $url], ['second', 'third'], 'url');

    $this->assertInstanceOf(
      Url::class,
      $withUrl['#url'],
      'The Url stays a Url; it is not replaced by a class list.'
    );
    $this->assertSame(
      ['first', 'second', 'third'],
      $withUrl['#url']->getOptions()['attributes']['class'],
      'The classes are merged into the Url\'s attributes option.'
    );
  }

  /**
   * It mirrors the classes into #options on a #type link element.
   *
   * Pinned as current behaviour. A `#type: link` element renders its
   * attributes out of `#options`, not out of `#attributes`, so the filter
   * copies the whole merged class list across as well. `neo_attribute` mirrors
   * too; `neo_attributes` does not, which is the second half of the
   * disagreement a later candidate has to resolve out loud.
   *
   * Note what is mirrored: the element's *entire* merged class list, appended
   * to whatever `#options` already held. A class present in both therefore
   * arrives twice. That is what the code does today.
   */
  public function testMirrorsClassesIntoOptionsOnLinkElement(): void {
    $build = [
      '#type' => 'link',
      '#title' => 'Read more',
      '#url' => Url::fromRoute('entity.node.canonical', ['node' => 1]),
      '#attributes' => ['class' => ['first']],
      '#options' => ['attributes' => ['class' => ['already']]],
    ];

    $result = $this->extension->addClass($build, ['second']);

    $this->assertSame(
      ['first', 'second'],
      $result['#attributes']['class'],
      'The element property is merged as it is for any other element.'
    );
    $this->assertSame(
      ['already', 'first', 'second'],
      $result['#options']['attributes']['class'],
      'The whole merged list is appended to the options the element already had.'
    );

    $bare = $this->extension->addClass(['#type' => 'link', '#title' => 'Read more'], 'only');

    $this->assertSame(
      ['only'],
      $bare['#options']['attributes']['class'],
      'The options path is created when the element carried none.'
    );
  }

  /**
   * It writes through a parents path to a nested element.
   *
   * Handed an array as the key, the filter treats the last entry as the key
   * and everything before it as a parents path, so a template can reach a
   * child of the value it is piping without unpacking it first. A path that
   * resolves to nothing is a no-op: the value comes back exactly as it went
   * in, with no key created along the way.
   */
  public function testWritesThroughParentsPathToNestedElement(): void {
    $build = [
      'wrapper' => ['inner' => ['#markup' => 'x']],
      'sibling' => ['#markup' => 'y'],
    ];

    $result = $this->extension->addClass($build, 'deep', ['wrapper', 'inner', 'attributes']);

    $this->assertSame(
      [
        'wrapper' => [
          'inner' => [
            '#markup' => 'x',
            '#attributes' => ['class' => ['deep']],
          ],
        ],
        'sibling' => ['#markup' => 'y'],
      ],
      $result,
      'The last entry is the key, the rest is the path, and nothing else moves.'
    );

    $missing = $this->extension->addClass($build, 'deep', ['nowhere', 'attributes']);

    $this->assertSame(
      $build,
      $missing,
      'A path that resolves to nothing writes nothing and creates nothing.'
    );
  }

}
