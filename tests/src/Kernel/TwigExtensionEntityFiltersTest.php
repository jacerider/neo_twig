<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the two filters that reach past the render array into real content.
 *
 * `neo_target_entity` and `neo_field` are the only **Twig helpers** that do not
 * stop at the array they are handed: one asks a parent entity for the entities
 * its reference field points at, the other asks an entity found inside a build
 * to render one of its fields. Everything else in the module is
 * array-in/array-out, which is why the rest of the suite is unit-tested and
 * these two are here.
 *
 * **Why this needs a container.** Neither filter can be driven by a test
 * double. `neo_target_entity` iterates `$parent->get($field_name)` and reads
 * `$item->entity` off each item, so it needs a field item list a reference
 * field really produced and target entities the storage can really load;
 * `neo_field` calls `FieldItemListInterface::view()`, which resolves the entity
 * type manager, the view builder, the entity display repository and a
 * formatter plugin out of the container. Core's `entity_test` supplies the
 * entity type and this test creates the fields and view displays it needs,
 * exactly as the spec's decision 6 requires — the module ships no fixture
 * extension of its own, because it declares no dependencies and a fixture would
 * be the first thing in `tests/` to imply one.
 *
 * **The shape difference worth stating out loud.** `neo_target_entity` ends on
 * `count($entities) > 1 ? $entities : reset($entities)`, the same collapse
 * `neo_raw` performs, so a template that iterates the result works on a
 * two-value field and silently walks the *properties* of a single entity on a
 * one-value field. That is current behaviour and it is pinned as such.
 *
 * **One finding recorded, not fixed.** That same `reset()` is reached with an
 * empty array when the reference field holds nothing, so an empty field answers
 * `FALSE` rather than the `NULL` the method's own `@return` promises — a fourth
 * reading of "nothing happened" on top of the three the criteria below pin. It
 * has no criterion here because the plan characterises rather than repairs; it
 * is written up in `docs/improvements/neo_twig.md` instead.
 *
 * @see \Drupal\Tests\neo_twig\Unit\TwigExtensionFieldFiltersTest
 */
#[Group('neo_twig')]
final class TwigExtensionEntityFiltersTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'entity_test',
  ];

  /**
   * The extension under test.
   */
  private TwigExtension $extension;

  /**
   * The two saved entities the reference fields below point at.
   *
   * @var \Drupal\entity_test\Entity\EntityTest[]
   */
  private array $targets = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    // Brings in `core.entity_view_mode.entity_test.test`, the second view mode
    // the `neo_field` criteria render against.
    $this->installConfig(['entity_test']);

    $this->createReferenceField('field_single_ref', 1);
    $this->createReferenceField('field_multi_ref', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->createTextField();

    foreach (['Target one', 'Target two'] as $name) {
      $target = EntityTest::create(['name' => $name]);
      $target->save();
      $this->targets[] = $target;
    }

    $this->extension = new TwigExtension();
  }

  /**
   * Tests that it returns the entity itself for a one-value reference field.
   *
   * The collapse named in the class docblock: one target comes back as the
   * entity, not as a one-item list, so `|neo_target_entity` returns two
   * different shapes for the same field depending on how many values an editor
   * happened to save.
   */
  public function testReturnsSingleEntityForOneValueReferenceField(): void {
    $parent = EntityTest::create([
      'name' => 'Parent',
      'field_single_ref' => $this->targets[0]->id(),
    ]);
    $parent->save();

    $result = $this->extension->getTargetEntity([
      '#theme' => 'field',
      '#field_name' => 'field_single_ref',
      '#object' => $parent,
    ]);

    $this->assertIsNotArray(
      $result,
      'A single target collapses to the entity rather than a one-item list.'
    );
    $this->assertInstanceOf(EntityTest::class, $result);
    $this->assertSame(
      $this->targets[0]->id(),
      $result->id(),
      'The entity returned is the one the reference field points at.'
    );
  }

  /**
   * Tests that it returns every entity a multi-value field targets.
   *
   * Past the collapse this is the ordinary answer: a list, in field order, of
   * the entity behind each item that has one.
   */
  public function testReturnsEveryEntityMultiValueReferenceFieldTargets(): void {
    $parent = EntityTest::create([
      'name' => 'Parent',
      'field_multi_ref' => [
        ['target_id' => $this->targets[1]->id()],
        ['target_id' => $this->targets[0]->id()],
      ],
    ]);
    $parent->save();

    $result = $this->extension->getTargetEntity([
      '#theme' => 'field',
      '#field_name' => 'field_multi_ref',
      '#object' => $parent,
    ]);

    $this->assertIsArray($result, 'Two targets come back as a list.');
    $this->assertCount(2, $result);
    $this->assertSame(
      [$this->targets[1]->id(), $this->targets[0]->id()],
      array_map(static fn ($entity) => $entity->id(), $result),
      'Every target is returned, in the order the field holds them.'
    );
  }

  /**
   * Tests that it reads the parent from either key it looks for.
   *
   * Different field types put the parent object under different keys, so the
   * filter tries `#object` first and `#field_collection_item` second. Both
   * reach the same answer, and when a build happens to carry both, the first
   * option in that list is the one that is read.
   */
  public function testReadsParentObjectFromEitherKey(): void {
    $parent = EntityTest::create([
      'name' => 'Parent',
      'field_single_ref' => $this->targets[0]->id(),
    ]);
    $parent->save();
    $other = EntityTest::create([
      'name' => 'Other parent',
      'field_single_ref' => $this->targets[1]->id(),
    ]);
    $other->save();

    $build = [
      '#theme' => 'field',
      '#field_name' => 'field_single_ref',
    ];

    $from_object = $this->extension->getTargetEntity($build + ['#object' => $parent]);
    $from_collection = $this->extension->getTargetEntity($build + ['#field_collection_item' => $parent]);
    $from_both = $this->extension->getTargetEntity($build + [
      '#field_collection_item' => $other,
      '#object' => $parent,
    ]);

    $this->assertSame(
      $this->targets[0]->id(),
      $from_object?->id(),
      'The ordinary key is read.'
    );
    $this->assertSame(
      $this->targets[0]->id(),
      $from_collection?->id(),
      'The field-collection key is read.'
    );
    $this->assertSame(
      $this->targets[0]->id(),
      $from_both?->id(),
      'A build carrying both keys is read from the ordinary one.'
    );
  }

  /**
   * Tests that it answers NULL without a field name or a parent object.
   *
   * Past the **field-shape gate** there are two more ways to get nothing, and
   * neither says which one fired: a field render array with no `#field_name`,
   * and one whose parent object sits under neither of the two keys the filter
   * knows. A template author gets `NULL` for both, and for the gate itself.
   */
  public function testAnswersNullWithoutFieldNameOrParentObject(): void {
    $parent = EntityTest::create([
      'name' => 'Parent',
      'field_single_ref' => $this->targets[0]->id(),
    ]);
    $parent->save();

    $this->assertNull(
      $this->extension->getTargetEntity([
        '#theme' => 'field',
        '#object' => $parent,
      ]),
      'A field render array naming no field answers NULL.'
    );
    $this->assertNull(
      $this->extension->getTargetEntity([
        '#theme' => 'field',
        '#field_name' => 'field_single_ref',
      ]),
      'A field render array carrying no parent object answers NULL.'
    );
    $this->assertNull(
      $this->extension->getTargetEntity([
        '#theme' => 'field',
        '#field_name' => 'field_single_ref',
        '#entity' => $parent,
      ]),
      'A parent under some third key is not found, and is not reported either.'
    );
  }

  /**
   * Tests that it renders a named field in the build's view mode.
   *
   * `neo_field` takes no entity argument: it sweeps the build for the first
   * value that is a content entity and asks that one. The view mode is read
   * off the build too, so the same filter against the same entity renders
   * differently depending on which build it is piped, which is the whole point
   * of it — a field pulled out of an entity-reference build is rendered the
   * way that build's display says to.
   */
  public function testRendersNamedFieldFromEntityInBuildViewMode(): void {
    $entity = EntityTest::create([
      'name' => 'Parent',
      'field_text' => 'Rendered value',
    ]);
    $entity->save();

    $default = TwigExtension::renderField([
      '#view_mode' => 'default',
      '#object' => $entity,
    ], 'field_text');

    $this->assertIsArray($default);
    $this->assertSame('field', $default['#theme'], 'It is a field render array.');
    $this->assertSame('field_text', $default['#field_name'], 'It is the named field.');
    $this->assertSame('default', $default['#view_mode'], "It carries the build's view mode.");
    $this->assertSame('hidden', $default['#label_display']);
    $this->assertStringContainsString(
      'Rendered value',
      (string) $this->container->get('renderer')->renderInIsolation($default),
      "The field renders the entity's value."
    );

    // The `test` display shows the same field with its label above. The only
    // thing that changes between the two calls is the build's `#view_mode`, so
    // a difference in the output can come from nothing else.
    $teaser = TwigExtension::renderField([
      '#view_mode' => 'test',
      '#object' => $entity,
    ], 'field_text');

    $this->assertSame('test', $teaser['#view_mode'], "It carries the build's view mode.");
    $this->assertSame(
      'above',
      $teaser['#label_display'],
      "The display for the build's view mode is the one that renders the field."
    );
    $this->assertStringContainsString(
      'Rendered value',
      (string) $this->container->get('renderer')->renderInIsolation($teaser),
      "The field renders the entity's value."
    );
  }

  /**
   * Tests that it answers NULL for each of its three misses.
   *
   * `neo_field` is the quietest helper in the module: three different mistakes
   * — a build with no view mode, a build with no content entity anywhere in
   * it, and a field the entity does not have — all answer `NULL`, and none of
   * them says which. A template author whose `|neo_field('field_x')` prints
   * nothing has no route to an answer except opening the class.
   */
  public function testAnswersNullWithoutViewModeContentEntityOrField(): void {
    $entity = EntityTest::create([
      'name' => 'Parent',
      'field_text' => 'Rendered value',
    ]);
    $entity->save();

    $this->assertNull(
      TwigExtension::renderField(['#object' => $entity], 'field_text'),
      'A build carrying no view mode answers NULL.'
    );
    $this->assertNull(
      TwigExtension::renderField([
        '#view_mode' => '',
        '#object' => $entity,
      ], 'field_text'),
      'An empty view mode is the same miss as an absent one.'
    );
    $this->assertNull(
      TwigExtension::renderField([
        '#view_mode' => 'default',
        '#object' => 'not an entity',
      ], 'field_text'),
      'A build with no content entity among its values answers NULL.'
    );
    $this->assertNull(
      TwigExtension::renderField([
        '#view_mode' => 'default',
        '#object' => $entity,
      ], 'field_nope'),
      'A field the entity does not have answers NULL.'
    );
  }

  /**
   * Creates the renderable field, and a view display for each view mode.
   */
  private function createTextField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Text',
    ])->save();

    // A configurable field is hidden until a display says otherwise, so both
    // view modes need one. They differ in a single setting — where the label
    // goes — which is what makes "in the build's view mode" observable.
    foreach (['default' => 'hidden', 'test' => 'above'] as $mode => $label) {
      EntityViewDisplay::create([
        'targetEntityType' => 'entity_test',
        'bundle' => 'entity_test',
        'mode' => $mode,
        'status' => TRUE,
      ])->setComponent('field_text', [
        'type' => 'string',
        'label' => $label,
        'weight' => 0,
      ])->save();
    }
  }

  /**
   * Creates an entity_test-to-entity_test reference field.
   *
   * @param string $field_name
   *   The field name.
   * @param int $cardinality
   *   The storage cardinality.
   */
  private function createReferenceField(string $field_name, int $cardinality): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => $cardinality,
      'settings' => ['target_type' => 'entity_test'],
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => $field_name,
    ])->save();
  }

}
