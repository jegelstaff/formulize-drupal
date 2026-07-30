<?php

declare(strict_types=1);

namespace Drupal\formulize;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Turns a Formulize screen into a Drupal render array.
 *
 * Used by both the field formatter and the block plugin so a screen behaves identically wherever it
 * is placed.
 */
class FormulizeScreenRenderer {

  use StringTranslationTrait;

  /**
   * Assets that come back from Formulize but must not be added to a Drupal page.
   *
   * icms.css and formulize.css are superseded by drupal.css, which is the same styles rewritten so
   * they only apply inside the screen's own div. Adding them here as well would duplicate every rule
   * and let their bare element selectors (body, button, table, td) restyle the whole Drupal page.
   * formulize.js is already injected into the screen markup by Formulize itself.
   *
   * @see \modules\formulize\templates\css\build-drupal-css.php
   */
  private const SKIP_ASSETS = [
    'icms.css',
    'icms_rtl.css',
    'formulize.css',
    'formulize.js',
    // Formulize's own jQuery stack is not loaded here. Drupal already provides jQuery, and having two
    // on one page is what required the reassert hack this replaced. Formulize's front-side code uses
    // only APIs present in jQuery 1.7 through 4.x, so Drupal's copy runs it unchanged. jQuery UI comes
    // from the formulize/screen library instead, at a version that supports jQuery 4.
    'jquery.min.js',
    'jquery.js',
    'jquery-migrate-1.2.1.min.js',
    'jquery-ui.min.js',
    'jquery-ui.css',
    // colorbox is dead weight: nothing calls .colorbox(), and its bundled 2012 copy needs .live().
    'jquery.colorbox-min.js',
    'colorbox.css',
    // helptip.js is the only harvested script that needs jQuery, and html_head always renders ahead of
    // Drupal's asset placeholders, so it would run before jQuery exists and throw. It is also dead on a
    // screen page: it binds img.helptip and input.checkemall, neither of which Formulize generates any
    // more. Standalone Formulize still loads it from header.php, and the ICMS control panel from
    // cp_functions.php, so nothing else changes.
    'helptip.js',
  ];

  /**
   * Assets a live Formulize page loads from its theme template rather than by registering them.
   *
   * These are plain markup in themes/Anari/theme.html, so they are not on the theme object and cannot
   * be harvested. The scoped stylesheet refers to both, so they have to be added by hand.
   */
  private const THEME_FONTS = [
    'poppins' => [
      '#tag' => 'link',
      '#attributes' => [
        'rel' => 'stylesheet',
        'href' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap',
      ],
    ],
    'fontawesome' => [
      '#tag' => 'script',
      '#value' => '',
      '#attributes' => [
        'src' => 'https://use.fontawesome.com/releases/v5.3.1/js/all.js',
        'defer' => 'defer',
      ],
    ],
  ];

  /**
   * The screen already rendered in this request, if any.
   *
   * Formulize is not re-entrant. A render includes header.php and modules/formulize/index.php, and
   * several of the includes below them are include_once guarded, so a second render in the same PHP
   * process does not repeat the setup it needs. Worse, header.php ends with
   * "if ($xoTheme->checkCache()) exit();" and by the second pass the Formulize module has been
   * launched, so the content cache check becomes live and can exit() partway through composing the
   * Drupal response. So we allow one screen per request and refuse the rest, rather than risk a
   * truncated page.
   */
  private ?int $rendered = NULL;

  public function __construct(
    private readonly FormulizeBootstrap $bootstrap,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Build a render array for a Formulize screen.
   *
   * @param int $screenId
   *   The Formulize screen ID.
   *
   * @return array
   *   A render array. Never cacheable: rendering a screen also processes any form submission posted
   *   to this URL, and the output depends on the Formulize user and their permissions.
   */
  public function render(int $screenId): array {
    $build = ['#cache' => ['max-age' => 0]];

    if ($screenId <= 0) {
      return $build + $this->message($this->t('No Formulize screen has been chosen.'), TRUE);
    }

    if ($this->rendered !== NULL) {
      $this->logger->warning('Screen @sid was not rendered because screen @first is already on this page. Formulize can only render one screen per request.', [
        '@sid' => $screenId,
        '@first' => $this->rendered,
      ]);
      return $build + $this->message($this->t('Only one Formulize screen can appear on a page. Screen @first is already shown here, so screen @sid was left out.', [
        '@first' => $this->rendered,
        '@sid' => $screenId,
      ]), TRUE);
    }

    if (!$this->bootstrap->boot()) {
      return $build + $this->unavailable();
    }

    // Check the screen exists before rendering it. Formulize's own handling of an unknown screen ends
    // in a message we cannot control, and historically in a redirect, so failing here gives a clearer
    // result.
    if (!$this->screenExists($screenId)) {
      $this->logger->warning('Formulize screen @sid was requested but does not exist.', ['@sid' => $screenId]);
      return $build + $this->message($this->t('The Formulize screen that should appear here (screen @sid) no longer exists.', ['@sid' => $screenId]), TRUE);
    }

    // Claim the request before calling in, so a failure part way through still blocks a second
    // attempt rather than letting it run the unsafe path.
    $this->rendered = $screenId;

    $screen = $this->bootstrap->call(static fn(): array => \Formulize::getScreenHtml($screenId), []);

    if (!is_array($screen) || !isset($screen['html'])) {
      return $build + $this->unavailable();
    }

    // Markup::create() is required, not cosmetic. Anything in #markup that is not already a
    // MarkupInterface is run through Xss::filter() with the admin tag list, which strips <script> -
    // and a Formulize screen is largely inline script (its FORMULIZE settings object, form action
    // assembly, calendar setup). Screen markup is trusted, exactly as it was under Drupal 7.
    $build['#markup'] = Markup::create((string) $screen['html']);
    $build['#attached']['html_head'] = $this->headElements($screen['assets'] ?? []);

    if ($this->configFactory->get('formulize.settings')->get('attach_js_assets')) {
      // Drupal's own jQuery runs the screen. Formulize's front-side code is written against APIs that
      // exist in every jQuery from 1.7 to 4.x, so there is no version to reconcile and nothing to
      // reassert - the page has exactly one jQuery. This library only adds jQuery UI, which Drupal 11
      // does not ship, ordered after core/jquery by its dependency.
      $build['#attached']['library'][] = 'formulize/screen';
    }

    return $build;
  }

  /**
   * Whether a Formulize screen ID resolves to a real screen.
   */
  private function screenExists(int $screenId): bool {
    return (bool) $this->bootstrap->call(static function () use ($screenId): bool {
      $handler = xoops_getmodulehandler('screen', 'formulize');
      if (!is_object($handler)) {
        return FALSE;
      }
      $screen = $handler->get($screenId);
      return is_object($screen) && (int) $screen->getVar('sid') === $screenId;
    }, FALSE);
  }

  /**
   * Convert the assets Formulize registered into Drupal html_head entries.
   *
   * Formulize declares its own assets in header.php and its module index, then relies on its theme
   * footer to output them. An embedded render skips the footer, so they come back to us instead. Using
   * that list rather than a hardcoded one means this module always loads the same jQuery and jQuery UI
   * versions as the rest of Formulize, and picks up anything added to header.php later.
   *
   * @param array $assets
   *   The 'assets' array from Formulize::getScreenHtml().
   *
   * @return array
   *   A list of [render array, key] pairs for #attached['html_head'].
   */
  private function headElements(array $assets): array {
    $settings = $this->configFactory->get('formulize.settings');
    $elements = [];
    $index = 0;

    // Stylesheets, then links, then scripts, so styles are in place before scripts run.
    foreach (($assets['stylesheet'] ?? []) as $attributes) {
      $element = $this->assetElement('stylesheet', $attributes);
      if ($element !== NULL) {
        $elements[] = [$element, 'formulize_css_' . $index++];
      }
    }

    // The scoped stylesheet. This is the one that actually dresses the screen: the same styles a live
    // Formulize page uses, rewritten so every selector sits under the screen's #formulize_form div.
    $scoped = $this->scopedStylesheetUrl();
    if ($scoped !== NULL) {
      $elements[] = [
        [
          '#tag' => 'link',
          '#attributes' => ['rel' => 'stylesheet', 'href' => $scoped, 'media' => 'all'],
        ],
        'formulize_scoped_css',
      ];
    }

    if ($settings->get('load_theme_fonts')) {
      foreach (self::THEME_FONTS as $key => $element) {
        $elements[] = [$element, 'formulize_font_' . $key];
      }
    }

    foreach (($assets['link'] ?? []) as $attributes) {
      $element = $this->assetElement('link', $attributes);
      if ($element !== NULL) {
        $elements[] = [$element, 'formulize_link_' . $index++];
      }
    }

    if ($settings->get('attach_js_assets')) {
      foreach (($assets['script'] ?? []) as $attributes) {
        $element = $this->assetElement('script', $attributes);
        if ($element !== NULL) {
          $elements[] = [$element, 'formulize_js_' . $index++];
        }
      }

      foreach (($assets['head'] ?? []) as $raw) {
        if (is_string($raw) && trim($raw) !== '') {
          $elements[] = [
            ['#type' => 'inline_template', '#template' => '{{ head|raw }}', '#context' => ['head' => $raw]],
            'formulize_head_' . $index++,
          ];
        }
      }
    }

    return $elements;
  }

  /**
   * Build one html_head element from a harvested asset's attributes.
   *
   * @param string $type
   *   'stylesheet', 'script' or 'link'.
   * @param array $attributes
   *   HTML attributes, with inline code under 'content'.
   *
   * @return array|null
   *   A render array, or NULL if this asset should be skipped.
   */
  private function assetElement(string $type, array $attributes): ?array {
    if (!is_array($attributes)) {
      return NULL;
    }

    $content = $attributes['content'] ?? NULL;
    unset($attributes['content']);

    $reference = $attributes['src'] ?? $attributes['href'] ?? NULL;
    if (is_string($reference) && $this->isSkipped($reference)) {
      return NULL;
    }

    if ($reference === NULL) {
      // Inline code rather than a file reference.
      if (!is_string($content) || trim($content) === '') {
        return NULL;
      }
      return [
        '#tag' => $type === 'script' ? 'script' : 'style',
        '#value' => Markup::create($content),
        '#attributes' => $attributes,
      ];
    }

    if ($type === 'stylesheet') {
      $attributes['rel'] = 'stylesheet';
      unset($attributes['type']);
      return ['#tag' => 'link', '#attributes' => $attributes];
    }
    if ($type === 'link') {
      return ['#tag' => 'link', '#attributes' => $attributes];
    }
    return ['#tag' => 'script', '#value' => '', '#attributes' => $attributes];
  }

  /**
   * Whether an asset URL is one we deliberately leave out.
   */
  private function isSkipped(string $reference): bool {
    $file = strtolower(basename(parse_url($reference, PHP_URL_PATH) ?: $reference));
    foreach (self::SKIP_ASSETS as $skip) {
      if ($file === $skip) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * URL of the generated, screen-scoped stylesheet, with a cache busting version.
   *
   * The stylesheet is per theme and generated on demand. Formulize's generator records the modification
   * time of every source it was built from, so this asks whether those have moved on and rebuilds only
   * when they have. That means a new Formulize theme, or an edit to the active theme's stylesheets, is
   * picked up with no manual step and no rebuild on the requests in between.
   *
   * Regeneration is best effort. If the cache directory is not writable - a reasonable thing for a
   * deployment to enforce - the existing file is served and the reason is logged, rather than failing
   * the page.
   */
  private function scopedStylesheetUrl(): ?string {
    $base = $this->bootstrap->getFormulizeUrl();
    if ($base === '') {
      return NULL;
    }

    $built = $this->bootstrap->call(static function (): ?array {
      require_once XOOPS_ROOT_PATH . '/modules/formulize/templates/css/build-drupal-css.php';
      $root = formulize_drupalCssRoot();
      if (!$root) {
        return NULL;
      }
      $theme = formulize_drupalCssActiveTheme($root);
      if (!$theme) {
        return NULL;
      }
      $result = ['theme' => $theme, 'rebuilt' => FALSE, 'error' => NULL];
      if (formulize_drupalCssIsStale($root, $theme)) {
        $build = formulize_buildDrupalCss($root, $theme);
        $result['rebuilt'] = $build['ok'];
        $result['error'] = $build['ok'] ? NULL : $build['message'];
      }
      $result['exists'] = is_file(formulize_drupalCssPath($root, $theme));
      return $result;
    });

    if ($built === NULL) {
      return NULL;
    }
    if ($built['error'] !== NULL) {
      $this->logger->warning('Could not regenerate the screen stylesheet for theme @theme: @why', [
        '@theme' => $built['theme'],
        '@why' => $built['error'],
      ]);
    }
    if (empty($built['exists'])) {
      return NULL;
    }

    $path = '/modules/formulize/cache/drupal-' . preg_replace('/[^A-Za-z0-9_.-]/', '', $built['theme']) . '.css';
    $version = $this->bootstrap->call(static function () use ($path) {
      return function_exists('formulize_get_file_version') ? formulize_get_file_version($path) : NULL;
    });
    return $base . $path . ($version ? '?v=' . rawurlencode((string) $version) : '');
  }

  /**
   * Message shown when Formulize cannot be reached at all.
   */
  private function unavailable(): array {
    $build = $this->message($this->t('This section is currently unavailable.'));

    if ($this->currentUser->hasPermission('administer formulize')) {
      $build['message']['#suffix'] = ' ' . $this->t('Formulize could not be loaded. Check the <a href=":url">Formulize settings</a> and the site log.', [
        ':url' => Url::fromRoute('formulize.settings')->toString(),
      ]);
    }

    return $build;
  }

  /**
   * A simple message render array.
   *
   * @param mixed $text
   *   The message.
   * @param bool $adminOnly
   *   TRUE to show it only to users who can administer Formulize, since it describes a configuration
   *   problem that would mean nothing to a visitor.
   */
  private function message(mixed $text, bool $adminOnly = FALSE): array {
    if ($adminOnly && !$this->currentUser->hasPermission('administer formulize')) {
      return [];
    }
    return [
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['formulize-screen-message']],
        '#value' => $text,
      ],
    ];
  }

}
