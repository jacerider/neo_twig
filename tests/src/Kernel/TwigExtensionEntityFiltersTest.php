<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\ErrorHandler\BufferingLogger;

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
 * **The three reasons this filter gives up now say which one fired**, and the
 * three criteria after the characterisation ones are that. They live here,
 * beside the `NULL`s they resolve, so the two fail together: a notice that
 * changed what the filter answers breaks a characterisation criterion in the
 * same run. None of those is edited. The filter's fourth reason — the value it
 * was handed was never a field's render array — stops before any entity is
 * touched and is pinned in the unit class with the other three filters behind
 * the same gate.
 *
 * **`neo_field`'s four reasons say which one fired too**, and its criteria are
 * here for the same reason: the last of them — the entity has no field by the
 * name it was given — needs a real content entity to ask, and it is the single
 * most useful notice in the module, because a typo in a field name has been an
 * empty region and nothing else. Its first three reasons are driven here
 * alongside it rather than split off, because all four are one filter's answer
 * and asserting that no two of them read the same is the whole criterion.
 *
 * `neo_field` is also called on the extension instance below rather than on the
 * class. Its callback stopped being static so that it can read the **debug
 * gate**, which a class-static callable cannot reach; the **registered name**
 * is unchanged, and the assertions about what it answers are untouched.
 *
 * The notice criteria read the **notice log** through the buffering logger
 * registered below, the same way the `neo_oembed` class reads its one
 * unconditional error, because the seam logs through `\Drupal::logger()` and
 * there is no seam to inject through.
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
   * The service id of the logger the notices are asserted against.
   */
  private const LOGGER_SERVICE = 'neo_twig_test.buffering_logger';

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
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The notice seam logs through `\Drupal::logger()`, so the assertions need
    // a logger inside the container rather than a database table to read back.
    $container->register(self::LOGGER_SERVICE, BufferingLogger::class)
      ->addTag('logger');
  }

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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Symfony's BufferingLogger prints whatever is still buffered when it is
    // destroyed, which would dress a failed assertion up as an unrelated
    // error. Emptying it here keeps a failure reading as the failure it is.
    if (isset($this->container) && $this->container->has(self::LOGGER_SERVICE)) {
      $this->cleanLogs();
    }
    parent::tearDown();
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

    $default = $this->extension->renderField([
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
    $teaser = $this->extension->renderField([
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
      $this->extension->renderField(['#object' => $entity], 'field_text'),
      'A build carrying no view mode answers NULL.'
    );
    $this->assertNull(
      $this->extension->renderField([
        '#view_mode' => '',
        '#object' => $entity,
      ], 'field_text'),
      'An empty view mode is the same miss as an absent one.'
    );
    $this->assertNull(
      $this->extension->renderField([
        '#view_mode' => 'default',
        '#object' => 'not an entity',
      ], 'field_text'),
      'A build with no content entity among its values answers NULL.'
    );
    $this->assertNull(
      $this->extension->renderField([
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

  /**
   * Tests that it answers the same in both gate states, with nothing inline.
   *
   * The criterion above drives the same three misses with the **debug gate**
   * off, which is the state roughly thirty deployed sites run in. This one
   * repeats every reason `neo_target_entity` gives up with the gate on as
   * well, because that is the state a notice exists in and the state in which
   * a diagnostic could accidentally become a behaviour change.
   *
   * Nothing about the answer moves. The gate's own failure, a build naming no
   * field and a build carrying no parent object all still answer `NULL`; a
   * reference field holding nothing still answers `FALSE`, which is the
   * `reset([])` finding this class already records rather than a value this
   * ticket introduced. Two `NULL`s stop reading the same; neither stops being
   * `NULL`.
   *
   * And nothing is attached anywhere. This filter answers an entity, a list of
   * entities or nothing at all, so there is never a render array to carry an
   * **inline notice** — the build it was handed comes back out of the call
   * exactly as it went in, which is what a filter that decorated its argument
   * would break.
   */
  public function testAnswersTheSameFromNeoTargetEntityInBothGateStatesWithNothingInline(): void {
    $parent = EntityTest::create(['name' => 'Parent']);
    $parent->save();

    foreach (['off' => FALSE, 'on' => TRUE] as $state => $gate) {
      $extension = new TwigExtension(['debug' => $gate]);

      foreach ($this->targetEntityMisses($parent) as $label => $build) {
        $handed = $build;
        $answer = $extension->getTargetEntity($build);

        $this->assertNull(
          $answer,
          'With the gate ' . $state . ', neo_target_entity answers NULL for ' . $label . '.'
        );
        $this->assertSame(
          $handed,
          $build,
          'With the gate ' . $state . ', ' . $label . ' comes back exactly as it was handed over.'
        );
      }

      $empty = [
        '#theme' => 'field',
        '#field_name' => 'field_single_ref',
        '#object' => $parent,
      ];
      $handed = $empty;

      $this->assertFalse(
        $extension->getTargetEntity($empty),
        'With the gate ' . $state . ', an empty reference field still answers FALSE.'
      );
      $this->assertSame(
        $handed,
        $empty,
        'With the gate ' . $state . ', that build comes back exactly as it was handed over.'
      );
    }
  }

  /**
   * Tests that it notices a missing field name and a missing parent object.
   *
   * Past the **field-shape gate** there are two ways to get nothing that have
   * nothing to do with each other. A build with no `#field_name` never named a
   * field, so the filter had nothing to ask for; a build whose parent object
   * sits under neither `#object` nor `#field_collection_item` named one but
   * had nothing to ask. The first is usually a build that was never a field's;
   * the second is a field render array that some preprocess rebuilt and
   * stripped.
   *
   * Two different mistakes, two different fixes, so two different
   * expectations — asserted against each other and against the gate's own,
   * because until now all three were the same `NULL`. Every line names
   * `neo_target_entity` and none of them names `getTargetEntity`, the PHP
   * method behind it, which is not something a template author has ever seen.
   *
   * A parent under some third key is driven as well: it is the missing-parent
   * reason arrived at from the other direction, and it says the same thing,
   * because the filter genuinely cannot tell the two apart.
   */
  public function testNoticesMissingFieldNameAndMissingParentObjectFromNeoTargetEntity(): void {
    $parent = EntityTest::create(['name' => 'Parent']);
    $parent->save();

    $said = [];
    foreach ($this->targetEntityMisses($parent) as $label => $build) {
      $this->cleanLogs();
      $extension = new TwigExtension(['debug' => TRUE]);

      $this->assertNull($extension->getTargetEntity($build), $label . ' still answers NULL.');

      $lines = $this->noticeLines();

      $this->assertCount(1, $lines, 'neo_target_entity says something about ' . $label . '.');
      $this->assertStringContainsString(
        'neo_target_entity',
        $lines[0],
        'The line for ' . $label . ' names neo_target_entity.'
      );
      $this->assertStringNotContainsString(
        'getTargetEntity',
        $lines[0],
        'The line for ' . $label . ' never names the PHP method behind it.'
      );
      $said[$label] = $this->expectationIn($lines[0]);
    }

    $distinct = [
      'the gate itself' => $said['a value that is not a field render array at all'],
      'a field name that is not there' => $said['a field render array naming no field'],
      'a parent object that is not there' => $said['a field render array carrying no parent object'],
    ];

    $this->assertSame(
      $distinct,
      array_unique($distinct),
      'The gate, a missing field name and a missing parent object expect three different things.'
    );
    $this->assertSame(
      $said['a field render array carrying no parent object'],
      $said['a field render array whose parent is under a third key'],
      'A parent under a third key is the missing-parent reason, arrived at from the other side.'
    );
  }

  /**
   * Tests that it notices a reference that resolved to no entity.
   *
   * The last of the four reasons, and the only one where everything the filter
   * asked for was there: the build was a field's, it named a field, the parent
   * object was under a key the filter reads, and the field it asked for simply
   * points at nothing. A template author looking at a populated node and an
   * empty region has no way to tell that from any of the three misses above.
   *
   * Driven two ways, because they are the same answer from different causes: a
   * reference field an editor left empty, and a field that is not a reference
   * field at all, whose items carry no entity to resolve. Both say the same
   * thing, because from inside the filter they are the same thing — the field
   * was read and no entity came back.
   *
   * The answer does not move. It is still `FALSE`, the `reset([])` this class
   * already records, and the notice is log-only: there is no render array to
   * attach anything to.
   */
  public function testNoticesReferenceThatResolvedToNoEntityFromNeoTargetEntity(): void {
    $parent = EntityTest::create([
      'name' => 'Parent',
      'field_text' => 'Not a reference at all',
    ]);
    $parent->save();

    $resolved_to_nothing = [
      'a reference field holding nothing' => 'field_single_ref',
      'a field that is not a reference field' => 'field_text',
    ];

    $said = [];
    foreach ($resolved_to_nothing as $label => $field_name) {
      $this->cleanLogs();
      $extension = new TwigExtension(['debug' => TRUE]);

      $this->assertFalse(
        $extension->getTargetEntity([
          '#theme' => 'field',
          '#field_name' => $field_name,
          '#object' => $parent,
        ]),
        $label . ' still answers FALSE, exactly as it did.'
      );

      $lines = $this->noticeLines();

      $this->assertCount(1, $lines, 'neo_target_entity says something about ' . $label . '.');
      $this->assertStringContainsString(
        'neo_target_entity',
        $lines[0],
        'The line for ' . $label . ' names neo_target_entity.'
      );
      $this->assertStringNotContainsString(
        'getTargetEntity',
        $lines[0],
        'The line for ' . $label . ' never names the PHP method behind it.'
      );
      $said[$label] = $this->expectationIn($lines[0]);
    }

    $this->assertCount(
      1,
      array_unique($said),
      'A field read that yields no entity is one reason, however it came about.'
    );

    // And it is not one of the three reasons that stop before the field is
    // read: everything this one asked for was there.
    $this->cleanLogs();
    $extension = new TwigExtension(['debug' => TRUE]);
    $extension->getTargetEntity(['#theme' => 'field', '#object' => $parent]);
    $extension->getTargetEntity(['#theme' => 'field', '#field_name' => 'field_single_ref']);
    $extension->getTargetEntity('not a field at all');

    $earlier = array_map(fn (string $line): string => $this->expectationIn($line), $this->noticeLines());

    $this->assertNotContains(
      reset($said),
      $earlier,
      'A reference that resolved to nothing is its own answer, not one of the three before it.'
    );
  }

  /**
   * Every way neo_target_entity gives up before it reads a field.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $parent
   *   A saved parent entity for the builds that carry one.
   *
   * @return array<string, mixed>
   *   Values to hand the filter, keyed by how a failure message names them.
   */
  private function targetEntityMisses(EntityTest $parent): array {
    return [
      'a value that is not a field render array at all' => ['#theme' => 'item_list'],
      'a field render array naming no field' => [
        '#theme' => 'field',
        '#object' => $parent,
      ],
      'a field render array carrying no parent object' => [
        '#theme' => 'field',
        '#field_name' => 'field_single_ref',
      ],
      'a field render array whose parent is under a third key' => [
        '#theme' => 'field',
        '#field_name' => 'field_single_ref',
        '#entity' => $parent,
      ],
    ];
  }

  /**
   * Tests that neo_field answers NULL for every reason, in both gate states.
   *
   * The characterisation criterion above drives the same misses with the
   * **debug gate** off, which is the state roughly thirty deployed sites run
   * in. This one repeats every reason `neo_field` gives up with the gate on as
   * well, because that is the state a notice exists in and the state in which
   * a diagnostic could accidentally become a behaviour change.
   *
   * Nothing about the answer moves: a value that is not an array, a build
   * carrying no view mode, a build with no content entity among its values and
   * a field the entity does not have all still answer `NULL`, and the one call
   * that works still renders the field. Four `NULL`s stop reading the same;
   * none of them stops being `NULL`.
   *
   * The build handed in comes back out of the call exactly as it went in as
   * well. `neo_field` answers a fresh render array or nothing at all, so there
   * is never anything to carry an **inline notice** — a filter that decorated
   * its argument instead would leave the notice on the caller's own build.
   */
  public function testAnswersNullFromNeoFieldForEveryReasonInBothGateStates(): void {
    $entity = EntityTest::create([
      'name' => 'Parent',
      'field_text' => 'Rendered value',
    ]);
    $entity->save();

    foreach (['off' => FALSE, 'on' => TRUE] as $state => $gate) {
      $extension = new TwigExtension(['debug' => $gate]);

      foreach ($this->renderFieldMisses($entity) as $label => [$build, $field_id]) {
        $handed = $build;

        $this->assertNull(
          $extension->renderField($build, $field_id),
          'With the gate ' . $state . ', neo_field answers NULL for ' . $label . '.'
        );
        $this->assertSame(
          $handed,
          $build,
          'With the gate ' . $state . ', ' . $label . ' comes back exactly as it was handed over.'
        );
      }

      $rendered = $extension->renderField([
        '#view_mode' => 'default',
        '#object' => $entity,
      ], 'field_text');

      $this->assertIsArray(
        $rendered,
        'With the gate ' . $state . ', the call that works still renders the field.'
      );
      $this->assertSame(
        'field_text',
        $rendered['#field_name'],
        'With the gate ' . $state . ', it is still the named field that comes back.'
      );
    }
  }

  /**
   * Tests that it notices each of neo_field's four reasons, distinctly.
   *
   * `neo_field` is the quietest helper in the module and the one whose silence
   * costs most: a typo in a field name is an empty region and nothing else.
   * Four different mistakes have answered the same `NULL` — a value that was
   * never a render array, a build that names no view mode to render in, a
   * build holding no content entity to ask, and an entity that has no field by
   * the name it was given — and none of them said which.
   *
   * So the four are asserted **against each other**: four reasons, four
   * expectations, no two alike. Every line names `neo_field`, the **registered
   * name** a template author types, and none of them names `renderField`, the
   * PHP method behind it, which is not something a template author has ever
   * seen.
   *
   * The last of the four is the one this plan calls the single most useful
   * notice in the module, so it is asserted twice over: it is its own reason,
   * and it **names the field it could not find**, because "field_nope is not a
   * field on this entity" is the whole answer and a phrase without the name in
   * it would leave the author exactly where they started.
   *
   * An absent view mode and an empty one are asserted to be the *same* reason,
   * because they are: `empty()` is what decides, and a build carrying
   * `#view_mode => ''` is the same miss arrived at from the other side.
   */
  public function testNoticesEachOfNeoFieldsFourReasonsAsItsOwnExpectation(): void {
    $entity = EntityTest::create([
      'name' => 'Parent',
      'field_text' => 'Rendered value',
    ]);
    $entity->save();

    $said = [];
    foreach ($this->renderFieldMisses($entity) as $label => [$build, $field_id]) {
      $this->cleanLogs();
      $extension = new TwigExtension(['debug' => TRUE]);

      $this->assertNull(
        $extension->renderField($build, $field_id),
        $label . ' still answers NULL.'
      );

      $lines = $this->noticeLines();

      $this->assertCount(1, $lines, 'neo_field says something about ' . $label . '.');
      $this->assertStringContainsString(
        'neo_field',
        $lines[0],
        'The line for ' . $label . ' names neo_field.'
      );
      $this->assertStringNotContainsString(
        'renderField',
        $lines[0],
        'The line for ' . $label . ' never names the PHP method behind it.'
      );
      $said[$label] = $this->expectationIn($lines[0]);
    }

    $four = [
      'a value that was never a render array' => $said['a value that is not an array at all'],
      'a build naming no view mode' => $said['a build carrying no view mode'],
      'a build holding no content entity' => $said['a build with no content entity among its values'],
      'an entity with no field by that name' => $said['a field the entity does not have'],
    ];

    $this->assertSame(
      $four,
      array_unique($four),
      "neo_field's four reasons expect four different things."
    );
    $this->assertStringContainsString(
      'field_nope',
      $said['a field the entity does not have'],
      'The notice names the field it could not find.'
    );
    $this->assertSame(
      $said['a build carrying no view mode'],
      $said['a build whose view mode is the empty string'],
      'An absent view mode and an empty one are one reason: empty() is what decides.'
    );
  }

  /**
   * Every way neo_field answers NULL instead of rendering a field.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   A saved entity for the builds that carry one.
   *
   * @return array<string, array>
   *   The build to hand the filter and the field id to ask it for, keyed by
   *   how a failure message names the reason.
   */
  private function renderFieldMisses(EntityTest $entity): array {
    return [
      'a value that is not an array at all' => ['not a render array', 'field_text'],
      'a build carrying no view mode' => [['#object' => $entity], 'field_text'],
      'a build whose view mode is the empty string' => [
        ['#view_mode' => '', '#object' => $entity],
        'field_text',
      ],
      'a build with no content entity among its values' => [
        ['#view_mode' => 'default', '#object' => 'not an entity'],
        'field_text',
      ],
      'a field the entity does not have' => [
        ['#view_mode' => 'default', '#object' => $entity],
        'field_nope',
      ],
    ];
  }

  /**
   * The notices on the module's own channel, as a reader would see them.
   *
   * @return string[]
   *   One line per notice, in the order written.
   */
  private function noticeLines(): array {
    $lines = [];
    foreach ($this->cleanLogs() as [$level, $message, $context]) {
      if (($context['channel'] ?? '') !== 'neo_twig') {
        continue;
      }
      $this->assertSame(RfcLogLevel::DEBUG, $level, 'A notice is logged at debug level.');
      $lines[] = strtr((string) $message, array_map(
        static fn ($replacement): string => (string) $replacement,
        array_filter($context, static fn ($key): bool => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY)
      ));
    }
    return $lines;
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
   * Returns and discards everything logged since the last call.
   *
   * @return array
   *   The buffered log records, each `[level, message, context]`.
   */
  private function cleanLogs(): array {
    return $this->container->get(self::LOGGER_SERVICE)->cleanLogs();
  }

}
