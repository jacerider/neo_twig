<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_twig\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Nodes;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Tests the names neo_twig registers its Twig helpers under.
 *
 * A **Twig helper**'s registered name is the only part of it a method-level
 * test cannot see. Every other class in this suite calls `addClass()` or
 * `getUrl()` directly and would keep passing after a rename that left thirty
 * sites' templates printing nothing — the module has already shipped a docblock
 * naming a filter that was never registered, which is exactly that failure one
 * step removed from production.
 *
 * So this class asserts the registrations themselves: the names, in the order
 * they are declared, and the callable behind each one.
 *
 * The extension is constructed directly with a twig-config array. It reads
 * nothing else at construction time, so nothing here needs a container.
 */
#[Group('neo_twig')]
final class TwigExtensionRegistrationTest extends UnitTestCase {

  /**
   * The twelve filter names, in registration order.
   *
   * Written out rather than derived: a list derived from the class under test
   * would agree with any rename, which is the whole failure being guarded.
   *
   * @var list<string>
   */
  private const FILTER_NAMES = [
    'neo_class',
    'neo_child_class',
    'neo_property_class',
    'neo_attributes',
    'neo_attribute',
    'neo_child_attribute',
    'neo_label',
    'neo_value',
    'neo_raw',
    'neo_target_entity',
    'neo_children',
    'neo_field',
  ];

  /**
   * The three function names, in registration order.
   *
   * @var list<string>
   */
  private const FUNCTION_NAMES = [
    'neo_uri',
    'neo_oembed',
    'neo_inspect',
  ];

  /**
   * Every registered name and the method behind it.
   *
   * The filters first, then the functions, both in registration order. One of
   * the twelve filters is registered against the class rather than the
   * instance — `neo_children` is static — which the callable assertions below
   * distinguish without needing a second list.
   *
   * @var array<string, string>
   */
  private const HELPER_METHODS = [
    'neo_class' => 'addClass',
    'neo_child_class' => 'addChildClass',
    'neo_property_class' => 'addPropertyClass',
    'neo_attributes' => 'mergeAttributes',
    'neo_attribute' => 'setAttribute',
    'neo_child_attribute' => 'setChildAttribute',
    'neo_label' => 'getFieldLabel',
    'neo_value' => 'getFieldValue',
    'neo_raw' => 'getRawValues',
    'neo_target_entity' => 'getTargetEntity',
    'neo_children' => 'childrenFilter',
    'neo_field' => 'renderField',
    'neo_uri' => 'getUrl',
    'neo_oembed' => 'getOembed',
    'neo_inspect' => 'inspect',
  ];

  /**
   * Tests that it registers twelve filters under their documented names.
   */
  public function testRegistersTwelveFiltersUnderTheirDocumentedNames(): void {
    $filters = (new TwigExtension())->getFilters();

    $this->assertContainsOnlyInstancesOf(TwigFilter::class, $filters);
    $this->assertSame(
      self::FILTER_NAMES,
      array_map(static fn (TwigFilter $filter): string => $filter->getName(), $filters),
      'The twelve filter names are registered, in order, exactly as documented.'
    );
  }

  /**
   * Tests that it registers three functions under their documented names.
   */
  public function testRegistersThreeFunctionsUnderTheirDocumentedNames(): void {
    $functions = (new TwigExtension())->getFunctions();

    $this->assertContainsOnlyInstancesOf(TwigFunction::class, $functions);
    $this->assertSame(
      self::FUNCTION_NAMES,
      array_map(static fn (TwigFunction $function): string => $function->getName(), $functions),
      'The three function names are registered, in order, exactly as documented.'
    );
  }

  /**
   * Tests that every registered name points at an exposed callable.
   */
  public function testPointsEveryRegisteredNameAtAnExposedCallable(): void {
    $extension = new TwigExtension();
    $methods = [];

    foreach (array_merge($extension->getFilters(), $extension->getFunctions()) as $helper) {
      $name = $helper->getName();
      $callable = $helper->getCallable();

      $this->assertIsArray($callable, sprintf('"%s" is registered against a method pair.', $name));
      $this->assertIsCallable($callable, sprintf('"%s" is registered against something callable.', $name));

      [$target, $method] = $callable;
      // An instance method carries the extension itself; a static one carries
      // the class name. Either way the target is this extension and not some
      // other object the registration could have drifted onto.
      $this->assertSame(
        TwigExtension::class,
        is_object($target) ? $target::class : $target,
        sprintf('"%s" is registered against the extension itself.', $name)
      );
      $this->assertTrue(
        method_exists(TwigExtension::class, $method),
        sprintf('"%s" names a method the extension actually declares.', $name)
      );
      $this->assertTrue(
        (new \ReflectionMethod(TwigExtension::class, $method))->isPublic(),
        sprintf('"%s" names a method the extension exposes publicly.', $name)
      );

      $methods[$name] = $method;
    }

    $this->assertSame(
      self::HELPER_METHODS,
      $methods,
      'Every registered name resolves to the documented method, and no name is registered twice.'
    );
  }

  /**
   * Tests that neo_field is registered against a callable that reads the gate.
   *
   * The one PHP-level move in the whole notice plan, and the criterion that
   * says what did **not** move with it. `neo_field` has four silent `NULL`
   * returns, one of which is "that entity has no field by that name" — the
   * single most useful notice in the module — and a class-static callable
   * cannot read the **debug gate** those notices sit behind, because the gate
   * is an instance property. So the callback becomes an instance method and
   * the registration points at the extension itself.
   *
   * The **registered name** is the contract, and it is unchanged: a template
   * that types `|neo_field('field_x')` is unaffected, which is what the
   * assertion on the name and on the method behind it says. The only thing
   * that moved is a PHP method nothing outside this module calls.
   *
   * `neo_children` is asserted alongside it as the deliberate counterexample.
   * It gains no notice — returning no children is its answer rather than a job
   * left undone — so it needs no gate, and its callback stays exactly where it
   * was. Without that half, a future change that moved every static callback
   * to the instance "for consistency" would pass.
   */
  public function testRegistersNeoFieldAgainstCallableThatCanReadTheGate(): void {
    $extension = new TwigExtension(['debug' => TRUE]);
    $filters = [];
    foreach ($extension->getFilters() as $filter) {
      $filters[$filter->getName()] = $filter;
    }

    $this->assertArrayHasKey(
      'neo_field',
      $filters,
      'The registered name does not move: it is the actual contract.'
    );

    [$target, $method] = $filters['neo_field']->getCallable();

    $this->assertSame(
      $extension,
      $target,
      'neo_field is registered against the extension instance, which is the only'
      . ' target that can read the debug gate a notice sits behind.'
    );
    $this->assertSame('renderField', $method, 'The method behind the name is unchanged.');
    $this->assertFalse(
      (new \ReflectionMethod(TwigExtension::class, 'renderField'))->isStatic(),
      'A static method cannot reach an instance property, so it is no longer static.'
    );

    [$children_target, $children_method] = $filters['neo_children']->getCallable();

    $this->assertSame(
      TwigExtension::class,
      $children_target,
      'neo_children stays registered against the class: it has no notice to gate.'
    );
    $this->assertTrue(
      (new \ReflectionMethod(TwigExtension::class, $children_method))->isStatic(),
      "neo_children's callback stays static, which is what that registration needs."
    );
  }

  /**
   * Tests that neo_uri's output is safe when the call passes no options.
   */
  public function testDeclaresNeoUriOutputSafeWhenTheCallPassesNoOptions(): void {
    // Read through the registered function rather than by calling the callback
    // directly: that is how Twig reaches it, so the assertion covers the
    // `is_safe_callback` wiring as well as the answer.
    $this->assertSame(
      ['html'],
      $this->neoUri()->getSafe(self::args()),
      'neo_uri("…") escapes nothing: with no options there is no second query'
      . ' parameter, so no ampersand can appear in the generated URL.'
    );
  }

  /**
   * Tests that a constant options array of at most one entry stays safe.
   */
  public function testDeclaresNeoUriOutputSafeWhenOptionsHoldAtMostOneConstantEntry(): void {
    $this->assertSame(
      ['html'],
      $this->neoUri()->getSafe(self::args(self::constantArray([]))),
      'neo_uri("…", {}) carries no parameters at all.'
    );
    $this->assertSame(
      ['html'],
      $this->neoUri()->getSafe(self::args(self::constantArray(['query' => 'a']))),
      'One constant entry cannot produce the ampersand that separates two.'
    );
    // The callback also reads a node named `parameters`, which is inherited
    // from core's path()/url(), whose second parameter carries that name.
    // neo_uri's own second parameter is $options, so a named-argument call in
    // a real template lands under `options` and never reaches this branch —
    // the branch is pinned here as written, not as reachable.
    $this->assertSame(
      ['html'],
      $this->neoUri()->getSafe(self::args(self::constantArray(['query' => 'a']), 'parameters')),
      'The named `parameters` node is read the same way as the positional one.'
    );
  }

  /**
   * Tests that a variable, or more than one entry, makes the output unsafe.
   */
  public function testDeclaresNeoUriOutputUnsafeWhenOptionsVaryOrHoldMoreThanOneEntry(): void {
    $this->assertSame(
      [],
      $this->neoUri()->getSafe(self::args(new ContextVariable('options', 1))),
      'A variable is opaque at compile time, so nothing can be assumed of it.'
    );
    $this->assertSame(
      [],
      $this->neoUri()->getSafe(self::args(self::constantArray(['a' => '1', 'b' => '2']))),
      'Two entries mean two query parameters, and an ampersand between them.'
    );
    $this->assertSame(
      [],
      $this->neoUri()->getSafe(self::args(self::constantArray([
        'query' => self::constantArray(['a' => '1', 'b' => '2']),
      ]))),
      'One entry holding a sub-array is still more than one query parameter.'
    );
    // NULL options is the shape one of neo_uri's three past bug fixes was
    // about. It is a constant, not an array, so it takes the unsafe answer.
    $this->assertSame(
      [],
      $this->neoUri()->getSafe(self::args(new ConstantExpression(NULL, 1))),
      'A constant that is not an array tells the callback nothing.'
    );
  }

  /**
   * Returns the registered neo_uri function.
   *
   * @return \Twig\TwigFunction
   *   The function Twig would look up for a `neo_uri()` call.
   */
  private function neoUri(): TwigFunction {
    $functions = [];
    foreach ((new TwigExtension())->getFunctions() as $function) {
      $functions[$function->getName()] = $function;
    }
    $this->assertArrayHasKey('neo_uri', $functions);
    return $functions['neo_uri'];
  }

  /**
   * Builds the argument node Twig hands a call's safety callback.
   *
   * `Nodes` is what the expression parser builds an argument list out of:
   * positional arguments under integer keys, named ones under the argument's
   * own name. It is built here rather than parsed out of a template so that
   * each shape the callback branches on is visible in the test that needs it.
   *
   * @param \Twig\Node\Expression\AbstractExpression|null $options
   *   The second argument, or NULL to pass none at all.
   * @param string|null $name
   *   The name to pass $options under, or NULL to pass it positionally.
   *
   * @return \Twig\Node\Nodes
   *   The argument list for `neo_uri('entity:node/1', …)`.
   */
  private static function args(?AbstractExpression $options = NULL, ?string $name = NULL): Nodes {
    $uri = new ConstantExpression('entity:node/1', 1);
    if ($options === NULL) {
      return new Nodes([$uri], 1);
    }
    return new Nodes($name === NULL ? [$uri, $options] : [$uri, $name => $options], 1);
  }

  /**
   * Builds an array literal node with constant keys.
   *
   * @param array $pairs
   *   Keys, and values that are either scalars to wrap in a constant or
   *   expression nodes to use as they are.
   *
   * @return \Twig\Node\Expression\ArrayExpression
   *   The node a `{…}` literal parses to.
   */
  private static function constantArray(array $pairs): ArrayExpression {
    $array = new ArrayExpression([], 1);
    foreach ($pairs as $key => $value) {
      $array->addElement(
        $value instanceof AbstractExpression ? $value : new ConstantExpression($value, 1),
        new ConstantExpression($key, 1)
      );
    }
    return $array;
  }

}
