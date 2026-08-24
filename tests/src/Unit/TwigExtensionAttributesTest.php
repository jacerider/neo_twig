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
 * Both writers resolve their write target through the one seam that owns the
 * **hash-prefix rule**, and there is one rule: a key is prefixed with `#`
 * only when the element carries no bare key of that name. Same argument, same
 * value, one destination — the same one `neo_class` resolves.
 *
 * One behaviour below is still pinned as it stands. It records a **defect**,
 * not an intention, so that the candidate which repairs it has to edit an
 * assertion that was expecting it:
 *
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
   * It writes to a bare attributes key when the element carries one.
   *
   * The **hash-prefix rule**, and there is now one of them. A key is prefixed
   * with `#` only when the element carries no bare key of that name, so a
   * value holding a bare `attributes` key — a preprocessed template variable,
   * say — is merged into where it stands rather than gaining a second,
   * hash-prefixed property that nothing reads. This is the rule `neo_class`
   * has always applied; `neo_attributes` used to prefix unconditionally and
   * write past the key its caller could see.
   */
  public function testMergesIntoBareAttributesKeyWhenElementCarriesOne(): void {
    $merged = $this->extension->mergeAttributes(
      ['attributes' => ['class' => ['first']]],
      ['class' => ['second']]
    );

    $this->assertInstanceOf(
      Attribute::class,
      $merged['attributes'],
      'The bare key the value already carried is the one written to.'
    );
    $this->assertSame(
      ['class' => ['first', 'second']],
      $merged['attributes']->toArray(),
      'The incoming set is merged over what the bare key already held.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $merged,
      'No second, hash-prefixed property is invented beside the bare one.'
    );
  }

  /**
   * It writes to a bare attributes key when the element carries one.
   *
   * The same rule reaches the narrow writer, because both resolve their write
   * target through the one seam that owns it. A bare `attributes` key already
   * on the value is a key something already reads, so the named attribute is
   * set there; nothing is written past it to a hash-prefixed property beside
   * it. `neo_attribute` used to do exactly that.
   */
  public function testSetsNamedAttributeOnBareAttributesKeyWhenElementCarriesOne(): void {
    $set = $this->extension->setAttribute(
      ['attributes' => ['data-role' => 'first']],
      'data-role',
      'second'
    );

    $this->assertSame(
      ['data-role' => 'second'],
      $set['attributes'],
      'The bare key the value already carried is the one written to.'
    );
    $this->assertArrayNotHasKey(
      '#attributes',
      $set,
      'No second, hash-prefixed property is invented beside the bare one.'
    );
  }

  /**
   * It still hash-prefixes when the element carries no bare key of that name.
   *
   * The other half of the one rule, and the half that did not move. With
   * nothing bare to write to, the key is prefixed and the property is created
   * — for both writers here, and for `neo_class`, whose half of this stays
   * pinned and unmoved in TwigExtensionAddClassTest. A bare key is never
   * created when one was not already there.
   */
  public function testStillHashPrefixesWhenElementCarriesNoBareKey(): void {
    $merged = $this->extension->mergeAttributes(['#markup' => 'x'], ['class' => ['second']]);

    $this->assertSame(
      ['class' => ['second']],
      $merged['#attributes']->toArray(),
      'neo_attributes creates the hash-prefixed property when no bare key is there.'
    );
    $this->assertArrayNotHasKey(
      'attributes',
      $merged,
      'neo_attributes never invents a bare key that was not already there.'
    );

    $set = $this->extension->setAttribute(['#markup' => 'x'], 'data-role', 'panel');

    $this->assertSame(
      ['data-role' => 'panel'],
      $set['#attributes'],
      'neo_attribute creates the hash-prefixed property when no bare key is there.'
    );
    $this->assertArrayNotHasKey(
      'attributes',
      $set,
      'neo_attribute never invents a bare key that was not already there.'
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

  /**
   * It merges attributes into a Url at the resolved key, not over the top.
   *
   * The resolved key does not have to hold an array. A `#type: link` element
   * carries a `Url` at `#url`, and pointing this writer at that key used to
   * build a fresh attribute set out of an object with nothing to iterate from
   * outside — an empty one — and write it over the top, destroying the link's
   * destination silently. The `Url` now stays a `Url`, mutated in place, with
   * the incoming set merged into its own `attributes` option: the same
   * reach-through the `Link` branch performs one level up, and the same shape
   * `neo_class` already handles here.
   */
  public function testMergesAttributesIntoUrlAtResolvedKeyRatherThanReplacingIt(): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => 1], [
      'attributes' => [
        'class' => ['first'],
        'id' => 'kept',
      ],
      'query' => ['page' => '2'],
    ]);
    $build = [
      '#type' => 'link',
      '#title' => 'Read more',
      '#url' => $url,
    ];

    $result = $this->extension->mergeAttributes($build, [
      'class' => ['second'],
      'data-role' => 'panel',
    ], 'url');

    $this->assertInstanceOf(
      Url::class,
      $result['#url'],
      'The Url stays a Url; it is not replaced by an attribute set.'
    );
    $this->assertSame($url, $result['#url'], 'It is the same Url, mutated in place.');
    $this->assertSame(
      [
        'class' => ['first', 'second'],
        'id' => 'kept',
        'data-role' => 'panel',
      ],
      $result['#url']->getOptions()['attributes'],
      'The incoming set is merged into the Url\'s own attributes option.'
    );
    $this->assertSame(
      ['page' => '2'],
      $result['#url']->getOptions()['query'],
      'No other url option is disturbed.'
    );
  }

  /**
   * It sets a named attribute into a Url at the resolved key, not over the top.
   *
   * The narrow writer meets the same shape and takes the same route: a `Url`
   * at the resolved key is not an array, so the name and value go into the
   * url's own `attributes` option and the `Url` survives. Pointing this writer
   * at a `#type: link` element's `#url` used to try to use that object as an
   * array; whatever it did, it was never a link that still went anywhere.
   */
  public function testSetsNamedAttributeIntoUrlAtResolvedKeyRatherThanReplacingIt(): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => 1], [
      'attributes' => ['class' => ['first']],
      'query' => ['page' => '2'],
    ]);
    $build = [
      '#type' => 'link',
      '#title' => 'Read more',
      '#url' => $url,
    ];

    $result = $this->extension->setAttribute($build, 'data-role', 'panel', 'url');

    $this->assertInstanceOf(
      Url::class,
      $result['#url'],
      'The Url stays a Url; it is not replaced by an attribute list.'
    );
    $this->assertSame($url, $result['#url'], 'It is the same Url, mutated in place.');
    $this->assertSame(
      ['class' => ['first'], 'data-role' => 'panel'],
      $result['#url']->getOptions()['attributes'],
      'The named attribute is written into the Url\'s own attributes option.'
    );
    $this->assertSame(
      ['page' => '2'],
      $result['#url']->getOptions()['query'],
      'No other url option is disturbed.'
    );
  }

  /**
   * It merges attributes into an Attribute object at the resolved key.
   *
   * An `Attribute` — what a preprocessed template variable usually carries —
   * is written into rather than replaced, as `neo_class` already writes into
   * one. That matters beyond the return value: the object at the key is the
   * same object a preprocess function still holds a handle to, so building a
   * fresh one out of it would leave that handle pointing at the unmerged set.
   */
  public function testMergesAttributesIntoAttributeObjectAtResolvedKey(): void {
    $attribute = new Attribute(['class' => ['first'], 'id' => 'kept']);
    $build = ['#markup' => 'x', '#attributes' => $attribute];

    $result = $this->extension->mergeAttributes($build, [
      'class' => ['second'],
      'data-role' => 'panel',
    ]);

    $this->assertSame(
      $attribute,
      $result['#attributes'],
      'The Attribute object is written into, not replaced by a fresh one.'
    );
    $this->assertSame(
      [
        'class' => ['first', 'second'],
        'id' => 'kept',
        'data-role' => 'panel',
      ],
      $attribute->toArray(),
      'The incoming set is merged over what the object already carried.'
    );
  }

  /**
   * It sets a named attribute on an Attribute object at the resolved key.
   *
   * This is the one shape the narrow writer was already lucky with: an
   * `Attribute` implements array access, so a name and a value written onto
   * it land in its storage and the object survives. Pinned deliberately now
   * rather than left to luck, because it is the shape `neo_class` handles on
   * purpose and the one the permissive hash-prefix rule widens the ways of
   * reaching.
   */
  public function testSetsNamedAttributeOnAttributeObjectAtResolvedKey(): void {
    $attribute = new Attribute(['class' => ['first'], 'id' => 'kept']);
    $build = ['#markup' => 'x', '#attributes' => $attribute];

    $result = $this->extension->setAttribute($build, 'data-role', 'panel');

    $this->assertSame(
      $attribute,
      $result['#attributes'],
      'The Attribute object is written into, not replaced.'
    );
    $this->assertSame(
      [
        'class' => ['first'],
        'id' => 'kept',
        'data-role' => 'panel',
      ],
      $attribute->toArray(),
      'The named attribute joins what the object already carried.'
    );
  }

  /**
   * It leaves an array-shaped value behaving exactly as it did, for both.
   *
   * The guard on the branch split above. Learning two object shapes is only
   * safe if the shape both writers already handled is untouched by it: an
   * array-shaped value must still take neither the `Attribute` branch nor the
   * `Url` one. So `neo_attributes` still hands back an `Attribute` where it
   * found an array, `neo_attribute` still hands back the plain array it was
   * given, and each still creates the property when the element carried none.
   */
  public function testLeavesArrayShapedAttributesValueBehavingExactlyAsItDid(): void {
    $build = [
      '#markup' => 'x',
      '#attributes' => [
        'class' => ['first'],
        'id' => 'kept',
      ],
    ];

    $merged = $this->extension->mergeAttributes($build, [
      'class' => ['second'],
      'data-role' => 'panel',
    ]);

    $this->assertInstanceOf(
      Attribute::class,
      $merged['#attributes'],
      'An array found at the key still comes back as an Attribute.'
    );
    $this->assertSame(
      [
        'class' => ['first', 'second'],
        'id' => 'kept',
        'data-role' => 'panel',
      ],
      $merged['#attributes']->toArray(),
      'And it still carries the same merge it always did.'
    );
    $this->assertSame(
      ['class' => ['first'], 'id' => 'kept'],
      $build['#attributes'],
      'The array it was handed is not mutated in place.'
    );

    $set = $this->extension->setAttribute($build, 'data-role', 'panel');

    $this->assertSame(
      [
        'class' => ['first'],
        'id' => 'kept',
        'data-role' => 'panel',
      ],
      $set['#attributes'],
      'neo_attribute still leaves the property the plain array it was.'
    );

    $this->assertSame(
      ['class' => ['only']],
      $this->extension
        ->mergeAttributes(['#markup' => 'x'], ['class' => ['only']])['#attributes']
        ->toArray(),
      'A missing property is still created by neo_attributes.'
    );
    $this->assertSame(
      ['data-role' => 'panel'],
      $this->extension->setAttribute(['#markup' => 'x'], 'data-role', 'panel')['#attributes'],
      'A missing property is still created by neo_attribute.'
    );
  }

}
