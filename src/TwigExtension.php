<?php

namespace Drupal\neo_twig;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Render\Element;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Defines Twig extensions.
 */
class TwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      new TwigFilter('neo_class', [$this, 'addClass']),
      new TwigFilter('neo_child_class', [$this, 'addChildClass']),
      new TwigFilter('neo_label', [$this, 'getFieldLabel']),
      new TwigFilter('neo_value', [$this, 'getFieldValue']),
      new TwigFilter('neo_raw', [$this, 'getRawValues']),
      new TwigFilter('neo_target_entity', [$this, 'getTargetEntity']),

    ];
  }

  /**
   * Add classes to a renderable array.
   */
  public function addClass($build, $classes, $key = 'attributes') {
    if (empty($build)) {
      return $build;
    }
    // Make sure the key starts with a hash, so it's treated as a property.
    if (strpos($key, '#') !== 0) {
      $key = '#' . $key;
    }
    if (!is_array($classes)) {
      $classes = [$classes];
    }
    $build[$key] = $build[$key] ?? [];
    $build[$key]['class'] = array_merge($build[$key]['class'] ?? [], $classes);

    // Link elements have a different structure.
    if (!empty($build['#type']) && $build['#type'] === 'link') {
      $build['#options']['attributes']['class'] = array_merge($build['#options']['attributes']['class'] ?? [], $build[$key]['class']);
    }
    return $build;
  }

  /**
   * Add classes to a renderable image array.
   */
  public function addChildClass(array $build, $classes, $key = 'attributes') {
    // Make sure the key starts with a hash, so it's treated as a property.
    if (strpos($key, '#') !== 0) {
      $key = '#' . $key;
    }
    if (!is_array($classes)) {
      $classes = [$classes];
    }
    foreach (Element::children($build) as $child) {
      $build[$child][$key] = $build[$child][$key] ?? [];
      $build[$child][$key]['class'] = array_merge($build[$child][$key]['class'] ?? [], $classes);
    }
    return $build;
  }

  /**
   * Twig filter callback: Only return a field's label.
   *
   * @param array|null $build
   *   Render array of a field.
   *
   * @return string
   *   The label of a field. If $build is not a render array of a field, NULL is
   *   returned.
   */
  public function getFieldLabel($build) {
    if (!$this->isFieldRenderArray($build)) {
      return NULL;
    }
    if (isset($build['#items'])) {
      $field_definition = $build['#items']->getFieldDefinition();
      if ($field_definition instanceof BaseFieldDefinition) {
        $settings = $field_definition->getSettings();
        if (!empty($settings['field_labels']['display_label'])) {
          return $settings['field_labels']['display_label'];
        }
      }
      elseif ($field_definition instanceof ThirdPartySettingsInterface && empty($build['#field_label_default'])) {
        $label = $field_definition->getThirdPartySetting('field_labels', 'display_label');
        if (isset($label) && !empty($label)) {
          return $label;
        }
      }
    }
    return $build['#title'] ?? NULL;
  }

  /**
   * Twig filter callback: Only return a field's value(s).
   *
   * @param array|null $build
   *   Render array of a field.
   *
   * @return array
   *   Array of render array(s) of field value(s). If $build is not the render
   *   array of a field, NULL is returned.
   */
  public function getFieldValue($build) {

    if (!$this->isFieldRenderArray($build)) {
      return NULL;
    }

    $elements = Element::children($build);
    if (empty($elements)) {
      return NULL;
    }

    $items = [];
    foreach ($elements as $delta) {
      $items[$delta] = $build[$delta];
    }

    return $items;
  }

  /**
   * Twig filter callback: Return specific field item(s) value.
   *
   * @param array|null $build
   *   Render array of a field.
   * @param string $key
   *   The name of the field value to retrieve.
   *
   * @return array|null
   *   Single field value or array of field values. If the field value is not
   *   found, null is returned.
   */
  public function getRawValues($build, $key = '') {

    if (!$this->isFieldRenderArray($build)) {
      return NULL;
    }
    if (!isset($build['#items']) || !($build['#items'] instanceof TypedDataInterface)) {
      return NULL;
    }

    $item_values = $build['#items']->getValue();
    if (empty($item_values)) {
      return NULL;
    }

    $raw_values = [];
    foreach ($item_values as $delta => $values) {
      if ($key) {
        $raw_values[$delta] = $values[$key] ?? NULL;
      }
      else {
        $raw_values[$delta] = $values;
      }
    }

    return count($raw_values) > 1 ? $raw_values : reset($raw_values);
  }

  /**
   * Twig filter callback: Return the referenced entity.
   *
   * Suitable for entity_reference fields: Image, File, Taxonomy, etc.
   *
   * @param array|null $build
   *   Render array of a field.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\ContentEntityInterface[]|null
   *   A single target entity or an array of target entities. If no target
   *   entity is found, null is returned.
   */
  public function getTargetEntity($build) {

    if (!$this->isFieldRenderArray($build)) {
      return NULL;
    }
    if (!isset($build['#field_name'])) {
      return NULL;
    }

    $parent_key = $this->getParentObjectKey($build);
    if (empty($parent_key)) {
      return NULL;
    }

    // Use the parent object to load the target entity of the field.
    /** @var \Drupal\Core\Entity\ContentEntityInterface $parent */
    $parent = $build[$parent_key];

    $entities = [];
    /** @var \Drupal\Core\Field\FieldItemInterface $field */
    foreach ($parent->get($build['#field_name']) as $item) {
      if (isset($item->entity)) {
        $entities[] = $item->entity;
      }
    }

    return count($entities) > 1 ? $entities : reset($entities);
  }

  /**
   * Checks whether the render array is a field's render array.
   *
   * @param array|null $build
   *   The render array.
   *
   * @return bool
   *   True if $build is a field render array.
   */
  protected function isFieldRenderArray($build) {

    return isset($build['#theme']) && $build['#theme'] == 'field';
  }

  /**
   * Determine the build array key of the parent object.
   *
   * Different field types use different key names.
   *
   * @param array $build
   *   Render array.
   *
   * @return string
   *   The key.
   */
  private function getParentObjectKey(array $build) {
    $options = ['#object', '#field_collection_item'];
    $parent_key = '';

    foreach ($options as $option) {
      if (isset($build[$option])) {
        $parent_key = $option;
        break;
      }
    }

    return $parent_key;
  }

}
