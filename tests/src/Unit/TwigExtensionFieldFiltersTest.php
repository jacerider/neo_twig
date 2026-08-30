<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\Tests\UnitTestCase;
use Drupal\field\FieldConfigInterface;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the three filters standing behind the field-shape gate.
 *
 * `neo_label`, `neo_value` and `neo_raw` each open with the same two-line
 * check — `isset($build['#theme']) && $build['#theme'] == 'field'` — and each
 * answers `NULL` when it fails. So does each of them when the check *passes*
 * and the field turns out to hold nothing. The two readings are
 * indistinguishable at the call site, and a template author who gets `NULL`
 * has no way to tell "you piped me something that is not a field" from "the
 * field is empty" without opening the class. This class pins **both**
 * readings, so the candidate that later makes these filters explain
 * themselves has the current ambiguity written down rather than inferred.
 *
 * The three filters are covered together because they share that one gate and
 * nothing else: past it, `neo_label` resolves a label from three sources in
 * order, `neo_value` splits a field into one render array per item, and
 * `neo_raw` reaches into the item list's own values. `neo_target_entity`, the
 * fourth filter behind the same gate, needs a real content entity to iterate
 * and is covered by the module's kernel tests instead.
 *
 * Nothing here needs a container or a database. The field item list is a test
 * double, `Element::children()` is a static array walk, and the extension is
 * constructed directly with a twig-config array.
 *
 * **One shape difference worth stating out loud.** `neo_raw` collapses a
 * single-item result to the value itself rather than returning a one-entry
 * list, so the same filter on the same field returns a list or a bare value
 * depending on how many items the editor happened to save. A template written
 * against the list form breaks silently on a single-value field. That is
 * current behaviour and it is asserted as such.
 *
 * **The gate now says which of its `NULL`s it answered**, and the criteria
 * after the characterisation ones are that. They live here, beside the
 * ambiguity they resolve, so the two fail together: a notice that changed what
 * a filter answers breaks a characterisation criterion in the same run. None
 * of those is edited, and the `NULL` they pin is still the `NULL` every reason
 * produces — the notice is additional, never a substitute.
 *
 * `neo_target_entity` appears here for its **gate failure only**, which is the
 * one reason of its four that stops before any entity is touched. Its three
 * later reasons — no field name, no parent object, and a reference that
 * resolved to nothing — are pinned beside the rest of that filter's behaviour
 * in the kernel class, which is where a real content entity lives.
 *
 * One departure from the paragraph above: the notice criteria install a
 * container carrying a logger factory double and nothing else, because the
 * seam resolves its logger lazily the way `neo_oembed` resolves its services.
 * The characterisation criteria still need none.
 */
#[Group('neo_twig')]
final class TwigExtensionFieldFiltersTest extends UnitTestCase {

  /**
   * The four filters standing behind the field-shape gate.
   *
   * Keyed by the **registered name** a template author types, because that is
   * what a notice has to say; the value is the PHP method behind it, which a
   * notice must never say.
   */
  private const GATE_FILTERS = [
    'neo_label' => 'getFieldLabel',
    'neo_value' => 'getFieldValue',
    'neo_raw' => 'getRawValues',
    'neo_target_entity' => 'getTargetEntity',
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
   * It answers NULL from each of the three filters for anything not a field.
   *
   * The gate is a loose equality against one exact string, so it is narrower
   * than "looks like a render array": an element with no `#theme` at all, an
   * element themed as something else, and an element whose `#theme` is the
   * *array* of suggestions core sometimes builds all fail it — the last one
   * because an array is never loosely equal to a string in PHP 8. So do the
   * scalars and objects a template can pipe by mistake. Every one of them
   * answers `NULL` from all three filters, with no exception and no notice.
   *
   * The second half is the ambiguity itself. A render array that *is* a field
   * and simply has nothing in it — no `#title`, no children, no `#items` —
   * answers the identical `NULL` from all three. The gate's answer and the
   * empty field's answer are the same value, and this asserts that they are,
   * so a later change that separates them has to change this test.
   */
  public function testAnswersNullFromEachFilterForAnythingThatIsNotFieldShaped(): void {
    foreach (self::notFieldRenderArrays() as $label => $value) {
      $this->assertNull(
        $this->extension->getFieldLabel($value),
        'neo_label answers NULL for ' . $label . '.'
      );
      $this->assertNull(
        $this->extension->getFieldValue($value),
        'neo_value answers NULL for ' . $label . '.'
      );
      $this->assertNull(
        $this->extension->getRawValues($value),
        'neo_raw answers NULL for ' . $label . '.'
      );
      $this->assertNull(
        $this->extension->getRawValues($value, 'value'),
        'neo_raw with a named key answers NULL for ' . $label . '.'
      );
    }

    // The gate's own answer, and the answer for a field holding nothing, are
    // the same value arrived at by different routes.
    $empty_field = ['#theme' => 'field'];

    $this->assertNull(
      $this->extension->getFieldLabel($empty_field),
      'neo_label answers NULL for a field render array holding nothing.'
    );
    $this->assertNull(
      $this->extension->getFieldValue($empty_field),
      'neo_value answers NULL for a field render array holding nothing.'
    );
    $this->assertNull(
      $this->extension->getRawValues($empty_field),
      'neo_raw answers NULL for a field render array holding nothing.'
    );

    $this->assertSame(
      $this->extension->getFieldLabel('not a field at all'),
      $this->extension->getFieldLabel($empty_field),
      'neo_label gives one answer for both readings: they are indistinguishable.'
    );
    $this->assertSame(
      $this->extension->getFieldValue('not a field at all'),
      $this->extension->getFieldValue($empty_field),
      'neo_value gives one answer for both readings: they are indistinguishable.'
    );
    $this->assertSame(
      $this->extension->getRawValues('not a field at all'),
      $this->extension->getRawValues($empty_field),
      'neo_raw gives one answer for both readings: they are indistinguishable.'
    );
  }

  /**
   * It returns a base field's configured display label.
   *
   * A base field carries its label override inside its own settings, under
   * `field_labels.display_label`, and that is the first of `neo_label`'s three
   * sources — tried before anything else and returned as-is, whitespace and
   * markup included, with no fallback consulted.
   *
   * `#title` is present in every case here and is never the answer, which is
   * the point: this source outranks it. So is `#field_label_default`, the flag
   * that suppresses the *config* field source — it is not consulted on this
   * branch at all, so a base field returns its configured label even when the
   * render array asks for the default one. That asymmetry between the two
   * sources is the behaviour, not an oversight in the test.
   *
   * An empty setting is not a label. `empty()` guards this source, so an empty
   * string, `'0'` and a missing key alike fall through to `#title` rather than
   * being returned.
   */
  public function testReturnsTheBaseFieldsConfiguredDisplayLabel(): void {
    $build = $this->fieldBuild(
      $this->baseFieldItems(['field_labels' => ['display_label' => 'Project lead']]),
      ['#title' => 'Uid']
    );

    $this->assertSame(
      'Project lead',
      $this->extension->getFieldLabel($build),
      "A base field's configured display label outranks the element title."
    );

    $build['#field_label_default'] = TRUE;

    $this->assertSame(
      'Project lead',
      $this->extension->getFieldLabel($build),
      'The default-label flag does not suppress the base field source.'
    );

    $verbatim = $this->fieldBuild(
      $this->baseFieldItems(['field_labels' => ['display_label' => '  Lead & <em>owner</em>  ']]),
      ['#title' => 'Uid']
    );

    $this->assertSame(
      '  Lead & <em>owner</em>  ',
      $this->extension->getFieldLabel($verbatim),
      'The configured label is returned verbatim: no trim, no escaping.'
    );

    $unconfigured = [
      'an empty display label' => ['field_labels' => ['display_label' => '']],
      'a display label of "0"' => ['field_labels' => ['display_label' => '0']],
      'a NULL display label' => ['field_labels' => ['display_label' => NULL]],
      'no display_label key' => ['field_labels' => []],
      'no field_labels key' => [],
    ];
    foreach ($unconfigured as $label => $settings) {
      $this->assertSame(
        'Uid',
        $this->extension->getFieldLabel(
          $this->fieldBuild($this->baseFieldItems($settings), ['#title' => 'Uid'])
        ),
        'A base field with ' . $label . ' falls through to the element title.'
      );
    }
  }

  /**
   * It returns a config field's third-party display label.
   *
   * The second source reads a third-party setting rather than the field's own
   * settings, because a config field cannot add keys to its settings schema.
   * It is asked for under exactly one module name and one key — `field_labels`
   * and `display_label` — and this asserts those two strings, because they are
   * the contract with whatever wrote the setting and nothing else in the class
   * names them.
   *
   * The branch is an `elseif`, so it is unreachable for a base field however
   * its third-party settings are configured. It is also conditional on
   * `#field_label_default` being empty: a render array asking for the default
   * label skips this source entirely and falls through to `#title`, and the
   * *definition is never even asked* on that path, which is the sharper claim
   * and the one asserted here.
   *
   * `empty()` decides what "asking for the default" means, so `FALSE`, `0`,
   * `''` and `NULL` on that key are all read as *not* asking, and leave the
   * configured label in place.
   */
  public function testReturnsTheConfigFieldsThirdPartyDisplayLabelAndSkipsItForTheDefault(): void {
    $build = $this->fieldBuild(
      $this->configFieldItems('Client contact'),
      ['#title' => 'Body']
    );

    $this->assertSame(
      'Client contact',
      $this->extension->getFieldLabel($build),
      "A config field's third-party display label outranks the element title."
    );

    // The setting is read from one module name and one key, and no other.
    $definition = $this->createMock(FieldConfigInterface::class);
    $definition->expects($this->once())
      ->method('getThirdPartySetting')
      ->with('field_labels', 'display_label')
      ->willReturn('Client contact');
    $this->extension->getFieldLabel(
      $this->fieldBuild($this->itemsWithDefinition($definition), ['#title' => 'Body'])
    );

    // Asking for the default label skips the source without consulting it.
    $untouched = $this->createMock(FieldConfigInterface::class);
    $untouched->expects($this->never())->method('getThirdPartySetting');
    $default = $this->fieldBuild(
      $this->itemsWithDefinition($untouched),
      ['#title' => 'Body', '#field_label_default' => TRUE]
    );

    $this->assertSame(
      'Body',
      $this->extension->getFieldLabel($default),
      'A render array asking for the default label falls through to the title.'
    );

    // What counts as asking is decided by empty(), so these four do not.
    foreach ([FALSE, 0, '', NULL] as $not_asking) {
      $build['#field_label_default'] = $not_asking;
      $this->assertSame(
        'Client contact',
        $this->extension->getFieldLabel($build),
        'A falsy #field_label_default leaves the configured label in place.'
      );
    }

    // An unconfigured setting is not a label, whatever shape the emptiness.
    foreach (['an empty string' => '', 'the string "0"' => '0', 'NULL' => NULL] as $label => $value) {
      $this->assertSame(
        'Body',
        $this->extension->getFieldLabel(
          $this->fieldBuild($this->configFieldItems($value), ['#title' => 'Body'])
        ),
        'A config field whose display label is ' . $label . ' falls through.'
      );
    }

    // The branch is an elseif: a base field never reaches it, however its own
    // third-party settings are configured.
    $base = $this->createMock(BaseFieldDefinition::class);
    $base->method('getSettings')->willReturn([]);

    $this->assertSame(
      'Body',
      $this->extension->getFieldLabel(
        $this->fieldBuild($this->itemsWithDefinition($base), ['#title' => 'Body'])
      ),
      'A base field falls straight to the title: the config branch is an elseif.'
    );
  }

  /**
   * Builds an item list whose definition is a config field.
   *
   * @param string|null $label
   *   What the definition reports for the field_labels display_label setting.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The item list double.
   */
  private function configFieldItems(?string $label): FieldItemListInterface {
    $definition = $this->createMock(FieldConfigInterface::class);
    $definition->method('getThirdPartySetting')->willReturn($label);
    return $this->itemsWithDefinition($definition);
  }

  /**
   * It falls back to the element's title when no display label is configured.
   *
   * The third source is `#title`, and it is reached by four different routes:
   * no `#items` on the element at all, an item list whose definition is
   * neither of the two the first two sources recognise, a base field with
   * nothing configured, and a config field asked for its default label. All
   * four produce the same answer, so nothing in the return value says which
   * route was taken.
   *
   * It is a `??`, not an `empty()` check, so it is narrower than the two
   * sources above it: only a *missing* or `NULL` title falls past it. A title
   * that is the empty string is returned as the empty string, and so is `'0'`.
   * A field with no title at all answers `NULL` — the same `NULL` the gate
   * gives for something that is not a field, reached three sources later.
   *
   * The title is handed back as the object it is. A field's `#title` is
   * normally a `TranslatableMarkup`, and `neo_label` returns that object
   * rather than a string, so a template that pipes the result into anything
   * expecting a string is relying on Twig's own casting, not on this filter.
   */
  public function testFallsBackToTheElementsTitleWhenNoDisplayLabelIsConfigured(): void {
    $plain = $this->createMock(FieldDefinitionInterface::class);
    $base = $this->createMock(BaseFieldDefinition::class);
    $base->method('getSettings')->willReturn([]);

    $routes = [
      'no #items at all' => NULL,
      'a definition that is neither source' => $this->itemsWithDefinition($plain),
      'a base field with nothing configured' => $this->itemsWithDefinition($base),
      'a config field with nothing configured' => $this->configFieldItems(NULL),
    ];
    foreach ($routes as $label => $items) {
      $this->assertSame(
        'Body',
        $this->extension->getFieldLabel($this->fieldBuild($items, ['#title' => 'Body'])),
        'A field with ' . $label . ' falls back to the element title.'
      );
    }

    // A config field asked for the default label is the fourth route.
    $this->assertSame(
      'Body',
      $this->extension->getFieldLabel($this->fieldBuild(
        $this->configFieldItems('Client contact'),
        ['#title' => 'Body', '#field_label_default' => TRUE]
      )),
      'A config field asked for the default label falls back to the title too.'
    );

    // The fallback is a ??, so only a missing or NULL title falls past it.
    $this->assertSame(
      '',
      $this->extension->getFieldLabel(['#theme' => 'field', '#title' => '']),
      'An empty title is returned as the empty string, not as NULL.'
    );
    $this->assertSame(
      '0',
      $this->extension->getFieldLabel(['#theme' => 'field', '#title' => '0']),
      'A title of "0" is returned as "0".'
    );
    $this->assertNull(
      $this->extension->getFieldLabel(['#theme' => 'field', '#title' => NULL]),
      'A NULL title answers NULL.'
    );
    $this->assertNull(
      $this->extension->getFieldLabel(['#theme' => 'field']),
      'A field with no title at all answers NULL, three sources later.'
    );

    // The title is returned as the object it is, not cast to a string.
    $title = new TranslatableMarkup('Body', [], [], $this->getStringTranslationStub());

    $this->assertSame(
      $title,
      $this->extension->getFieldLabel(['#theme' => 'field', '#title' => $title]),
      'A TranslatableMarkup title comes back as the same object, uncast.'
    );
  }

  /**
   * It returns one render array per field item, and NULL when there are none.
   *
   * `neo_value` is the field's children and nothing else: every `#`-prefixed
   * property is dropped, and each remaining child is handed back whole, under
   * the delta it already had. What comes back is a *list of render arrays*,
   * not markup and not a single element — a template has to loop it, even for
   * a field it knows holds one item, which is where this filter differs in
   * shape from `neo_raw` two criteria below.
   *
   * The walk does not sort. `Element::children()` is called without its sort
   * flag, so `#weight` on a child is carried along inside the child's own
   * array and changes nothing about the order, which follows the render
   * array's key order instead. A template relying on `neo_value` to apply
   * weights is relying on whoever built the array having ordered it already.
   *
   * A field with no children answers `NULL` rather than an empty list, which
   * is the same answer the gate gives for something that is not a field at
   * all, and the same one an unrenderable field gives. `#items` being present
   * and populated does not change that: this filter never looks at it.
   */
  public function testReturnsOneRenderArrayPerFieldItemAndNullWhenThereAreNoChildren(): void {
    $build = [
      '#theme' => 'field',
      '#title' => 'Body',
      '#field_name' => 'field_body',
      '#items' => $this->configFieldItems('Client contact'),
      0 => ['#markup' => 'first', '#weight' => 10],
      1 => ['#markup' => 'second', '#weight' => 0],
      2 => ['#markup' => 'third', '#weight' => 5],
    ];

    $this->assertSame(
      [
        0 => ['#markup' => 'first', '#weight' => 10],
        1 => ['#markup' => 'second', '#weight' => 0],
        2 => ['#markup' => 'third', '#weight' => 5],
      ],
      $this->extension->getFieldValue($build),
      'Every child comes back whole under its own delta; properties are dropped.'
    );

    $this->assertSame(
      [0, 1, 2],
      array_keys($this->extension->getFieldValue($build)),
      'The order follows the array, not #weight: the walk does not sort.'
    );

    // Deltas are whatever keys the array carried, in the order it carried
    // them. Nothing renumbers them and nothing reorders them.
    $out_of_order = [
      '#theme' => 'field',
      2 => ['#markup' => 'third'],
      0 => ['#markup' => 'first'],
      'extra' => ['#markup' => 'named'],
    ];

    $this->assertSame(
      [2, 0, 'extra'],
      array_keys($this->extension->getFieldValue($out_of_order)),
      'Keys are preserved as they stand, numeric or not, unsorted and unrenumbered.'
    );

    // A single item is still a list of one: this filter never collapses.
    $this->assertSame(
      [0 => ['#markup' => 'only']],
      $this->extension->getFieldValue(['#theme' => 'field', 0 => ['#markup' => 'only']]),
      'A one-item field returns a one-entry list, not the item.'
    );

    // No children is NULL, not an empty list — even with #items populated.
    $childless = [
      'a field with only properties' => [
        '#theme' => 'field',
        '#title' => 'Body',
        '#field_name' => 'field_body',
        '#items' => $this->configFieldItems('Client contact'),
      ],
      'a field with nothing at all' => ['#theme' => 'field'],
    ];
    foreach ($childless as $label => $value) {
      $this->assertNull(
        $this->extension->getFieldValue($value),
        'neo_value answers NULL for ' . $label . ', not an empty list.'
      );
    }
  }

  /**
   * It returns every raw item value, and only the named key when one is given.
   *
   * `neo_raw` is the one filter of the three that reaches past the render
   * array into `#items` itself, so it answers with stored values rather than
   * anything themed: a text field gives back `value` *and* `format`, and a
   * link field gives back `uri`, `title` and `options`, whatever the display
   * would have shown. Naming a key narrows each item to that one value.
   *
   * A named key that an item does not carry is `NULL` at that delta rather
   * than a missing entry, so the result always has one entry per item and the
   * deltas always line up with the field's own.
   *
   * The key argument is tested for *truthiness*, not for being given, so a key
   * of `'0'` is read as no key at all and the whole item comes back. That is a
   * quirk of the guard rather than a decision, and it is pinned here because a
   * field whose property is literally named `0` is the one case where naming a
   * key silently does nothing.
   *
   * Two separate `NULL`s guard the item list — one for `#items` being absent
   * or not typed data, one for it holding no values — and neither is
   * distinguishable from the gate's own `NULL` or from the collapse below.
   */
  public function testReturnsEveryRawItemValueAndOnlyTheNamedKeyWhenOneIsGiven(): void {
    $build = $this->fieldBuild($this->rawItems([
      ['value' => 'First paragraph', 'format' => 'basic_html'],
      ['value' => 'Second paragraph', 'format' => 'full_html'],
    ]));

    $this->assertSame(
      [
        0 => ['value' => 'First paragraph', 'format' => 'basic_html'],
        1 => ['value' => 'Second paragraph', 'format' => 'full_html'],
      ],
      $this->extension->getRawValues($build),
      'With no key, every stored property of every item comes back.'
    );

    $this->assertSame(
      [0 => 'First paragraph', 1 => 'Second paragraph'],
      $this->extension->getRawValues($build, 'value'),
      'A named key narrows each item to that one value.'
    );
    $this->assertSame(
      [0 => 'basic_html', 1 => 'full_html'],
      $this->extension->getRawValues($build, 'format'),
      'Any stored property can be named, not only the main one.'
    );

    // A key an item does not carry is NULL at that delta, not a gap.
    $mixed = $this->fieldBuild($this->rawItems([
      ['uri' => 'https://example.com', 'title' => 'Example'],
      ['uri' => 'internal:/about'],
    ]));

    $this->assertSame(
      [0 => 'Example', 1 => NULL],
      $this->extension->getRawValues($mixed, 'title'),
      'A missing key is NULL at its delta: the deltas still line up.'
    );
    $this->assertSame(
      [0 => NULL, 1 => NULL],
      $this->extension->getRawValues($mixed, 'nothing_named_this'),
      'A key no item carries gives one NULL per item rather than NULL overall.'
    );

    // The key is tested for truthiness, so "0" is read as no key at all.
    $numeric = $this->fieldBuild($this->rawItems([
      [0 => 'zero key', 'value' => 'a'],
      [0 => 'zero key too', 'value' => 'b'],
    ]));

    $this->assertSame(
      [
        0 => [0 => 'zero key', 'value' => 'a'],
        1 => [0 => 'zero key too', 'value' => 'b'],
      ],
      $this->extension->getRawValues($numeric, '0'),
      'A key of "0" is falsy, so it is read as no key and the item comes back.'
    );

    // Two more NULLs, neither of them distinguishable from the gate's.
    $unreadable = [
      'a field with no #items' => ['#theme' => 'field', '#title' => 'Body'],
      'a field whose #items is an array' => [
        '#theme' => 'field',
        '#items' => [['value' => 'a']],
      ],
      'a field whose #items is not typed data' => [
        '#theme' => 'field',
        '#items' => new Attribute(['class' => ['kept']]),
      ],
      'a field whose item list holds no values' => $this->fieldBuild($this->rawItems([])),
    ];
    foreach ($unreadable as $label => $value) {
      $this->assertNull(
        $this->extension->getRawValues($value),
        'neo_raw answers NULL for ' . $label . '.'
      );
      $this->assertNull(
        $this->extension->getRawValues($value, 'value'),
        'neo_raw with a named key answers NULL for ' . $label . ' too.'
      );
    }
  }

  /**
   * Builds an item list reporting the given stored values.
   *
   * @param array $values
   *   What the list reports from getValue(): one array per item.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The item list double.
   */
  private function rawItems(array $values): FieldItemListInterface {
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getValue')->willReturn($values);
    return $items;
  }

  /**
   * It returns a single raw value rather than a list when there is one item.
   *
   * The last line of `neo_raw` is `count($raw_values) > 1 ? $raw_values :
   * reset($raw_values)`, so the filter's *return shape* depends on how many
   * items an editor happened to save. One item and the value itself comes
   * back; two and it is a list keyed by delta. Nothing in the call says which
   * to expect, and a template written against the list form — `{% for v in
   * field|neo_raw %}`, or `|neo_raw|first` — reads the wrong thing on a
   * single-value field without raising anything. This is current behaviour and
   * this criterion exists to make the later change to it a visible diff.
   *
   * The collapse is `reset()`, not `[0]`, so it answers the first value in the
   * list whatever key it is under.
   *
   * With a key named, the collapse hands back a bare scalar, and if that one
   * item does not carry the key the answer is `NULL` — the fourth distinct
   * route to `NULL` from this one filter, and indistinguishable from the other
   * three at the call site.
   */
  public function testReturnsSingleRawValueRatherThanListWhenTheFieldHoldsOneItem(): void {
    $one = $this->fieldBuild($this->rawItems([
      ['value' => 'Only paragraph', 'format' => 'basic_html'],
    ]));

    $this->assertSame(
      ['value' => 'Only paragraph', 'format' => 'basic_html'],
      $this->extension->getRawValues($one),
      'One item collapses to the item itself, not to a one-entry list.'
    );
    $this->assertSame(
      'Only paragraph',
      $this->extension->getRawValues($one, 'value'),
      'One item with a named key collapses to a bare value, not to a list.'
    );

    // Two is where the shape changes, and it changes without warning.
    $two = $this->fieldBuild($this->rawItems([
      ['value' => 'First', 'format' => 'basic_html'],
      ['value' => 'Second', 'format' => 'basic_html'],
    ]));

    $this->assertSame(
      'Only paragraph',
      $this->extension->getRawValues($one, 'value'),
      'A one-item field answers a string.'
    );
    $this->assertSame(
      [0 => 'First', 1 => 'Second'],
      $this->extension->getRawValues($two, 'value'),
      'A two-item field answers a list, from the same filter and the same key.'
    );

    // The collapse is reset(), so the key the single value sits under is
    // irrelevant: it is the first value, not the value at delta 0.
    $offset = $this->fieldBuild($this->rawItems([
      3 => ['value' => 'Third delta only'],
    ]));

    $this->assertSame(
      'Third delta only',
      $this->extension->getRawValues($offset, 'value'),
      'The collapse takes the first value, whatever delta it sits under.'
    );

    // A single item missing the named key is the fourth route to NULL.
    $missing = $this->fieldBuild($this->rawItems([
      ['uri' => 'internal:/about'],
    ]));

    $this->assertNull(
      $this->extension->getRawValues($missing, 'title'),
      'One item without the named key answers NULL, like every other reading.'
    );
    $this->assertSame(
      $this->extension->getRawValues('not a field at all', 'title'),
      $this->extension->getRawValues($missing, 'title'),
      'That NULL is the gate\'s NULL: the two readings are indistinguishable.'
    );
  }

  /**
   * Builds a field render array around an optional item list.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface|null $items
   *   The item list to hang on `#items`, or NULL to omit the key.
   * @param array $extra
   *   Further keys to merge into the element.
   *
   * @return array
   *   A render array the field-shape gate accepts.
   */
  private function fieldBuild(?FieldItemListInterface $items = NULL, array $extra = []): array {
    $build = ['#theme' => 'field'] + $extra;
    if ($items !== NULL) {
      $build['#items'] = $items;
    }
    return $build;
  }

  /**
   * Builds an item list whose definition is a base field with settings.
   *
   * @param array $settings
   *   What the base field definition reports from getSettings().
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The item list double.
   */
  private function baseFieldItems(array $settings): FieldItemListInterface {
    $definition = $this->createMock(BaseFieldDefinition::class);
    $definition->method('getSettings')->willReturn($settings);
    return $this->itemsWithDefinition($definition);
  }

  /**
   * Builds an item list reporting the given field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The definition the list reports.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The item list double.
   */
  private function itemsWithDefinition(FieldDefinitionInterface $definition): FieldItemListInterface {
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getFieldDefinition')->willReturn($definition);
    return $items;
  }

  /**
   * Values the field-shape gate rejects, keyed by a readable label.
   *
   * @return array<string, mixed>
   *   Values that are not a field render array.
   */
  private static function notFieldRenderArrays(): array {
    return [
      'NULL' => NULL,
      'an empty array' => [],
      'a string' => 'not a render array',
      'the string "field"' => 'field',
      'an integer' => 42,
      'TRUE' => TRUE,
      'an object' => new Attribute(['class' => ['kept']]),
      'an element with no #theme' => [
        '#markup' => 'a',
        '#title' => 'Not a field label',
      ],
      'an element themed as something else' => [
        '#theme' => 'item_list',
        '#title' => 'Not a field label',
      ],
      'an element themed with a suggestion list' => [
        '#theme' => ['field__node__body', 'field'],
        '#title' => 'Not a field label',
      ],
    ];
  }

  /**
   * It answers NULL from all four filters for every reason, in both states.
   *
   * The characterisation criterion above drives the same paths with the gate
   * off, which is the state every deployed site runs in. This one
   * repeats every reason a filter behind the gate answers `NULL` with the
   * **debug gate** on as well, because that is the state a notice exists in
   * and the state in which a diagnostic could accidentally become a behaviour
   * change.
   *
   * The return value is the whole promise of this ticket: `NULL` stays `NULL`
   * for every reason, on every environment, in both gate states. Two `NULL`s
   * stop *reading* the same; neither of them stops being `NULL`. A template
   * that rendered nothing keeps rendering nothing, and a template that guarded
   * on `{% if value %}` takes the same branch it always did.
   *
   * Both the gate's own failure and every empty shape past it are driven, so
   * an implementation that repaired a guard rather than explaining it fails
   * here before any criterion about a notice is reached.
   */
  public function testAnswersNullFromAllFourFiltersForEveryReasonInBothGateStates(): void {
    $this->installNoticeLogger();

    foreach (['off' => FALSE, 'on' => TRUE] as $state => $gate) {
      $extension = new TwigExtension(['debug' => $gate]);

      foreach (self::GATE_FILTERS as $name => $method) {
        foreach (self::notFieldRenderArrays() as $label => $value) {
          $this->assertNull(
            $this->callFilter($extension, $method, $value),
            'With the gate ' . $state . ', ' . $name . ' answers NULL for ' . $label . '.'
          );
        }
      }

      foreach ($this->emptyShapeReasons() as $label => [$name, $build]) {
        $this->assertNull(
          $this->callFilter($extension, self::GATE_FILTERS[$name], $build),
          'With the gate ' . $state . ', ' . $name . ' answers NULL for ' . $label . '.'
        );
      }
    }
  }

  /**
   * It notices "not a field render array" from each filter, naming that one.
   *
   * The gate is the reason all four filters share, and it is the one a
   * template author is least equipped to guess at: a whole entity piped in
   * instead of one of its fields, a bare list, a string, an element themed as
   * something else. Every one of them is a perfectly ordinary thing to be
   * holding in a template, and every one of them has answered the same `NULL`
   * as an empty field until now.
   *
   * What separates the four notices is the **registered name** — `neo_label`,
   * `neo_value`, `neo_raw`, `neo_target_entity` — and never the PHP method
   * behind it, which is not something a template author has ever seen. That is
   * asserted both ways round, because a message built from `__FUNCTION__`
   * would read perfectly well and still name the wrong thing.
   *
   * The notice is **log-only**. All four filters answer `NULL`, so there is no
   * render array to attach anything to, which is asserted separately and in
   * full below.
   */
  public function testNoticesNotFieldShapedFromEachOfTheFourFiltersNamingThatFilter(): void {
    foreach (self::GATE_FILTERS as $name => $method) {
      $this->installNoticeLogger();
      $extension = new TwigExtension(['debug' => TRUE]);

      foreach (self::notFieldRenderArrays() as $label => $value) {
        $before = count($this->logged);
        $answer = $this->callFilter($extension, $method, $value);

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
        $this->assertNull(
          $answer,
          $name . ' still answers NULL for ' . $label . '.'
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
   * It notices a childless field render array from neo_value, distinctly.
   *
   * This is the ambiguity the glossary records against the **field-shape
   * gate**, at its sharpest: a real field went in, the gate passed, and the
   * answer is the same `NULL` a string would have produced. "That is not a
   * field" and "that field is empty" are two different mistakes with two
   * different fixes, and until now they read the same.
   *
   * So the two notices are asserted **against each other** rather than
   * separately: the expectation `neo_value` reports for a childless field is
   * not the one it reports for something that never was a field. Both name
   * `neo_value`, because the filter is the same one; only what it expected
   * moves.
   *
   * A field render array that carries a title and properties but no children
   * is the shape an unfilled field arrives in, so that is the one driven.
   */
  public function testNoticesFieldRenderArrayWithNoChildrenFromNeoValueDistinctlyFromTheGate(): void {
    $this->installNoticeLogger();
    $extension = new TwigExtension(['debug' => TRUE]);

    $this->assertNull(
      $extension->getFieldValue(['#theme' => 'field', '#title' => 'Author', '#label_display' => 'above']),
      'A field render array with no children still answers NULL.'
    );
    $this->assertCount(1, $this->logged, 'neo_value says something about a field holding nothing.');

    $empty_field = $this->logged[0];

    $this->assertStringContainsString('neo_value', $empty_field, 'The line names neo_value.');
    $this->assertStringNotContainsString(
      'getFieldValue',
      $empty_field,
      'And never the PHP method behind it.'
    );

    $extension->getFieldValue('not a field at all');

    $this->assertCount(2, $this->logged, "The gate's own failure is a second line.");
    $this->assertNotSame(
      $this->expectationIn($empty_field),
      $this->expectationIn($this->logged[1]),
      'A field with nothing in it and a value that is not a field expect different things.'
    );
    $this->assertStringContainsString(
      'neo_value',
      $this->logged[1],
      'Both lines name the same filter: only what it expected moves.'
    );
  }

  /**
   * It notices untyped items and empty items from neo_raw, as two answers.
   *
   * `neo_raw` reaches past the gate into the item list itself, and it can fail
   * there twice for reasons that have nothing to do with each other. A render
   * array with no `#items`, or an `#items` that is not typed data, is a build
   * the filter cannot read at all — usually an array that was themed as a
   * field by something other than the field formatter. An `#items` that *is*
   * typed data and holds no values is the ordinary unfilled field.
   *
   * The fix for the first is to pipe a different build; the fix for the second
   * is to fill the field in, or to guard the template. So they are asserted as
   * two distinct expectations, and both distinct from the gate's own.
   */
  public function testNoticesItemsThatAreNotTypedDataAndItemsThatAreEmptyFromNeoRaw(): void {
    $this->installNoticeLogger();
    $extension = new TwigExtension(['debug' => TRUE]);

    $untyped = [
      'no #items at all' => ['#theme' => 'field'],
      '#items holding a string' => ['#theme' => 'field', '#items' => 'not typed data'],
      '#items holding a plain array' => ['#theme' => 'field', '#items' => [['value' => 'a']]],
    ];

    $expectations = [];
    foreach ($untyped as $label => $build) {
      $before = count($this->logged);

      $this->assertNull($extension->getRawValues($build), 'neo_raw answers NULL for ' . $label . '.');
      $this->assertCount(
        $before + 1,
        $this->logged,
        'neo_raw says something about ' . $label . '.'
      );
      $this->assertStringContainsString(
        'neo_raw',
        $this->logged[$before],
        'The line for ' . $label . ' names neo_raw.'
      );
      $this->assertStringNotContainsString(
        'getRawValues',
        $this->logged[$before],
        'The line for ' . $label . ' never names the PHP method behind it.'
      );
      $expectations[$label] = $this->expectationIn($this->logged[$before]);
    }

    $this->assertCount(
      1,
      array_unique($expectations),
      'Every way of not being typed data is one expectation, said one way.'
    );

    $before = count($this->logged);
    $empty = $this->fieldBuild($this->rawItems([]));

    $this->assertNull($extension->getRawValues($empty), 'neo_raw answers NULL for items holding no values.');
    $this->assertCount(
      $before + 1,
      $this->logged,
      'neo_raw says something about items holding no values.'
    );
    $this->assertStringContainsString(
      'neo_raw',
      $this->logged[$before],
      'That line names neo_raw too.'
    );

    $extension->getRawValues('not a field at all');

    $three = [
      'items that are not typed data' => reset($expectations),
      'items that hold no values' => $this->expectationIn($this->logged[$before]),
      "a value that is not a field's render array" => $this->expectationIn(end($this->logged)),
    ];

    $this->assertSame(
      $three,
      array_unique($three),
      'The three reasons neo_raw answers NULL expect three different things.'
    );
  }

  /**
   * It attaches nothing inline from any of the four filters.
   *
   * Every one of these filters answers `NULL`, so there is nothing to attach
   * an **inline notice** to and nothing that could carry one — which is the
   * rule the whole plan rests on, stated from the other end: an empty value
   * never gains a surface, because making one truthy would change what a dev
   * template renders.
   *
   * Two things are asserted for every reason, with the gate on. The answer is
   * `NULL` and not a render array wearing a notice, and the array the filter
   * was handed comes back out of the call exactly as it went in — no
   * `#suffix`, no key added, no key reordered — because a filter that took its
   * argument by reference and decorated it would leave the notice on the
   * caller's own build.
   */
  public function testAttachesNothingInlineFromAnyOfTheFourFilters(): void {
    $this->installNoticeLogger();
    $extension = new TwigExtension(['debug' => TRUE]);

    foreach (self::GATE_FILTERS as $name => $method) {
      foreach (self::notFieldRenderArrays() as $label => $value) {
        $handed = $value;
        $answer = $this->callFilter($extension, $method, $value);

        $this->assertNull($answer, $name . ' answers NULL for ' . $label . ', never an element.');
        $this->assertSame(
          '',
          self::attachedTextOn($answer),
          $name . ' attaches nothing to what it answers for ' . $label . '.'
        );
        $this->assertEquals(
          $handed,
          $value,
          $name . ' leaves ' . $label . ' exactly as it was handed it.'
        );
      }
    }

    foreach ($this->emptyShapeReasons() as $label => [$name, $build]) {
      $handed = $build;
      $answer = $this->callFilter($extension, self::GATE_FILTERS[$name], $build);

      $this->assertNull($answer, $name . ' answers NULL for ' . $label . ', never an element.');
      $this->assertSame(
        '',
        self::attachedTextOn($answer),
        $name . ' attaches nothing to what it answers for ' . $label . '.'
      );
      $this->assertSame(
        $handed,
        $build,
        $name . ' leaves ' . $label . ' exactly as it was handed it.'
      );
    }

    $this->assertNotEmpty(
      $this->logged,
      'The log is where all of that went instead, so this is not passing on silence.'
    );
  }

  /**
   * Every reason a filter behind the gate answers NULL past the gate itself.
   *
   * `neo_label` is not among them: past the gate its only answer is `#title`
   * or the `NULL` in place of one, which is a value rather than a job left
   * undone and carries no criterion in this ticket.
   *
   * @return array<string, array>
   *   The registered name of the filter the reason belongs to and the value
   *   that produces it, keyed by how a failure message should name the reason.
   */
  private function emptyShapeReasons(): array {
    return [
      'a field render array with no children' => [
        'neo_value',
        ['#theme' => 'field', '#title' => 'Author', '#label_display' => 'above'],
      ],
      'a field render array with no #items at all' => [
        'neo_raw',
        ['#theme' => 'field'],
      ],
      'a field render array whose #items is a string' => [
        'neo_raw',
        ['#theme' => 'field', '#items' => 'not typed data'],
      ],
      'a field render array whose #items is a plain array' => [
        'neo_raw',
        ['#theme' => 'field', '#items' => [['value' => 'a']]],
      ],
      'a field item list holding no values' => [
        'neo_raw',
        $this->fieldBuild($this->rawItems([])),
      ],
    ];
  }

  /**
   * Calls one of the four filters on a given extension.
   *
   * Each filter takes its own arguments past the build, so this is the single
   * place that knows them; every criterion drives them through it, so what
   * varies between cases is only the value handed in.
   *
   * @param \Drupal\neo_twig\TwigExtension $extension
   *   The extension to call, in whichever gate state the criterion needs.
   * @param string $method
   *   The method to call, as named in self::GATE_FILTERS.
   * @param mixed $build
   *   The value to hand it.
   *
   * @return mixed
   *   Whatever the filter answered.
   */
  private function callFilter(TwigExtension $extension, string $method, mixed $build): mixed {
    return match ($method) {
      'getFieldLabel' => $extension->getFieldLabel($build),
      'getFieldValue' => $extension->getFieldValue($build),
      'getRawValues' => $extension->getRawValues($build),
      'getTargetEntity' => $extension->getTargetEntity($build),
    };
  }

  /**
   * The "expected …" half of a notice line, without the value it describes.
   *
   * Criteria about two reasons reading differently compare this rather than
   * the whole line, because the description of what arrived differs between
   * two reasons anyway — so comparing whole lines would pass even where both
   * reasons said they expected the same thing.
   *
   * @param string $line
   *   A notice line as a reader would see it.
   *
   * @return string
   *   What the notice said it expected.
   */
  private function expectationIn(string $line): string {
    $found = preg_match('/expected (.*), received /', $line, $matches);
    $this->assertSame(1, $found, 'The notice says what it expected: ' . $line);
    return $matches[1];
  }

  /**
   * Installs a container carrying a recording logger factory double.
   *
   * The notice seam resolves its logger lazily from the container, the way
   * `neo_oembed` resolves its services, so a criterion about what reaches the
   * **notice log** needs one. Nothing but the factory is in it: a filter
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
   * What a value carries after its own output, as a reader would read it.
   *
   * Tags stripped and entities decoded, because every criterion here is about
   * the words a notice carries and never about the box they arrive in — the
   * same reasoning `neo_inspect`'s own coverage took about its styling. A
   * value that is not an array cannot carry one at all, which is the answer
   * for all four of these filters and is why it is the empty string here.
   *
   * @param mixed $answer
   *   Whatever a filter answered.
   *
   * @return string
   *   The attachment's text, or the empty string when there is none.
   */
  private static function attachedTextOn($answer): string {
    $suffix = is_array($answer) ? (string) ($answer['#suffix'] ?? '') : '';
    return trim(html_entity_decode(strip_tags($suffix), ENT_QUOTES));
  }

}
