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
 * Tests `neo_attributes` and `neo_attribute`, the two narrower writers.
 *
 * `mergeAttributes()` merges an `Attribute` object or an array of attributes
 * into an element; `setAttribute()` sets one named attribute. Both take the
 * same key argument as `neo_class` and run the same preamble — empty guard,
 * `Link` branch, non-array guard, parents path — and both then diverge from it.
 * Nothing here needs a container: `Attribute`, `Link`, `Url` and `NestedArray`
 * all construct without one.
 *
 * Both writers reach through a `Link` to the `Url` it wraps and write into
 * that URL's options — `neo_attributes` merging a whole attribute set,
 * `neo_attribute` setting one name.
 *
 * Two of the behaviours below are pinned as they stand. Each records a
 * **defect**, not an intention, so that the candidate which repairs it has to
 * edit an assertion that was expecting it:
 *
 * - Both writers apply the strict **hash-prefix rule**: they *always* prefix.
 *   On a value carrying a bare `attributes` key they therefore write to
 *   `#attributes`, where `neo_class` writes to `attributes`. Same argument,
 *   same value, two destinations.
 * - `neo_attribute` mirrors its write into `#options` on a `#type: link`
 *   element. `neo_attributes` does not, so a merge onto a link element misses
 *   the mirror the other two writers make.
 *
 * @see \Drupal\Tests\neo_twig\Unit\TwigExtensionAddClassTest
 */
#[Group('neo_twig')]
final class TwigExtensionAttributesTest extends UnitTestCase {

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
   * It merges an Attribute object and an array of attributes into an element.
   *
   * Either shape is accepted — an array is wrapped in an `Attribute` before
   * anything else happens — and both produce the same result. What the element
   * arrived with survives: a class list is appended to rather than replaced,
   * and an attribute the incoming set does not mention is left alone.
   *
   * Note the return shape. Unlike `neo_class`, which leaves the property as
   * the array it found, this filter replaces it with an `Attribute` object.
   */
  public function testMergesAttributeObjectAndArrayIntoElementAttributes(): void {
    $build = [
      '#markup' => 'x',
      '#attributes' => [
        'class' => ['first'],
        'id' => 'kept',
      ],
    ];
    $expected = [
      'class' => ['first', 'second'],
      'id' => 'kept',
      'data-role' => 'panel',
    ];

    $object = $this->extension->mergeAttributes($build, new Attribute([
      'class' => ['second'],
      'data-role' => 'panel',
    ]));

    $this->assertInstanceOf(
      Attribute::class,
      $object['#attributes'],
      'The property comes back as an Attribute, not as the array it was.'
    );
    $this->assertSame(
      $expected,
      $object['#attributes']->toArray(),
      'The incoming Attribute is merged over what the element already had.'
    );

    $array = $this->extension->mergeAttributes($build, [
      'class' => ['second'],
      'data-role' => 'panel',
    ]);

    $this->assertSame(
      $expected,
      $array['#attributes']->toArray(),
      'An array of attributes is wrapped and merged the same way.'
    );
  }

  /**
   * It merges an Attribute object into a Link's url options.
   *
   * A `Link` is not a render array and has no property to write to, so the
   * filter reaches through to the `Url` it wraps: it reads that URL's options,
   * merges the incoming set into whatever `attributes` option is already
   * there, and writes the options back. The `Link` itself comes back, mutated
   * in place, so a template can keep piping it — which is what `neo_attribute`
   * does on the same value, one name at a time.
   */
  public function testMergesAttributeObjectIntoLinkUrlOptions(): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => 1]);
    $link = Link::fromTextAndUrl('Read more', $url);

    $result = $this->extension->mergeAttributes($link, new Attribute([
      'class' => ['second'],
      'data-role' => 'panel',
    ]));

    $this->assertSame($link, $result, 'The same Link object is returned, mutated in place.');
    $this->assertSame(
      ['class' => ['second'], 'data-role' => 'panel'],
      $result->getUrl()->getOptions()['attributes'] ?? NULL,
      'The merged set is written into the wrapped URL\'s attributes option.'
    );
  }

  /**
   * It merges an array of attributes into a Link's url options.
   *
   * The same wrapping the render-array path does happens before the `Link`
   * branch is reached, so an array argument and an `Attribute` argument are
   * the same call by the time the URL is touched.
   */
  public function testMergesArrayOfAttributesIntoLinkUrlOptions(): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => 1]);
    $link = Link::fromTextAndUrl('Read more', $url);

    $result = $this->extension->mergeAttributes($link, [
      'class' => ['second'],
      'data-role' => 'panel',
    ]);

    $this->assertSame($link, $result, 'The same Link object is returned, mutated in place.');
    $this->assertSame(
      ['class' => ['second'], 'data-role' => 'panel'],
      $result->getUrl()->getOptions()['attributes'] ?? NULL,
      'An array of attributes is wrapped and merged the same way.'
    );
  }

  /**
   * It merges into a Link that already carries attributes, keeping both sets.
   *
   * `Attribute::merge()` deep-merges, so a class list on the link and a class
   * list from the caller are concatenated rather than replaced and duplicates
   * are not removed. An attribute the incoming set does not mention is left
   * alone. That is deliberately not `neo_class`'s flat `array_merge` on the
   * class key alone — a whole-set deep merge is what distinguishes this
   * writer from that one.
   */
  public function testMergesIntoLinkAlreadyCarryingAttributesKeepingBothSets(): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => 1], [
      'attributes' => [
        'class' => ['first'],
        'id' => 'kept',
      ],
    ]);
    $link = Link::fromTextAndUrl('Read more', $url);

    $result = $this->extension->mergeAttributes($link, [
      'class' => ['second'],
      'data-role' => 'panel',
    ]);

    $this->assertSame(
      [
        'class' => ['first', 'second'],
        'id' => 'kept',
        'data-role' => 'panel',
      ],
      $result->getUrl()->getOptions()['attributes'] ?? NULL,
      'Both sets survive: the class lists concatenate and nothing is dropped.'
    );
  }

  /**
   * It merges attributes into a Link without disturbing other url options.
   *
   * Only the `attributes` key of the options array is rewritten. Everything
   * else the URL was built with — a query, a fragment, an absolute flag —
   * comes back through `setOptions()` exactly as it went in.
   */
  public function testMergesIntoLinkWithoutDisturbingOtherUrlOptions(): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => 1], [
      'attributes' => ['class' => ['first']],
      'query' => ['page' => '2'],
      'fragment' => 'main',
      'absolute' => TRUE,
    ]);
    $link = Link::fromTextAndUrl('Read more', $url);

    $options = $this->extension
      ->mergeAttributes($link, ['data-role' => 'panel'])
      ->getUrl()
      ->getOptions();

    $this->assertSame(
      ['class' => ['first'], 'data-role' => 'panel'],
      $options['attributes'] ?? NULL,
      'The merge lands on the attributes option.'
    );
    $this->assertSame(['page' => '2'], $options['query'], 'The query is untouched.');
    $this->assertSame('main', $options['fragment'], 'The fragment is untouched.');
    $this->assertTrue($options['absolute'], 'The absolute flag is untouched.');
  }

  /**
   * It sets a single named attribute on an element.
   *
   * The narrowest of the three writers: one name, one value, written straight
   * onto the resolved property. Attributes already there are left alone, a
   * name already present is overwritten, and — unlike `neo_attributes` — the
   * property stays the plain array it was.
   */
  public function testSetsSingleNamedAttributeOnElement(): void {
    $build = [
      '#markup' => 'x',
      '#attributes' => ['class' => ['first']],
    ];

    $result = $this->extension->setAttribute($build, 'data-role', 'panel');

    $this->assertSame(
      ['class' => ['first'], 'data-role' => 'panel'],
      $result['#attributes'],
      'The named attribute is added and the property stays a plain array.'
    );

    $overwritten = $this->extension->setAttribute($result, 'data-role', 'dialog');

    $this->assertSame(
      'dialog',
      $overwritten['#attributes']['data-role'],
      'A name already present is overwritten rather than merged.'
    );

    $created = $this->extension->setAttribute(['#markup' => 'x'], 'data-role', 'panel');

    $this->assertSame(
      ['data-role' => 'panel'],
      $created['#attributes'],
      'The property is created when the element carried none.'
    );
  }

  /**
   * It sets a named attribute on a Link's url options.
   *
   * A `Link` is not a render array and has no property to write to, so the
   * filter reaches through to the URL it wraps and writes the attribute into
   * that URL's `attributes` option. The `Link` itself comes back, mutated in
   * place, so a template can keep piping it — the same reach-through the
   * `neo_attributes` branch above makes with a whole set.
   */
  public function testSetsNamedAttributeOnLinkUrlOptions(): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => 1], [
      'attributes' => ['class' => ['first']],
    ]);
    $link = Link::fromTextAndUrl('Read more', $url);

    $result = $this->extension->setAttribute($link, 'data-role', 'panel');

    $this->assertInstanceOf(Link::class, $result, 'A Link comes back as a Link.');
    $this->assertSame($link, $result, 'The same Link object is returned, mutated in place.');
    $this->assertSame(
      ['class' => ['first'], 'data-role' => 'panel'],
      $result->getUrl()->getOptions()['attributes'],
      'The attribute is written into the wrapped URL\'s attributes option.'
    );
  }

  /**
   * It always writes to the hash-prefixed key, even when a bare one is there.
   *
   * **Pinned as current behaviour.** Both writers prefix the key with `#`
   * unconditionally, before the element is even resolved. A value carrying a
   * bare `attributes` key — a preprocessed template variable, say — therefore
   * gains a second, hash-prefixed property, and the bare one it already had is
   * left behind untouched.
   *
   * `neo_class` does the opposite: it prefixes only when the bare key is
   * absent, so the same argument against the same value writes to `attributes`
   * there and `#attributes` here. That disagreement is the **hash-prefix
   * rule**, and a later candidate unifies it; this is the half of it that
   * lives in these two writers.
   */
  public function testAlwaysWritesToHashPrefixedKeyEvenBesideBareOne(): void {
    $merged = $this->extension->mergeAttributes(
      ['attributes' => ['class' => ['first']]],
      ['class' => ['second']]
    );

    $this->assertSame(
      ['class' => ['first']],
      $merged['attributes'],
      'neo_attributes leaves the bare key exactly as it found it.'
    );
    $this->assertSame(
      ['class' => ['second']],
      $merged['#attributes']->toArray(),
      'The merge lands on a second, hash-prefixed property beside it.'
    );

    $set = $this->extension->setAttribute(
      ['attributes' => ['data-role' => 'first']],
      'data-role',
      'second'
    );

    $this->assertSame(
      ['data-role' => 'first'],
      $set['attributes'],
      'neo_attribute leaves the bare key exactly as it found it.'
    );
    $this->assertSame(
      ['data-role' => 'second'],
      $set['#attributes'],
      'The write lands on a second, hash-prefixed property beside it.'
    );
  }

  /**
   * It mirrors a set attribute into #options, and does not mirror a merge.
   *
   * **Pinned as current behaviour.** A `#type: link` element renders its
   * attributes out of `#options`, not out of `#attributes`, so `neo_attribute`
   * copies its write across — as `neo_class` does. `neo_attributes` has no
   * such branch at all, so a merge onto a link element writes a property the
   * rendered link never reads, and the element's `#options` come out of the
   * filter untouched.
   *
   * Two of the three writers mirror and one does not; a later candidate folds
   * that into whichever it makes authoritative, and this is the assertion it
   * has to flip.
   */
  public function testMirrorsSetAttributeIntoOptionsButNotMergedOnes(): void {
    $build = [
      '#type' => 'link',
      '#title' => 'Read more',
      '#url' => Url::fromRoute('entity.node.canonical', ['node' => 1]),
      '#attributes' => ['class' => ['first']],
      '#options' => ['attributes' => ['data-existing' => 'kept']],
    ];

    $set = $this->extension->setAttribute($build, 'data-role', 'panel');

    $this->assertSame(
      'panel',
      $set['#attributes']['data-role'],
      'The attribute is written onto the element property as usual.'
    );
    $this->assertSame(
      ['data-existing' => 'kept', 'data-role' => 'panel'],
      $set['#options']['attributes'],
      'It is mirrored into the options the link renders from.'
    );

    $merged = $this->extension->mergeAttributes($build, ['data-role' => 'panel']);

    $this->assertSame(
      'panel',
      (string) $merged['#attributes']['data-role'],
      'The merge reaches the element property just the same.'
    );
    $this->assertSame(
      ['data-existing' => 'kept'],
      $merged['#options']['attributes'],
      'But nothing is mirrored, so the rendered link never sees it.'
    );
  }

  /**
   * It writes through a parents path to a nested element.
   *
   * Handed an array as the key, both writers treat the last entry as the key
   * and everything before it as a parents path, so a template can reach a
   * child of the value it is piping without unpacking it first. A path that
   * resolves to nothing is a no-op for either: the value comes back exactly as
   * it went in, with no key created along the way.
   */
  public function testWritesThroughParentsPathToNestedElement(): void {
    $build = [
      'wrapper' => ['inner' => ['#markup' => 'x']],
      'sibling' => ['#markup' => 'y'],
    ];
    $path = ['wrapper', 'inner', 'attributes'];

    $merged = $this->extension->mergeAttributes($build, ['class' => ['deep']], $path);

    $this->assertInstanceOf(
      Attribute::class,
      $merged['wrapper']['inner']['#attributes'] ?? NULL,
      'The last entry is the key and the rest is the path.'
    );
    $this->assertSame(
      ['class' => ['deep']],
      $merged['wrapper']['inner']['#attributes']->toArray(),
      'The merged attributes land on the nested element.'
    );
    $this->assertSame(
      ['#markup' => 'y'],
      $merged['sibling'],
      'Nothing outside the path moves.'
    );

    $set = $this->extension->setAttribute($build, 'data-role', 'panel', $path);

    $this->assertSame(
      ['#markup' => 'x', '#attributes' => ['data-role' => 'panel']],
      $set['wrapper']['inner'],
      'neo_attribute resolves the same path to the same element.'
    );

    $this->assertSame(
      $build,
      $this->extension->mergeAttributes($build, ['class' => ['deep']], ['nowhere', 'attributes']),
      'A path that resolves to nothing merges nothing and creates nothing.'
    );
    $this->assertSame(
      $build,
      $this->extension->setAttribute($build, 'data-role', 'panel', ['nowhere', 'attributes']),
      'The same is true of neo_attribute.'
    );
  }

}
