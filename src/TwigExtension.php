<?php

namespace Drupal\neo_twig;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Link;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\media\OEmbed\Resource;
use Drupal\media\OEmbed\ResourceException;
use Psr\Log\LoggerInterface;
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
   * The module's logger channel, resolved lazily, or NULL until it is.
   *
   * @var \Psr\Log\LoggerInterface|null
   */
  protected ?LoggerInterface $logger = NULL;

  /**
   * Notices already written this request, keyed by the message itself.
   *
   * @var array<string, true>
   */
  protected array $noticed = [];

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
   * Says that a helper returned early without doing its job.
   *
   * Every silent guard in this class calls this, and supplies only two things:
   * its own **registered name** and one short phrase saying what it expected.
   * What arrived is described here, the line is worded here and the logging
   * happens here, so changing how a notice reads is one edit rather than one
   * per guard.
   *
   * Three properties are the reason it exists:
   *
   * - **The gate is read first.** Before the value is described, before a
   *   message is built and before the container is asked for anything, this
   *   checks `twig.config.debug` — the same switch `inspect()` is gated on and
   *   the same one that turns on core's FILE NAME SUGGESTIONS comments. A
   *   deployed site pays one boolean per guard and nothing else, which is what
   *   makes it acceptable to call this from every early return on the module's
   *   hottest surface.
   * - **It changes nothing a deployed site renders.** It answers the value the
   *   helper is about to hand back, so a guard reads
   *   `return $this->notice('neo_class', '…', $value, $build);`, and it never
   *   raises and never throws — including when the container cannot answer for
   *   a logger, because a diagnostic that takes a template down is worse than
   *   the silence it replaces.
   * - **The log is deduplicated per request.** The guard that fires most is
   *   the benign empty-value one, and fifty identical lines on one page make a
   *   log useless. The same message is written once however many call sites
   *   produce it; a different helper, a different expectation or a different
   *   value arriving is a different message and gets its own line.
   *
   * The value is described in `inspect()`'s own words — "render array (#type:
   * link)", "Url", "string: …" — reused exactly as it stands, because that is
   * the vocabulary this module has already taught a template author.
   *
   * **Two surfaces, and the shape of the answer picks one.** The log takes
   * every notice and is the only surface a helper answering `NULL`, a string
   * or a `Link` can reach. A helper that is about to hand back a render array
   * hands it over as well, and the same line is attached to that element so it
   * renders where the element renders — inside core's own Twig-debug output
   * markers, which name the template that made the call without any helper
   * needing to know what template it is in. That is the mysterious case: a
   * real value went in, a real value came out, and nothing changed.
   *
   * Two rules govern the inline surface and neither bends:
   *
   * - **An empty value never carries one.** Attaching to one would make it
   *   truthy, and a dev template guarding on `{% if thing %}` would render a
   *   branch production does not. An empty value gets the log line and nothing
   *   else, which is also why the guard that fires most cannot flood a page.
   * - **It is never deduplicated.** An inline notice belongs to the element
   *   that failed, so suppressing the second copy of a message would leave the
   *   notice beside the wrong element. Only the log deduplicates.
   *
   * @param string $name
   *   The helper's registered name, as a template author types it —
   *   `neo_class`, never `addClass`. The method behind the name is not
   *   something a template author has ever seen.
   * @param string $expected
   *   One short phrase saying what the helper expected, such as "a render
   *   array or a Link".
   * @param mixed $received
   *   The value that actually arrived.
   * @param mixed $build
   *   The value the helper is about to hand back, when it has one to hand
   *   over. Omit it and the notice is log-only, which is all a helper
   *   answering `NULL`, a string or a `Link` can carry.
   *
   * @return mixed
   *   What the helper should hand back: the value handed over when there was
   *   one, and otherwise the value that arrived, unchanged.
   *
   * @see \Drupal\neo_twig\TwigExtension::describe()
   */
  protected function notice(string $name, string $expected, $received, $build = NULL) {
    // Everything below this line costs something, so nothing below it runs on
    // an environment where a developer has not opted into Twig debugging.
    if (!$this->debug) {
      return $build ?? $received;
    }

    $description = self::describe($received);
    $message = $name . ': expected ' . $expected . ', received ' . $description;

    // The inline surface comes first and is never deduplicated, because the
    // notice belongs to this element rather than to this request. An empty
    // value is skipped outright: attaching to one would make it truthy.
    if (is_array($build) && $build) {
      $this->attach($build, $message);
    }

    if (!isset($this->noticed[$message])) {
      $this->noticed[$message] = TRUE;
      try {
        // Resolved lazily, the way getOembed() resolves its services: a
        // constructor argument would be a container rebuild on every site that
        // installs this module, and the injection question belongs to its own
        // backlog candidate.
        $this->logger ??= \Drupal::logger('neo_twig');
        $this->logger->debug('@helper: expected @expected, received @received', [
          '@helper' => $name,
          '@expected' => $expected,
          '@received' => $description,
        ]);
      }
      catch (\Throwable) {
        // No container, no logger service, or a logger that could not write.
        // The template renders exactly what it rendered before, which is the
        // whole promise of the gate this notice sits behind.
      }
    }

    return $build ?? $received;
  }

  /**
   * Attaches a notice after an element's own output.
   *
   * `#suffix` rather than a child, because a child is only rendered by an
   * element that renders its children and a notice has to arrive on every
   * shape a helper is handed. Whatever the element already had there is kept
   * and the notice lands after it, so the element's own trailing output still
   * comes out first.
   *
   * The message is escaped: the description in it carries a field's value, a
   * token's output or a stray string from a template, none of which this
   * module wrote. The result is marked safe so the box survives the renderer's
   * admin filter, which strips the `style` attribute otherwise — and anything
   * that was already there is put through exactly the filter the renderer
   * would have applied to it, so marking the pair safe does not smuggle
   * anything past a check it would otherwise have faced.
   *
   * @param array $build
   *   The element the helper is about to hand back.
   * @param string $message
   *   The notice, as plain text.
   *
   * @see \Drupal\Core\Render\Renderer::xssFilterAdminIfUnsafe()
   */
  private function attach(array &$build, string $message): void {
    $existing = $build['#suffix'] ?? '';
    if (!$existing instanceof MarkupInterface) {
      $existing = Xss::filterAdmin((string) $existing);
    }
    $build['#suffix'] = Markup::create($existing
      . '<div style="' . self::INSPECT_BOX . 'padding:4px 6px;">'
      . Html::escape($message)
      . '</div>');
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
      new TwigFilter('neo_field', [$this, 'renderField']),
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
      $this->notice('neo_oembed', self::OEMBED_EXPECTS_URL, $url);
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
      $this->notice('neo_uri', self::URI_EXPECTS_LINKING, $uri);
      return '';
    }
    try {
      return Url::fromUri($uri, $options)->toString();
    }
    catch (\Exception $e) {
      try {
        // Resolving here after the first pass threw is a successful two-stage
        // resolution rather than a give-up, so it says nothing: every rooted
        // path, bare fragment and query string a template holds arrives this
        // way, and a notice would fire on the most ordinary call this helper
        // receives.
        return Url::fromUserInput($uri, $options)->toString();
      }
      catch (\Exception $e) {
        // If the URI is invalid. Both passes failed, so the visitor is sent to
        // the front page instead of anywhere the author named — which is the
        // one answer here worth saying out loud.
        $this->notice('neo_uri', self::URI_EXPECTS_RESOLVABLE, $uri);
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
   * What the three attribute writers expect to be handed.
   *
   * Their first two guards catch the same thing from two directions — a value
   * that is empty, and a value that is neither a render array nor a `Link` —
   * so they say the same thing about what they wanted. What separates the two
   * notices is the description of what actually turned up.
   */
  private const WRITER_EXPECTS = 'a render array or a Link';

  /**
   * What the three attribute writers expect the key to reach.
   *
   * One phrase for one rule, said once: the resolver they share is the only
   * place a write target is looked for, so it is the only place that has to
   * say what it was looking for.
   */
  private const WRITER_EXPECTS_TARGET = 'a key that resolves to an element it can write into';

  /**
   * What the three writing children walkers expect to be handed.
   *
   * Their empty guard is the one place all three say the same thing: there was
   * nothing to walk at all, before any question of children or of a property
   * arises. `neo_children` is not among them — finding no children is its
   * answer rather than a job left undone.
   */
  private const WALKER_EXPECTS = 'a render array to walk';

  /**
   * What the two child walkers expect the array they were handed to hold.
   *
   * `neo_property_class` says something else, because it does not walk
   * children: it names the property it looked under, which is the only part of
   * its answer a shared phrase could not carry.
   */
  private const WALKER_EXPECTS_CHILDREN = 'a render array with children';

  /**
   * What the four filters behind the field-shape gate expect to be handed.
   *
   * One reason from four filters, so one phrase: the gate is a single check on
   * `#theme`, and the only thing that separates the four notices it produces
   * is the **registered name** of the filter that tripped it. Everything past
   * the gate says something of its own, because that is where two `NULL`s
   * stopped reading the same.
   */
  private const FIELD_EXPECTS = "a field's render array";

  /**
   * What neo_value expects a field render array to hold.
   *
   * Distinct from the gate on purpose. The gate answers "that is not a field";
   * this answers "that is a field, and it has nothing in it" — two different
   * mistakes with two different fixes, which the same `NULL` has been hiding.
   */
  private const FIELD_EXPECTS_ITEMS = 'a field render array with at least one item';

  /**
   * What neo_raw expects to find under #items.
   *
   * A build with no `#items`, or an `#items` that is not typed data, is one
   * the filter cannot read at all — usually an array themed as a field by
   * something other than the field formatter. The fix is a different build.
   */
  private const FIELD_EXPECTS_TYPED_DATA = 'field items that are typed data';

  /**
   * What neo_raw expects those items to hold.
   *
   * The ordinary unfilled field, and a different answer from the one above:
   * the items were readable and there was nothing in them. The fix is to fill
   * the field in, or to guard the template.
   */
  private const FIELD_EXPECTS_VALUES = 'field items with at least one value';

  /**
   * What neo_target_entity expects the render array to name.
   */
  private const FIELD_EXPECTS_NAME = 'a render array naming the field it came from';

  /**
   * What neo_target_entity expects the render array to carry.
   *
   * The parent object is read from `#object` or `#field_collection_item` and
   * from nowhere else, so a build that carries one under some third key is
   * this reason too — the filter genuinely cannot tell the two apart.
   */
  private const FIELD_EXPECTS_PARENT = "a render array carrying the field's parent object";

  /**
   * What neo_target_entity expects the field it read to point at.
   *
   * The only one of the four where everything asked for was there: the build
   * was a field's, it named a field, the parent object was under a key the
   * filter reads, and the field points at nothing. From inside a template that
   * is indistinguishable from the three misses before it.
   */
  private const FIELD_EXPECTS_REFERENCE = 'a reference field pointing at an entity';

  /**
   * What neo_field expects to be handed.
   *
   * The first of four reasons this filter answers `NULL`, and the only one
   * that is about the argument's type rather than its contents: a string, an
   * object or anything else a template can pipe never had an entity in it to
   * find.
   */
  private const RENDER_FIELD_EXPECTS = 'a render array to find an entity in';

  /**
   * What neo_field expects that render array to name.
   *
   * The view mode is read off the build rather than passed in, so a build that
   * carries none cannot say how to render anything. `empty()` decides, so a
   * `#view_mode` of `''` is this reason too — the same miss from the other
   * side, and deliberately not a fifth phrase.
   */
  private const RENDER_FIELD_EXPECTS_VIEW_MODE = 'a render array carrying the view mode to render in';

  /**
   * What neo_field expects to find among the render array's values.
   *
   * The filter takes no entity argument: it sweeps the build for the first
   * value that is a content entity and asks that one. A build with none is
   * usually one a preprocess rebuilt, or the wrong half of an entity-reference
   * build.
   */
  private const RENDER_FIELD_EXPECTS_ENTITY = 'a render array holding a content entity';

  /**
   * What neo_field expects the entity it found to have.
   *
   * The single most useful notice in the module, which is why it is a format
   * string rather than a phrase: a typo in a field name has been an empty
   * region and nothing else, and an answer that did not name the field would
   * leave the author exactly where they started.
   */
  private const RENDER_FIELD_EXPECTS_FIELD = 'a field named "%s" on the entity it found';

  /**
   * What neo_uri expects a uri it is asked for a url to be.
   *
   * The three **non-linking uri** routes are deliberately path-less and the
   * empty string is the correct answer for them, but from inside a template it
   * is an anchor that silently goes nowhere.
   */
  private const URI_EXPECTS_LINKING = 'a uri that links somewhere';

  /**
   * What neo_uri expects when neither resolution pass could answer.
   *
   * The `/` fallback, which is what this helper's three past bug fixes were
   * all about: rather than fatalling the page it is printed on, an
   * unresolvable uri quietly becomes a link to the front page.
   */
  private const URI_EXPECTS_RESOLVABLE = 'a uri Drupal can resolve to a url';

  /**
   * What neo_oembed expects to be handed.
   *
   * Its other empty answer — a resource that could not be fetched — already
   * logs an error unconditionally and loudly, which is right for a remote
   * call, and is not routed through this seam.
   */
  private const OEMBED_EXPECTS_URL = 'a url to fetch an oEmbed resource for';

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
   * **A path that misses is where a writer is most mysterious**, so this is
   * also where it says so. A real render array went in, a real render array
   * comes back out and nothing about it changed — which is invisible from
   * inside a template. One resolver means one notice describing one rule for
   * all three writers, rather than three copies of it drifting apart; the
   * writer supplies only its **registered name**, because that is the only
   * part of the answer the resolver cannot know.
   *
   * The value the notice reports is what the path actually resolved to —
   * NULL, a scalar, or an empty array — because that is the difference
   * between the three mistakes an author is trying to tell apart. The array
   * is taken by reference so the writer hands back the one the notice was
   * attached to; with the **debug gate** off nothing is attached and the
   * reference is never written through.
   *
   * @param array $build
   *   The renderable array being written to. Gains the **inline notice** when
   *   the target cannot be reached and Twig debugging is on.
   * @param string|array $key
   *   The key to write to, or an array whose last entry is the key and whose
   *   earlier entries are a parents path to the element holding it.
   * @param string $name
   *   The calling writer's registered name, as a template author types it.
   *
   * @return array|null
   *   A tuple of the parents path, the resolved key and the element found at
   *   that path, or NULL when there is nothing to write to.
   */
  protected function resolveWriteTarget(&$build, $key, string $name) {
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
    $build = $this->notice($name, self::WRITER_EXPECTS_TARGET, $element, $build);
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
      return $this->notice('neo_class', self::WRITER_EXPECTS, $build);
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
      return $this->notice('neo_class', self::WRITER_EXPECTS, $build);
    }

    $target = $this->resolveWriteTarget($build, $key, 'neo_class');
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
      return $this->notice('neo_child_class', self::WALKER_EXPECTS, $build);
    }
    if ($prop) {
      if (strpos($prop, '#') !== 0) {
        $prop = '#' . $prop;
      }
    }
    // Resolved before the loop rather than in its header, so that a walk over
    // an array holding no children can say so. What reaches this is whatever
    // survived the empty guard, exactly as before: a value that is not an
    // array still raises out of Element::children() rather than being caught
    // here, because this ticket adds a notice and removes no behaviour.
    $children = Element::children($build);
    if (!$children) {
      return $this->notice('neo_child_class', self::WALKER_EXPECTS_CHILDREN, $build, $build);
    }
    foreach ($children as $child) {
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
      return $this->notice('neo_property_class', self::WALKER_EXPECTS, $build);
    }
    // Make sure the key starts with a hash, so it's treated as a property.
    if (strpos($property, '#') !== 0) {
      $property = '#' . $property;
    }
    if (isset($build[$property]) && is_array($build[$property])) {
      foreach ($build[$property] as $delta => $item) {
        $build[$property][$delta] = $this->addClass($item, $classes, $key);
      }
      return $build;
    }
    // The hash-prefixed name is what the notice reports, because it is what
    // was actually looked for: this walker has no bare-key fallback, so an
    // author who typed `items` at an array holding `items` is looking straight
    // at the key they named while nothing was ever read from it. What arrived
    // is whatever sat at that key — nothing, or something that is not an array
    // — which is the difference between the two mistakes.
    return $this->notice(
      'neo_property_class',
      'an array under ' . $property,
      is_array($build) ? ($build[$property] ?? NULL) : NULL,
      $build
    );
  }

  /**
   * Add attributes to a renderable array.
   */
  public function mergeAttributes($build, Attribute|array $attributes, $key = 'attributes') {
    if (empty($build)) {
      return $this->notice('neo_attributes', self::WRITER_EXPECTS, $build);
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
      return $this->notice('neo_attributes', self::WRITER_EXPECTS, $build);
    }
    $target = $this->resolveWriteTarget($build, $key, 'neo_attributes');
    if ($target === NULL) {
      return $build;
    }
    [$parents, $key, $element] = $target;
    $element[$key] = $element[$key] ?? [];
    $mirror = NULL;
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
      // The merged set is array-shaped, so it is handed over to be mirrored.
      // The two branches above are not: an Attribute object and a Url are
      // written into in place, and neither is a payload the mirror can read.
      $mirror = $elementAttributes->toArray();
    }

    return $this->commitWriteTarget($build, $parents, $element, $mirror);
  }

  /**
   * Add attribute to a renderable array.
   */
  public function setAttribute($build, string $attribute, string $value, $key = 'attributes') {
    if (empty($build)) {
      return $this->notice('neo_attribute', self::WRITER_EXPECTS, $build);
    }
    if ($build instanceof Link) {
      $url = $build->getUrl();
      $options = $url->getOptions();
      $options['attributes'][$attribute] = $value;
      $url->setOptions($options);
      return $build;
    }
    if (!is_array($build)) {
      return $this->notice('neo_attribute', self::WRITER_EXPECTS, $build);
    }
    $target = $this->resolveWriteTarget($build, $key, 'neo_attribute');
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
      return $this->notice('neo_child_attribute', self::WALKER_EXPECTS, $build);
    }
    $children = Element::children($build);
    if (!$children) {
      return $this->notice('neo_child_attribute', self::WALKER_EXPECTS_CHILDREN, $build, $build);
    }
    foreach ($children as $child) {
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
      $this->notice('neo_label', self::FIELD_EXPECTS, $build);
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
      $this->notice('neo_value', self::FIELD_EXPECTS, $build);
      return NULL;
    }

    $elements = Element::children($build);
    if (empty($elements)) {
      $this->notice('neo_value', self::FIELD_EXPECTS_ITEMS, $build);
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
      $this->notice('neo_raw', self::FIELD_EXPECTS, $build);
      return NULL;
    }
    if (!isset($build['#items']) || !($build['#items'] instanceof TypedDataInterface)) {
      // What was found at that key, rather than the build it sat in: an
      // absent `#items` and one holding a plain array are the two mistakes an
      // author is telling apart here.
      $this->notice('neo_raw', self::FIELD_EXPECTS_TYPED_DATA, $build['#items'] ?? NULL);
      return NULL;
    }

    $item_values = $build['#items']->getValue();
    if (empty($item_values)) {
      $this->notice('neo_raw', self::FIELD_EXPECTS_VALUES, $item_values);
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
      $this->notice('neo_target_entity', self::FIELD_EXPECTS, $build);
      return NULL;
    }
    if (!isset($build['#field_name'])) {
      $this->notice('neo_target_entity', self::FIELD_EXPECTS_NAME, $build);
      return NULL;
    }

    $parent_key = $this->getParentObjectKey($build);
    if (empty($parent_key)) {
      $this->notice('neo_target_entity', self::FIELD_EXPECTS_PARENT, $build);
      return NULL;
    }

    // Use the parent object to load the target entity of the field.
    /** @var \Drupal\Core\Entity\ContentEntityInterface $parent */
    $parent = $build[$parent_key];

    // Resolved into a variable rather than read in the loop header so that the
    // list itself can be described when nothing comes back: which field was
    // read is the part of that answer the build cannot supply.
    $items = $parent->get($build['#field_name']);

    $entities = [];
    /** @var \Drupal\Core\Field\FieldItemInterface $field */
    foreach ($items as $item) {
      if (isset($item->entity)) {
        $entities[] = $item->entity;
      }
    }

    if (!$entities) {
      // Noticed rather than returned, because `reset([])` answers FALSE here
      // and this ticket explains an answer without moving it.
      $this->notice('neo_target_entity', self::FIELD_EXPECTS_REFERENCE, $items);
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
   * Answers `NULL` for four different reasons and, with the **debug gate** on,
   * says which one: the value was never a render array, it carries no view
   * mode to render in, it holds no content entity to ask, or the entity it
   * found has no field by the name it was given. The last of those is the
   * single most useful notice in the module — a typo in a field name has been
   * an empty region and nothing else.
   *
   * **Not static, deliberately.** This was registered as a class-static
   * callable, and a static method cannot read the gate those notices sit
   * behind, because the gate is an instance property. The **registered name**
   * — `neo_field` — is unchanged, which is the contract; the only thing that
   * moved is a PHP method nothing outside this module calls. `neo_children`
   * next door stays static, because it makes no notice and needs no gate.
   *
   * @param array $build
   *   The render array whose children are to be filtered.
   * @param string $field_id
   *   The field id to render.
   *
   * @return array
   *   The element's children.
   */
  public function renderField($build, string $field_id): array|null {
    if (!is_array($build) || empty($build['#view_mode'])) {
      // One guard, two reasons. A value that was never a render array could
      // not have held an entity at all; a build that carries no view mode may
      // hold one and cannot say how to render it. Both still answer the same
      // NULL they always did, and only what the notice says it expected moves.
      //
      // The guard itself stays whole rather than being split in two. The
      // characterisation suite censuses the bare non-array guard as belonging
      // to the three attribute writers — the helpers that hand the value back
      // rather than answering NULL — by reading this class's own source, so a
      // second copy of that line here would enrol this filter in a census it
      // does not belong to.
      if (is_array($build)) {
        // What was found at that key rather than the build it sat in: an
        // absent view mode and one that is the empty string are the same miss
        // arrived at from two sides.
        $this->notice('neo_field', self::RENDER_FIELD_EXPECTS_VIEW_MODE, $build['#view_mode'] ?? NULL);
      }
      else {
        $this->notice('neo_field', self::RENDER_FIELD_EXPECTS, $build);
      }
      return NULL;
    }
    $entity = array_filter($build, function ($entity) {
      return $entity instanceof ContentEntityInterface;
    });
    if (empty($entity)) {
      $this->notice('neo_field', self::RENDER_FIELD_EXPECTS_ENTITY, $build);
      return NULL;
    }
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = reset($entity);
    if (!$entity->hasField($field_id)) {
      // The entity rather than the build, because everything the filter asked
      // for was there and the only thing left to say is which entity was
      // asked — the field it could not find is named in the expectation.
      $this->notice('neo_field', sprintf(self::RENDER_FIELD_EXPECTS_FIELD, $field_id), $entity);
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
