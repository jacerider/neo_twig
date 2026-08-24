<?php

namespace Drupal\neo_twig;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Link;
use Drupal\Core\Render\Element;
use Drupal\Core\Template\Attribute;
use Drupal\media\OEmbed\Resource;
use Drupal\media\OEmbed\ResourceException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\Url;
use Twig\TwigFunction;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Node;

/**
 * Defines Twig extensions.
 */
class TwigExtension extends AbstractExtension {

  /**
   * Whether Twig debugging is on.
   *
   * @var bool
   */
  protected bool $debug;

  /**
   * Constructs a TwigExtension object.
   *
   * @param array $twig_config
   *   The Twig configuration from the container.
   */
  public function __construct(array $twig_config = []) {
    $this->debug = !empty($twig_config['debug']);
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('neo_uri', [$this, 'getUrl'], [
        'is_safe_callback' => [$this, 'isUrlGenerationSafe'],
      ]),
      new TwigFunction('neo_oembed', [$this, 'getOembed']),
      new TwigFunction('neo_inspect', [$this, 'inspect'], [
        'is_safe' => ['html'],
        // So `neo_inspect()` with no argument can list what is in scope.
        'needs_context' => TRUE,
      ]),
    ];
  }

  /**
   * Renders the addressable structure of a render array, for debugging.
   *
   * Answers "what can I print in here?" without a devel/kint dependency. Gated
   * on twig.config.debug, the same switch that turns on core's FILE NAME
   * SUGGESTIONS comments, so it is inert on any environment where a developer
   * has not deliberately opted into Twig debugging — including if one of these
   * calls is committed by accident.
   *
   * Called with no argument it lists every variable in scope, which is the
   * quickest answer to "what can I print in this template?". Called with one
   * it walks that value's printable children instead.
   *
   * @param array $context
   *   The Twig context, supplied by Twig itself.
   * @param mixed $var
   *   The variable to inspect, normally a render array. Omit to list the whole
   *   context.
   * @param int $depth
   *   How many levels of children to walk.
   *
   * @return string
   *   An HTML table, or an empty string when debugging is off.
   */
  public function inspect(array $context, $var = NULL, int $depth = 2): string {
    if (!$this->debug) {
      return '';
    }
    // NULL also covers `neo_inspect(nope)` for a variable that does not exist,
    // where listing what does exist is the answer the author actually needs.
    if ($var === NULL) {
      return $this->inspectContext($context);
    }
    if (!is_array($var)) {
      return '<pre style="' . self::INSPECT_BOX . '">neo_inspect: '
        . Html::escape(is_object($var) ? get_class($var) : gettype($var))
        . '</pre>';
    }

    $head = array_filter([
      isset($var['#type']) ? '#type: ' . self::inspectScalar($var['#type']) : NULL,
      isset($var['#theme']) ? '#theme: ' . self::inspectScalar($var['#theme']) : NULL,
      isset($var['#theme_wrappers']) ? '#theme_wrappers: ' . self::inspectScalar($var['#theme_wrappers']) : NULL,
    ]);

    $rows = $this->inspectRows($var, max(1, $depth));
    $out = '<table style="' . self::INSPECT_BOX . 'border-collapse:collapse;width:100%;">';
    $out .= '<caption style="text-align:left;padding:4px 6px;font-weight:bold;">neo_inspect'
      . ($head ? ' — ' . Html::escape(implode('  ', $head)) : '')
      . '</caption>';
    if (!$rows) {
      $out .= '<tr><td style="padding:4px 6px;">no printable children'
        . ' — this value renders as a whole</td></tr>';
    }
    foreach ($rows as $row) {
      $out .= '<tr>'
        . '<td style="padding:2px 6px;border-top:1px solid #0002;white-space:nowrap;">'
        . str_repeat('&nbsp;&nbsp;', $row['level'])
        . '<code>' . Html::escape($row['key']) . '</code></td>'
        . '<td style="padding:2px 6px;border-top:1px solid #0002;opacity:.75;">' . Html::escape($row['type']) . '</td>'
        . '<td style="padding:2px 6px;border-top:1px solid #0002;opacity:.75;">' . Html::escape($row['title']) . '</td>'
        . '</tr>';
    }
    return $out . '</table>';
  }

  /**
   * Lists every variable in scope in the current template.
   *
   * @param array $context
   *   The Twig context.
   *
   * @return string
   *   An HTML table.
   */
  private function inspectContext(array $context): string {
    $out = '<table style="' . self::INSPECT_BOX . 'border-collapse:collapse;width:100%;">';
    $out .= '<caption style="text-align:left;padding:4px 6px;font-weight:bold;">'
      . 'neo_inspect — variables in scope</caption>';
    $rows = 0;
    foreach ($context as $name => $value) {
      // Twig's own internals (_self, _context, _charset) and our plumbing are
      // not things a template author can usefully print.
      if (!is_string($name) || str_starts_with($name, '_')) {
        continue;
      }
      $rows++;
      $out .= '<tr>'
        . '<td style="padding:2px 6px;border-top:1px solid #0002;white-space:nowrap;">'
        . '<code>{{ ' . Html::escape($name) . ' }}</code></td>'
        . '<td style="padding:2px 6px;border-top:1px solid #0002;opacity:.75;">'
        . Html::escape(self::describe($value)) . '</td>'
        . '</tr>';
    }
    if (!$rows) {
      $out .= '<tr><td style="padding:4px 6px;">no variables in scope</td></tr>';
    }
    $out .= '<tr><td colspan="2" style="padding:4px 6px;border-top:1px solid #0002;opacity:.75;">'
      . 'Pass one in to walk its children, e.g. <code>{{ neo_inspect(' . Html::escape((string) array_key_first(array_filter(
        $context,
        fn($k) => is_string($k) && !str_starts_with($k, '_'),
        ARRAY_FILTER_USE_KEY
      )) ?: 'form') . ') }}</code></td></tr>';
    return $out . '</table>';
  }

  /**
   * Describes a context value in one short phrase.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   A description such as "render array (#type: form)" or "string".
   */
  private static function describe($value): string {
    if (is_array($value)) {
      $properties = array_filter(array_keys($value), fn($k) => is_string($k) && str_starts_with($k, '#'));
      if ($properties) {
        foreach (['#type', '#theme', '#markup', '#plain_text'] as $property) {
          if (isset($value[$property])) {
            return 'render array (' . $property . ': ' . self::inspectScalar($value[$property]) . ')';
          }
        }
        return 'render array';
      }
      return 'array (' . count($value) . ' items)';
    }
    if (is_object($value)) {
      $class = get_class($value);
      $short = substr($class, (int) strrpos($class, '\\') + 1);
      if ($value instanceof MarkupInterface) {
        return $short . ': ' . Unicode::truncate(trim(strip_tags((string) $value)), 40, TRUE, TRUE);
      }
      return $short;
    }
    if (is_bool($value)) {
      return 'bool: ' . ($value ? 'TRUE' : 'FALSE');
    }
    if ($value === NULL) {
      return 'NULL';
    }
    return gettype($value) . ': ' . Unicode::truncate((string) $value, 40, TRUE, TRUE);
  }

  /**
   * Inline style shared by every neo_inspect box.
   */
  private const INSPECT_BOX = 'font:12px/1.5 ui-monospace,monospace;background:#ffd;color:#000;border:1px solid #cc0;margin:4px 0;';

  /**
   * Flattens a render-array property to a short string.
   *
   * @param mixed $value
   *   The property value.
   *
   * @return string
   *   A one-line representation.
   */
  private static function inspectScalar($value): string {
    if (is_array($value)) {
      return implode(', ', array_map(fn($v) => is_scalar($v) ? (string) $v : '…', $value));
    }
    return is_scalar($value) ? (string) $value : '…';
  }

  /**
   * Collects the printable children of a render array.
   *
   * @param array $element
   *   The render array.
   * @param int $depth
   *   How many levels to walk.
   * @param int $level
   *   The current level.
   *
   * @return array
   *   Rows with `level`, `key`, `type` and `title`.
   */
  private function inspectRows(array $element, int $depth, int $level = 0): array {
    $rows = [];
    foreach ($element as $key => $child) {
      if (!is_string($key) || str_starts_with($key, '#')) {
        continue;
      }
      $type = '';
      $title = '';
      if (is_array($child)) {
        foreach (['#type', '#theme', '#markup', '#plain_text'] as $property) {
          if (isset($child[$property])) {
            $type = ltrim($property, '#') === 'type' || ltrim($property, '#') === 'theme'
              ? self::inspectScalar($child[$property])
              : ltrim($property, '#');
            break;
          }
        }
        $title = isset($child['#title']) ? (string) $child['#title'] : '';
      }
      else {
        $type = gettype($child);
      }
      $rows[] = [
        'level' => $level,
        'key' => $key,
        'type' => $type,
        'title' => $title,
      ];
      if (is_array($child) && $level + 1 < $depth) {
        $rows = array_merge($rows, $this->inspectRows($child, $depth, $level + 1));
      }
    }
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      new TwigFilter('neo_class', [$this, 'addClass']),
      new TwigFilter('neo_child_class', [$this, 'addChildClass']),
      new TwigFilter('neo_property_class', [$this, 'addPropertyClass']),
      new TwigFilter('neo_attributes', [$this, 'mergeAttributes']),
      new TwigFilter('neo_attribute', [$this, 'setAttribute']),
      new TwigFilter('neo_child_attribute', [$this, 'setChildAttribute']),
      new TwigFilter('neo_label', [$this, 'getFieldLabel']),
      new TwigFilter('neo_value', [$this, 'getFieldValue']),
      new TwigFilter('neo_raw', [$this, 'getRawValues']),
      new TwigFilter('neo_target_entity', [$this, 'getTargetEntity']),
      new TwigFilter('neo_children', [self::class, 'childrenFilter']),
      new TwigFilter('neo_field', [self::class, 'renderField']),
    ];
  }

  /**
   * Get an oEmbed renderable array from a URL.
   *
   * @param string $url
   *   The oEmbed URL.
   * @param int $max_width
   *   The maximum width.
   * @param int $max_height
   *   The maximum height.
   *
   * @return array
   *   A renderable array.
   */
  public function getOembed(string $url, int $max_width = 0, int $max_height = 0): array {
    if (empty($url)) {
      return [];
    }

    try {
      /** @var \Drupal\media\OEmbed\UrlResolverInterface $url_resolver */
      $url_resolver = \Drupal::service('media.oembed.url_resolver');
      /** @var \Drupal\media\OEmbed\ResourceFetcherInterface $resource_fetcher */
      $resource_fetcher = \Drupal::service('media.oembed.resource_fetcher');
      /** @var \Drupal\media\IFrameUrlHelper $iframe_url_helper */
      $iframe_url_helper = \Drupal::service('media.oembed.iframe_url_helper');

      $resource_url = $url_resolver->getResourceUrl($url, $max_width, $max_height);
      $resource = $resource_fetcher->fetchResource($resource_url);
    }
    catch (ResourceException $exception) {
      \Drupal::logger('neo_twig')->error("Could not retrieve the remote URL (@url): %error", [
        '@url' => $url,
        '%error' => $exception->getPrevious() ? $exception->getPrevious()->getMessage() : $exception->getMessage(),
      ]);
      return [];
    }

    if ($resource->getType() === Resource::TYPE_LINK) {
      return [
        '#title' => $resource->getTitle(),
        '#type' => 'link',
        '#url' => Url::fromUri($url),
      ];
    }

    if ($resource->getType() === Resource::TYPE_PHOTO) {
      return [
        '#theme' => 'image',
        '#uri' => $resource->getUrl()->toString(),
        '#width' => $resource->getWidth(),
        '#height' => $resource->getHeight(),
        '#attributes' => [
          'loading' => 'lazy',
        ],
      ];
    }

    $iframe_url = Url::fromRoute('media.oembed_iframe', [], [
      'absolute' => TRUE,
      'query' => [
        'url' => $url,
        'max_width' => $max_width,
        'max_height' => $max_height,
        'hash' => $iframe_url_helper->getHash($url, $max_width, $max_height),
      ],
    ]);

    $config = \Drupal::config('media.settings');
    $domain = $config->get('iframe_domain');
    if ($domain) {
      $iframe_url->setOption('base_url', $domain);
    }

    $element = [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#attributes' => [
        'src' => $iframe_url->toString(),
        'scrolling' => FALSE,
        'width' => $resource->getWidth() ?: $max_width,
        'height' => $resource->getHeight() ?: $max_height,
        'class' => ['media-oembed-content'],
        'loading' => 'lazy',
      ],
      '#attached' => [
        'library' => [
          'media/oembed.formatter',
        ],
      ],
    ];

    $title = $resource->getTitle();
    if ($title) {
      $element['#attributes']['title'] = $title;
    }

    return $element;
  }

  /**
   * Get the URL for a given URI.
   *
   * @param string|null $uri
   *   The URI.
   * @param array $options
   *   The options.
   * @param bool $destination
   *   Whether to add the current page as a destination query parameter.
   *
   * @return string
   *   The URL.
   */
  public function getUrl($uri, ?array $options = [], $destination = FALSE) {
    // Templates commonly pass a value that does not exist, such as
    // `link.options` on a link that has none, which arrives here as NULL.
    $options = $options ?? [];
    if ($destination) {
      $options['query']['destination'] = Url::fromRoute('<current>')->toString();
    }
    if (empty($uri)) {
      return Url::fromRoute('<current>', [], $options)->toString();
    }
    if ($this->isNonLinkingUri($uri)) {
      // <nolink>, <none> and <button> are deliberately path-less: core renders
      // them as a <span>, not a link. Neither branch below can say that — the
      // routed form resolves to '' and the bare form falls through to the '/'
      // fallback, sending the visitor to the front page. Return the empty
      // string so an un-updated template degrades to a dead href rather than
      // navigating somewhere the author never named. Suppressing the anchor
      // itself is the template's job: guard on the uri before calling this.
      return '';
    }
    try {
      return Url::fromUri($uri, $options)->toString();
    }
    catch (\Exception $e) {
      try {
        return Url::fromUserInput($uri, $options)->toString();
      }
      catch (\Exception $e) {
        // If the URI is invalid.
        return '/';
      }
    }
  }

  /**
   * Checks whether a uri names one of Drupal's three non-linking routes.
   *
   * Matches both the routed form Url::toUriString() produces
   * (`route:<nolink>`) and the bare form a component author writes into an
   * examples block (`<nolink>`).
   *
   * Kept local rather than shared with neo_alchemist's NonLinkingUri: neo_twig
   * declares no module dependencies, and three route names are not worth
   * acquiring one.
   *
   * @param mixed $uri
   *   The uri to test. Templates pass whatever they hold, so a non-string
   *   (a Url object, a MarkupInterface) simply answers FALSE and falls
   *   through to the resolution below.
   *
   * @return bool
   *   TRUE for <nolink>, <none> and <button>.
   */
  protected function isNonLinkingUri(mixed $uri): bool {
    if (!is_string($uri)) {
      return FALSE;
    }
    $route = str_starts_with($uri, 'route:') ? substr($uri, strlen('route:')) : $uri;
    [$route] = explode(';', $route, 2);
    return in_array($route, ['<nolink>', '<none>', '<button>'], TRUE);
  }

  /**
   * Resolve the write target for an attribute writer.
   *
   * Splits a parents path out of the key argument, applies the hash-prefix
   * rule to what is left and fetches the element the write lands on. Answers
   * "nothing to write to" with NULL, which every writer turns into the value
   * it was handed, untouched.
   *
   * The hash-prefix rule is one rule for all three writers: the key is
   * prefixed with a hash only when the element carries no bare key of that
   * name. A bare key that is already there is a key something already reads,
   * so the write goes where it is read.
   *
   * @param array $build
   *   The renderable array being written to.
   * @param string|array $key
   *   The key to write to, or an array whose last entry is the key and whose
   *   earlier entries are a parents path to the element holding it.
   *
   * @return array|null
   *   A tuple of the parents path, the resolved key and the element found at
   *   that path, or NULL when there is nothing to write to.
   */
  protected function resolveWriteTarget($build, $key) {
    $parents = [];
    if (is_array($key)) {
      $parents = $key;
      $key = array_pop($parents);
    }
    $element = NestedArray::getValue($build, $parents);
    if ($element && is_array($element)) {
      if (!isset($element[$key])) {
        // Make sure the key starts with a hash, so it's treated as a property.
        if (strpos($key, '#') !== 0) {
          $key = '#' . $key;
        }
      }
      return [$parents, $key, $element];
    }
    return NULL;
  }

  /**
   * Commit a written element back through its parents path.
   *
   * Applies the link-element mirror before the write-back: a #type link
   * element renders out of its #options rather than out of the property that
   * was just written, so a writer that has an array-shaped payload to mirror
   * hands it over here. A writer with no payload gets no mirror.
   *
   * @param array $build
   *   The renderable array being written to.
   * @param array $parents
   *   The parents path the element was found at.
   * @param array $element
   *   The element, with the writer's write already applied.
   * @param array|null $mirror
   *   Attributes to mirror into a link element's options, keyed by attribute
   *   name, or NULL to mirror nothing. An array value is merged onto whatever
   *   the options already held; anything else replaces it.
   *
   * @return array
   *   The renderable array with the element written back into it.
   */
  protected function commitWriteTarget($build, $parents, $element, $mirror = NULL) {
    $isLink = !empty($element['#type']) && $element['#type'] === 'link';
    // Link elements have a different structure.
    if ($mirror !== NULL && $isLink) {
      foreach ($mirror as $name => $value) {
        if (is_array($value)) {
          $held = $element['#options']['attributes'][$name] ?? [];
          $element['#options']['attributes'][$name] = array_merge($held, $value);
        }
        else {
          $element['#options']['attributes'][$name] = $value;
        }
      }
    }
    NestedArray::setValue($build, $parents, $element);
    return $build;
  }

  /**
   * Add classes to a renderable array.
   */
  public function addClass($build, $classes, $key = 'attributes') {
    if (empty($build)) {
      return $build;
    }
    if (!is_array($classes)) {
      $classes = [$classes];
    }
    foreach ($classes as &$value) {
      if (is_array($value)) {
        $value = implode(' ', $value);
      }
    }
    if ($build instanceof Link) {
      $url = $build->getUrl();
      $options = $url->getOptions();
      $options['attributes']['class'] = array_merge($options['attributes']['class'] ?? [], $classes);
      $url->setOptions($options);
      return $build;
    }
    if (!is_array($build)) {
      return $build;
    }

    $target = $this->resolveWriteTarget($build, $key);
    if ($target === NULL) {
      return $build;
    }
    [$parents, $key, $element] = $target;
    $element[$key] = $element[$key] ?? [];
    $mirror = NULL;
    if ($element[$key] instanceof Attribute) {
      $element[$key] = $element[$key]->addClass($classes);
    }
    elseif ($element[$key] instanceof Url) {
      $options = $element[$key]->getOptions();
      $options['attributes']['class'] = array_merge($options['attributes']['class'] ?? [], $classes);
      $element[$key]->setOptions($options);
    }
    else {
      $element[$key]['class'] = array_merge($element[$key]['class'] ?? [], $classes);
      $mirror = ['class' => $element[$key]['class']];
    }
    return $this->commitWriteTarget($build, $parents, $element, $mirror);
  }

  /**
   * Add classes to the children of a renderable.
   *
   * Example:
   * {{ build|add_child_class('my-class') }}
   * {{ build|add_child_class('my-class', 'wrapper_attributes') }}
   *
   * This will add the class to any child element that has a property with the
   * provided key and value. For example ['#field_name' => 'title'].
   * {{ build|add_child_class('my-class', 'wrapper_attributes',
   * 'field_name', 'title') }}
   */
  public function addChildClass($build, $classes, $key = 'attributes', $prop = NULL, $propValue = NULL) {
    if (empty($build)) {
      return $build;
    }
    if ($prop) {
      if (strpos($prop, '#') !== 0) {
        $prop = '#' . $prop;
      }
    }
    foreach (Element::children($build) as $child) {
      if ($prop && $propValue) {
        if (isset($build[$child][$prop]) && $build[$child][$prop] === $propValue) {
          $build[$child] = $this->addClass($build[$child], $classes, $key);
        }
      }
      else {
        $build[$child] = $this->addClass($build[$child], $classes, $key);
      }
    }
    return $build;
  }

  /**
   * Add classes to the children of a renderable.
   */
  public function addPropertyClass($build, $classes, $property = 'items', $key = 'attributes') {
    if (empty($build)) {
      return $build;
    }
    // Make sure the key starts with a hash, so it's treated as a property.
    if (strpos($property, '#') !== 0) {
      $property = '#' . $property;
    }
    if (isset($build[$property]) && is_array($build[$property])) {
      foreach ($build[$property] as $delta => $item) {
        $build[$property][$delta] = $this->addClass($item, $classes, $key);
      }
    }
    return $build;
  }

  /**
   * Add attributes to a renderable array.
   */
  public function mergeAttributes($build, Attribute|array $attributes, $key = 'attributes') {
    if (empty($build)) {
      return $build;
    }
    if (is_array($attributes)) {
      $attributes = new Attribute($attributes);
    }
    if ($build instanceof Link) {
      $url = $build->getUrl();
      $options = $url->getOptions();
      $linkAttributes = new Attribute($options['attributes'] ?? []);
      $linkAttributes->merge($attributes);
      $options['attributes'] = $linkAttributes->toArray();
      $url->setOptions($options);
      return $build;
    }
    if (!is_array($build)) {
      return $build;
    }
    $target = $this->resolveWriteTarget($build, $key);
    if ($target === NULL) {
      return $build;
    }
    [$parents, $key, $element] = $target;
    $element[$key] = $element[$key] ?? [];
    if ($element[$key] instanceof Attribute) {
      // An Attribute object is merged into rather than replaced, so anything
      // still holding a handle to it sees the merge.
      $element[$key]->merge($attributes);
    }
    elseif ($element[$key] instanceof Url) {
      // A Url has nothing to iterate from outside, so building an attribute
      // set out of it would replace it with an empty one. Merge into the
      // url's own options instead and leave it a Url.
      $options = $element[$key]->getOptions();
      $urlAttributes = new Attribute($options['attributes'] ?? []);
      $urlAttributes->merge($attributes);
      $options['attributes'] = $urlAttributes->toArray();
      $element[$key]->setOptions($options);
    }
    else {
      $elementAttributes = new Attribute($element[$key]);
      $elementAttributes->merge($attributes);
      $element[$key] = $elementAttributes;
    }

    // This writer hands over no payload, so it makes no link-element mirror.
    return $this->commitWriteTarget($build, $parents, $element);
  }

  /**
   * Add attribute to a renderable array.
   */
  public function setAttribute($build, string $attribute, string $value, $key = 'attributes') {
    if (empty($build)) {
      return $build;
    }
    if ($build instanceof Link) {
      $url = $build->getUrl();
      $options = $url->getOptions();
      $options['attributes'][$attribute] = $value;
      $url->setOptions($options);
      return $build;
    }
    if (!is_array($build)) {
      return $build;
    }
    $target = $this->resolveWriteTarget($build, $key);
    if ($target === NULL) {
      return $build;
    }
    [$parents, $key, $element] = $target;
    $element[$key] = $element[$key] ?? [];
    $mirror = [$attribute => $value];
    if ($element[$key] instanceof Url) {
      // A Url cannot be written to as an array. Set the attribute in the
      // url's own options instead and leave it a Url. Nothing is handed over
      // to mirror, because the payload never became an attribute set here.
      $options = $element[$key]->getOptions();
      $options['attributes'][$attribute] = $value;
      $element[$key]->setOptions($options);
      $mirror = NULL;
    }
    else {
      // An Attribute object is written into through its array access; an
      // array-shaped value is written into directly.
      $element[$key][$attribute] = $value;
    }
    return $this->commitWriteTarget($build, $parents, $element, $mirror);
  }

  /**
   * Add classes to the children of a renderable.
   */
  public function setChildAttribute($build, string $attribute, string $value, $key = 'attributes') {
    if (empty($build)) {
      return $build;
    }
    foreach (Element::children($build) as $child) {
      $build[$child] = $this->setAttribute($build[$child], $attribute, $value, $key);
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

  /**
   * Filters out the children of a render array, optionally sorted by weight.
   *
   * @param array $build
   *   The render array whose children are to be filtered.
   * @param bool $sort
   *   Boolean to indicate whether the children should be sorted by weight.
   *
   * @return array
   *   The element's children.
   */
  public static function childrenFilter(array $build, bool $sort = FALSE): array {
    $keys = Element::children($build, $sort);
    return array_intersect_key($build, array_flip($keys));
  }

  /**
   * Render a field from an entity reference render array.
   *
   * @param array $build
   *   The render array whose children are to be filtered.
   * @param string $field_id
   *   The field id to render.
   *
   * @return array
   *   The element's children.
   */
  public static function renderField($build, string $field_id): array|null {
    if (!is_array($build) || empty($build['#view_mode'])) {
      return NULL;
    }
    $entity = array_filter($build, function ($entity) {
      return $entity instanceof ContentEntityInterface;
    });
    if (empty($entity)) {
      return NULL;
    }
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = reset($entity);
    if (!$entity->hasField($field_id)) {
      return NULL;
    }
    return $entity->get($field_id)->view($build['#view_mode']);
  }

  /**
   * Determines at compile time whether the generated URL will be safe.
   *
   * Saves the unneeded automatic escaping for performance reasons.
   *
   * The URL generation process percent encodes non-alphanumeric characters.
   * Thus, the only character within a URL that must be escaped in HTML is the
   * ampersand ("&") which separates query params. Thus we cannot mark
   * the generated URL as always safe, but only when we are sure there won't be
   * multiple query params. This is the case when there are none or only one
   * constant parameter given. For instance, we know beforehand this will not
   * need to be escaped:
   * - path('route')
   * - path('route', {'param': 'value'})
   * But the following may need to be escaped:
   * - path('route', var)
   * - path('route', {'param': ['val1', 'val2'] }) // a sub-array
   * - path('route', {'param1': 'value1', 'param2': 'value2'})
   * If param1 and param2 reference placeholders in the route, it would not
   * need to be escaped, but we don't know that in advance.
   *
   * @param \Twig\Node\Node $args_node
   *   The arguments of the path/url functions.
   *
   * @return array
   *   An array with the contexts the URL is safe
   */
  public function isUrlGenerationSafe(Node $args_node) {
    // Support named arguments.
    $parameter_node = $args_node->hasNode('parameters') ? $args_node->getNode('parameters') : ($args_node->hasNode(1) ? $args_node->getNode(1) : NULL);

    if (!isset($parameter_node) || $parameter_node instanceof ArrayExpression && count($parameter_node) <= 2 &&
        (!$parameter_node->hasNode(1) || $parameter_node->getNode(1) instanceof ConstantExpression)) {
      return ['html'];
    }

    return [];
  }

}
