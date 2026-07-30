<?php

declare(strict_types=1);

namespace Drupal\formulize;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Works out where Formulize is on this server.
 *
 * Formulize has to be included into the Drupal request, so we need its filesystem path. Asking an
 * administrator for an absolute server path is often unreasonable - on shared hosting they may have
 * no way to find out what it is. So the single configured value may be either a filesystem path or
 * a URL, and we try progressively less direct ways of turning it into a path:
 *
 * 1. It already is a path pointing at a Formulize installation.
 * 2. It is a URL. Drupal knows both its own filesystem root and its own base path, so subtracting
 *    one from the other gives the document root the two systems share, and the URL's path can be
 *    appended to that.
 * 3. Neither worked, so walk up from Drupal's root looking for a Formulize installation, which finds
 *    the common case of Drupal living in a subfolder of Formulize.
 */
class FormulizePathResolver {

  use StringTranslationTrait;

  /**
   * State key holding the last successfully resolved absolute path.
   *
   * Kept in state rather than config so that an environment specific path is never written into an
   * exported configuration, while still being available to requests that cannot run strategy 2
   * (drush and cron have no base path to read).
   */
  public const STATE_KEY = 'formulize.resolved_path';

  /**
   * Files that must both be present for a directory to be a Formulize installation.
   *
   * integration_api.php is what actually gets included, so its absence would leave us with a path
   * that looks right and then fails.
   */
  private const REQUIRED_FILES = ['mainfile.php', 'integration_api.php'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Resolve the Formulize root directory.
   *
   * @return string|null
   *   The absolute path with no trailing slash, or NULL if it could not be found.
   */
  public function getPath(): ?string {
    $cached = $this->state->get(self::STATE_KEY);
    if (is_string($cached) && $cached !== '' && $this->isFormulizeRoot($cached)) {
      return $cached;
    }

    $result = $this->resolve((string) $this->configFactory->get('formulize.settings')->get('location'));
    if ($result['path'] !== NULL) {
      // Cache it so a CLI request, which cannot derive a path from a URL, still has one.
      $this->state->set(self::STATE_KEY, $result['path']);
      return $result['path'];
    }

    return NULL;
  }

  /**
   * Try to turn a configured location into a Formulize root directory.
   *
   * Does not touch state, so it is safe to call while validating a settings form.
   *
   * @param string $location
   *   Either a filesystem path or a URL.
   *
   * @return array
   *   An array with:
   *   - path: the resolved absolute path, or NULL.
   *   - strategy: which approach succeeded ('path', 'url', 'scan'), or NULL.
   *   - tried: a list of human readable notes about what was attempted.
   */
  public function resolve(string $location): array {
    $tried = [];
    $location = trim($location);

    if ($location === '') {
      return [
        'path' => NULL,
        'strategy' => NULL,
        'tried' => [(string) $this->t('No location has been configured.')],
      ];
    }

    // 1. An explicit filesystem path, either the directory itself or its mainfile.php.
    $candidate = $this->normalisePath($location);
    if ($candidate !== NULL) {
      if ($this->isFormulizeRoot($candidate)) {
        return ['path' => $candidate, 'strategy' => 'path', 'tried' => $tried];
      }
      $tried[] = (string) $this->t('Treated as a filesystem path: @path does not contain @files.', [
        '@path' => $candidate,
        '@files' => implode(' and ', self::REQUIRED_FILES),
      ]);
    }

    // 2. A URL, deduced against the document root Drupal and Formulize share.
    [$derived, $note] = $this->deriveFromUrl($location);
    if ($derived !== NULL) {
      return ['path' => $derived, 'strategy' => 'url', 'tried' => $tried];
    }
    if ($note !== NULL) {
      $tried[] = $note;
    }

    // 3. Look upwards from Drupal for an enclosing Formulize installation.
    $scanned = $this->scanAncestors();
    if ($scanned !== NULL) {
      return ['path' => $scanned, 'strategy' => 'scan', 'tried' => $tried];
    }
    $tried[] = (string) $this->t('Searched the directories above Drupal (@root) and found no Formulize installation.', [
      '@root' => $this->drupalRoot(),
    ]);

    return ['path' => NULL, 'strategy' => NULL, 'tried' => $tried];
  }

  /**
   * Describe a resolution strategy for an administrator.
   */
  public function describeStrategy(string $strategy, string $path): string {
    return match ($strategy) {
      'path' => (string) $this->t('Found Formulize at @path, using the path you entered.', ['@path' => $path]),
      'url' => (string) $this->t('Found Formulize at @path, worked out from the URL you entered and the location of Drupal on this server.', ['@path' => $path]),
      'scan' => (string) $this->t('Found Formulize at @path by looking in the directories above Drupal. The value you entered did not resolve on its own, so consider replacing it with this path.', ['@path' => $path]),
      default => (string) $this->t('Found Formulize at @path.', ['@path' => $path]),
    };
  }

  /**
   * Turn a value that looks like a filesystem path into a candidate directory.
   *
   * @return string|null
   *   The candidate directory, or NULL if the value cannot be a local path at all.
   */
  private function normalisePath(string $location): ?string {
    // A URL is not a path. Note a Windows drive letter ("C:\...") also matches a scheme pattern, so
    // test for that first.
    if (!preg_match('#^[a-zA-Z]:[\\\\/]#', $location) && preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
      return NULL;
    }

    $location = rtrim($location, '/\\');
    if ($location === '') {
      $location = '/';
    }

    // Accept the path to mainfile.php as well as the directory holding it, since the Drupal 7
    // module's setting was documented both ways.
    if (basename($location) === 'mainfile.php') {
      $location = dirname($location);
    }

    $real = realpath($location);
    return $real === FALSE ? NULL : $real;
  }

  /**
   * Derive the Formulize path from a URL, using where Drupal sits on this server.
   *
   * Drupal knows its own filesystem root and the base path it is served from. Removing the base path
   * from the end of the root leaves the document root, which Formulize must share for the two to run
   * in one PHP process. The URL's own path can then be appended to that.
   *
   * @return array
   *   A tuple of [resolved path or NULL, explanatory note or NULL].
   */
  private function deriveFromUrl(string $location): array {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return [NULL, (string) $this->t('Could not work the path out from a URL because there is no web request in progress.')];
    }

    // Accept a full URL or a root relative path such as "/" or "/formulize".
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
      $parts = parse_url($location);
      if ($parts === FALSE || !isset($parts['host'])) {
        return [NULL, (string) $this->t('Could not work the path out because @location is not a URL that can be parsed.', ['@location' => $location])];
      }
      $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
      if ($host !== strtolower($request->getHttpHost())) {
        return [
          NULL,
          (string) $this->t('Could not work the path out because @given is on a different host from Drupal (@drupal). Formulize must be on the same server, so enter its filesystem path instead.', [
            '@given' => $host,
            '@drupal' => $request->getHttpHost(),
          ]),
        ];
      }
      $urlPath = $parts['path'] ?? '';
    }
    elseif (str_starts_with($location, '/')) {
      $urlPath = $location;
    }
    else {
      return [NULL, NULL];
    }

    $urlPath = '/' . trim(str_replace('\\', '/', $urlPath), '/');
    $urlPath = $urlPath === '/' ? '' : $urlPath;

    $documentRoot = $this->sharedDocumentRoot($request->getBasePath());
    if ($documentRoot === NULL) {
      return [
        NULL,
        (string) $this->t("Could not work the path out because Drupal's base path (@base) is not the tail of its filesystem root (@root).", [
          '@base' => $request->getBasePath() === '' ? '/' : $request->getBasePath(),
          '@root' => $this->drupalRoot(),
        ]),
      ];
    }

    $candidate = realpath($documentRoot . $urlPath);
    if ($candidate !== FALSE && $this->isFormulizeRoot($candidate)) {
      return [$candidate, NULL];
    }

    return [
      NULL,
      (string) $this->t('Worked out @candidate from the URL, but it does not contain @files.', [
        '@candidate' => $candidate === FALSE ? $documentRoot . $urlPath : $candidate,
        '@files' => implode(' and ', self::REQUIRED_FILES),
      ]),
    ];
  }

  /**
   * Strip Drupal's base path off the end of its filesystem root to get the document root.
   *
   * @return string|null
   *   The document root with no trailing slash, or NULL if the two do not line up.
   */
  private function sharedDocumentRoot(string $basePath): ?string {
    $root = rtrim(str_replace('\\', '/', $this->drupalRoot()), '/');
    $basePath = trim(str_replace('\\', '/', $basePath), '/');

    if ($basePath === '') {
      // Drupal is served from the document root, so they are the same directory.
      return $root;
    }

    $suffix = '/' . $basePath;
    if (!str_ends_with($root, $suffix)) {
      return NULL;
    }

    $documentRoot = substr($root, 0, -strlen($suffix));
    return $documentRoot === '' ? '/' : $documentRoot;
  }

  /**
   * Look for a Formulize installation in the directories above Drupal.
   *
   * @return string|null
   *   The path found, or NULL.
   */
  private function scanAncestors(): ?string {
    $directory = rtrim(str_replace('\\', '/', $this->drupalRoot()), '/');
    // Bounded so a misconfiguration cannot walk the whole filesystem.
    for ($depth = 0; $depth < 8; $depth++) {
      $parent = dirname($directory);
      if ($parent === $directory || $parent === '.') {
        return NULL;
      }
      if ($this->isFormulizeRoot($parent)) {
        return $parent;
      }
      $directory = $parent;
    }
    return NULL;
  }

  /**
   * Whether a directory holds a Formulize installation.
   */
  public function isFormulizeRoot(string $directory): bool {
    if ($directory === '' || !is_dir($directory)) {
      return FALSE;
    }
    foreach (self::REQUIRED_FILES as $file) {
      if (!is_file($directory . '/' . $file)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Drupal's filesystem root.
   *
   * Wrapped so tests can subclass and point it somewhere else.
   */
  protected function drupalRoot(): string {
    return DRUPAL_ROOT;
  }

  /**
   * Forget the cached path, so the next lookup resolves afresh.
   */
  public function clearCache(): void {
    $this->state->delete(self::STATE_KEY);
  }

}
