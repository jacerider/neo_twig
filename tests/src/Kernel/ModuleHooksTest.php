<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_twig\Kernel;

use Drupal\Core\Link;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the four hook implementations in neo_twig.module.
 *
 * The half of the module no other test in this suite reaches, and the half
 * other packages build on — `neo_image`'s own template prints the image and
 * link attribute variables added here. One theme-registry alter widens three
 * core templates, and three preprocess hooks consume what the widened
 * variables carry.
 *
 * **The registry is the real one.** The alter is asserted against the registry
 * the site actually builds rather than a hand-made array passed to the
 * function, because the claim being made is that `image_formatter`,
 * `file_link` and `responsive_image_formatter` genuinely gain the variables —
 * something a test feeding the hook its own array cannot show. That is what
 * makes this a kernel test, along with the file-link hook needing a real file
 * entity and the image hook needing a real url object.
 *
 * Each preprocess hook does something different with what it is given. The
 * image formatter merges into the image element's attributes and into the
 * url's options. The file link rebuilds the whole link from scratch — a copy
 * of core's own preprocess with a merge inserted, which is the only way
 * attributes reach a link core has already built. The responsive image
 * formatter wraps its link attributes in an `Attribute` object for the
 * template to print, and merges the image attributes into the element.
 *
 * `file`, `image`, `breakpoint` and `responsive_image` are installed because
 * the three templates are theirs. Installing them declares nothing:
 * `neo_twig.info.yml` and `composer.json` still name no dependency at all.
 */
#[Group('neo_twig')]
final class ModuleHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'breakpoint',
    'responsive_image',
    'neo_twig',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The theme registry is built for the default theme, which is named in
    // `system.theme` — without it there is no theme to build a registry for.
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);
    // The file-link hook reads a real file entity's uri, mime type and size.
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
  }

  /**
   * Tests that the image formatter gains both attribute variables.
   *
   * Criterion: it adds image and link attribute variables to the image
   * formatter template's registry entry.
   *
   * Both default to NULL, which is what every branch of the preprocess hook
   * gates on: a template rendered without them behaves exactly as core's did.
   */
  public function testRegistryAlterAddsImageFormatterAttributeVariables(): void {
    $variables = $this->registryVariables('image_formatter');

    $this->assertArrayHasKey(
      'image_attributes',
      $variables,
      'The image formatter template gains an image_attributes variable.'
    );
    $this->assertNull($variables['image_attributes'], 'It defaults to NULL.');
    $this->assertArrayHasKey(
      'link_attributes',
      $variables,
      'The image formatter template gains a link_attributes variable.'
    );
    $this->assertNull($variables['link_attributes'], 'It defaults to NULL.');
    // The alter widens the entry; it must not narrow it.
    $this->assertArrayHasKey('item', $variables, "Core's own variables survive the alter.");
    $this->assertArrayHasKey('url', $variables);
  }

  /**
   * Tests that the file link gains a link attribute variable, and only that.
   *
   * Criterion: it adds a link attribute variable to the file link template's
   * registry entry.
   *
   * This is the one entry of the three that gets a single variable — a file
   * link has no image element to attribute, so no `image_attributes` is added
   * and a template asking for one would not find it.
   */
  public function testRegistryAlterAddsFileLinkAttributeVariable(): void {
    $variables = $this->registryVariables('file_link');

    $this->assertArrayHasKey(
      'link_attributes',
      $variables,
      'The file link template gains a link_attributes variable.'
    );
    $this->assertNull($variables['link_attributes'], 'It defaults to NULL.');
    $this->assertArrayNotHasKey(
      'image_attributes',
      $variables,
      'The file link template gains no image_attributes variable.'
    );
    $this->assertArrayHasKey('file', $variables, "Core's own variables survive the alter.");
  }

  /**
   * Tests that the responsive image formatter gains both variables.
   *
   * Criterion: it adds image and link attribute variables to the responsive
   * image formatter template's registry entry.
   */
  public function testRegistryAlterAddsResponsiveImageFormatterAttributeVariables(): void {
    $variables = $this->registryVariables('responsive_image_formatter');

    $this->assertArrayHasKey(
      'image_attributes',
      $variables,
      'The responsive image formatter template gains an image_attributes variable.'
    );
    $this->assertNull($variables['image_attributes'], 'It defaults to NULL.');
    $this->assertArrayHasKey(
      'link_attributes',
      $variables,
      'The responsive image formatter template gains a link_attributes variable.'
    );
    $this->assertNull($variables['link_attributes'], 'It defaults to NULL.');
    $this->assertArrayHasKey('item', $variables, "Core's own variables survive the alter.");
    $this->assertArrayHasKey('url', $variables);
  }

  /**
   * Tests where the image formatter hook writes each of its two inputs.
   *
   * Criterion: it merges image attributes into the image element and link
   * attributes into the url's options.
   *
   * Two different targets and two different mechanisms. The image attributes
   * are merged deeply into the render element's `#attributes`, so a class
   * already there is kept and the new one is appended rather than replacing
   * it. The link attributes never touch the variables at all — they are merged
   * into the `Url` object's options, which is a mutation of the very object
   * core handed in.
   */
  public function testImageFormatterMergesImageAttributesAndLinkOptions(): void {
    $url = Url::fromUri('https://example.com/target', [
      'attributes' => [
        'class' => ['existing-link'],
        'rel' => 'nofollow',
      ],
    ]);
    $variables = [
      'image' => [
        '#theme' => 'image',
        '#uri' => 'public://neo-twig-test.png',
        '#attributes' => [
          'class' => ['existing-image'],
          'alt' => 'An existing alt',
        ],
      ],
      'url' => $url,
      'image_attributes' => [
        'class' => ['added-image'],
        'loading' => 'lazy',
      ],
      'link_attributes' => [
        'class' => ['added-link'],
        'target' => '_blank',
      ],
    ];

    neo_twig_preprocess_image_formatter($variables);

    $this->assertSame(
      [
        '#theme' => 'image',
        '#uri' => 'public://neo-twig-test.png',
        '#attributes' => [
          'class' => ['existing-image', 'added-image'],
          'alt' => 'An existing alt',
          'loading' => 'lazy',
        ],
      ],
      $variables['image'],
      'The image attributes are merged deeply into the image element, appending classes.'
    );
    $this->assertSame(
      [
        'class' => ['existing-link', 'added-link'],
        'rel' => 'nofollow',
        'target' => '_blank',
      ],
      $url->getOptions()['attributes'],
      'The link attributes are merged into the url options, not into the variables.'
    );
    $this->assertSame(
      $url,
      $variables['url'],
      'The url handed in is mutated in place rather than replaced.'
    );
    $this->assertArrayNotHasKey(
      'attributes',
      $variables,
      'Nothing is written to a link_attributes variable the template would print.'
    );
  }

  /**
   * Tests that the file link is rebuilt so the link attributes reach it.
   *
   * Criterion: it rebuilds the file link so that the supplied link attributes
   * reach it.
   *
   * Core has already built `$variables['link']` by the time this runs, and a
   * built `Link` cannot be reopened — so the hook discards it and builds the
   * whole thing again from the file, which is why the mime-type `type`
   * attribute and the `url.site` cache context reappear here. The merge is the
   * one line that is not core's.
   */
  public function testFileLinkIsRebuiltWithTheSuppliedLinkAttributes(): void {
    $file = $this->createFileEntity();
    $variables = [
      'file' => $file,
      'description' => NULL,
      'link' => Link::fromTextAndUrl('Already built', Url::fromUri('https://example.com/stale')),
      'link_attributes' => [
        'class' => ['file-link'],
        'target' => '_blank',
      ],
    ];

    neo_twig_preprocess_file_link($variables);

    $link = $variables['link'];
    $this->assertInstanceOf(Link::class, $link, 'The link is rebuilt.');
    $this->assertSame(
      'neo-twig-test.txt',
      $link->getText(),
      'With no description the filename is the link text.'
    );
    $this->assertSame(
      $file->createFileUrl(FALSE),
      $link->getUrl()->getUri(),
      "The link points at the file's own absolute url, replacing whatever core built."
    );
    $this->assertSame(
      [
        'type' => 'text/plain; length=42',
        'class' => ['file-link'],
        'target' => '_blank',
      ],
      $link->getUrl()->getOptions()['attributes'],
      'The supplied attributes are merged onto the mime-type attribute the hook rebuilds.'
    );
    $this->assertContains(
      'url.site',
      $variables['#cache']['contexts'],
      'Rebuilding the url re-adds the cache context core added for it.'
    );

    // A description replaces the link text and moves the filename into a title
    // attribute, which the same merge then sees.
    $described = [
      'file' => $file,
      'description' => 'The described file',
      'link_attributes' => ['target' => '_blank'],
    ];

    neo_twig_preprocess_file_link($described);

    $this->assertSame(
      'The described file',
      $described['link']->getText(),
      'A description wins over the filename as the link text.'
    );
    $this->assertSame(
      [
        'type' => 'text/plain; length=42',
        'title' => 'neo-twig-test.txt',
        'target' => '_blank',
      ],
      $described['link']->getUrl()->getOptions()['attributes'],
      'The filename becomes a title attribute, and the supplied attributes join it.'
    );
  }

  /**
   * Tests the responsive image formatter's two unlike halves.
   *
   * Criterion: it wraps responsive image link attributes in an Attribute
   * object and merges the image attributes.
   *
   * The only one of the three hooks that hands a template something to print:
   * the link attributes are replaced in place by an `Attribute` object, which
   * is why the documented template usage is `link_attributes|without('href')`
   * rather than a url merge. The image half is the same deep merge the image
   * formatter does.
   */
  public function testResponsiveImageFormatterWrapsLinkAttributesAndMergesImageAttributes(): void {
    $variables = [
      'responsive_image' => [
        '#type' => 'responsive_image',
        '#uri' => 'public://neo-twig-test.png',
        '#attributes' => [
          'class' => ['existing-image'],
          'alt' => 'An existing alt',
        ],
      ],
      'image_attributes' => [
        'class' => ['added-image'],
        'loading' => 'lazy',
      ],
      'link_attributes' => [
        'class' => ['added-link'],
        'target' => '_blank',
      ],
    ];

    neo_twig_preprocess_responsive_image_formatter($variables);

    $this->assertInstanceOf(
      Attribute::class,
      $variables['link_attributes'],
      'The link attributes are replaced by an Attribute object the template can print.'
    );
    $this->assertSame(
      ' class="added-link" target="_blank"',
      (string) $variables['link_attributes'],
      'The object prints the attributes it was given.'
    );
    $this->assertSame(
      [
        '#type' => 'responsive_image',
        '#uri' => 'public://neo-twig-test.png',
        '#attributes' => [
          'class' => ['existing-image', 'added-image'],
          'alt' => 'An existing alt',
          'loading' => 'lazy',
        ],
      ],
      $variables['responsive_image'],
      'The image attributes are merged deeply into the responsive image element.'
    );
  }

  /**
   * Returns one hook's variables from the registry the site really builds.
   *
   * @param string $hook
   *   The theme hook to read.
   *
   * @return array
   *   The hook's registered variables, keyed by variable name.
   */
  private function registryVariables(string $hook): array {
    $registry = $this->container->get('theme.registry')->get();
    $this->assertArrayHasKey($hook, $registry, "The $hook theme hook is registered.");
    return $registry[$hook]['variables'];
  }

  /**
   * Creates the saved file entity the file-link hook is run against.
   *
   * @return \Drupal\file\FileInterface
   *   A saved file whose mime type and size the rebuilt link reports.
   */
  private function createFileEntity(): File {
    $file = File::create([
      'uri' => 'public://neo-twig-test.txt',
      'filename' => 'neo-twig-test.txt',
      'filemime' => 'text/plain',
      'filesize' => 42,
    ]);
    $file->save();
    return $file;
  }

}
