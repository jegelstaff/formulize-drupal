<?php

declare(strict_types=1);

namespace Drupal\formulize;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Loads Formulize into the current Drupal request.
 *
 * Both systems end up active in one PHP process. The handshake is a global: Formulize's session
 * handler looks for $GLOBALS['formulizeHostSystemUserId'] and, when it is set, treats the host system
 * as authoritative for who the user is - mapping that ID through Formulize's resource mapping table
 * and building a session without asking for a password. Set but empty means log out.
 *
 * Two things about that are load bearing:
 *
 * - The global must be in place before mainfile.php is included, because Formulize resolves it while
 *   its session service boots and never looks again.
 * - Booting is therefore deliberately lazy. Formulize's boot is a full CMS startup with database and
 *   session writes, far too expensive to do on every Drupal request, and because we always set the
 *   global immediately before including anything, being lazy costs us nothing.
 *
 * @see \libraries\icms\core\Session::service()
 */
class FormulizeBootstrap {

  /**
   * NULL until the first attempt, then whether Formulize is loaded.
   */
  private ?bool $loaded = NULL;

  /**
   * The Formulize root, once known.
   */
  private ?string $root = NULL;

  public function __construct(
    private readonly FormulizePathResolver $pathResolver,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Load Formulize, if it is not loaded already.
   *
   * @return bool
   *   TRUE if Formulize is available for use.
   */
  public function boot(): bool {
    if ($this->loaded !== NULL) {
      return $this->loaded;
    }
    $this->loaded = FALSE;

    $root = $this->pathResolver->getPath();
    if ($root === NULL) {
      $this->logger->error('Could not load Formulize because its location has not been configured, or the configured location is wrong. Check the Formulize settings page.');
      return FALSE;
    }
    $this->root = $root;

    // Formulize's own code triggers warnings here that are expected rather than wrong: under Drupal
    // the PHP session is already running, so ICMS's session_set_save_handler(), session_name() and
    // session_start() calls all fail by design, and it compensates by reading and writing its session
    // rows explicitly. Left alone, Drupal's error handler would print those onto the page. Send them
    // to our log channel instead so they stay visible without wrecking the response.
    $captured = [];
    $previousHandler = set_error_handler($this->diagnosticHandler($captured));
    ob_start();

    try {
      $this->setGlobals();
      require_once $root . '/integration_api.php';
      if (!class_exists('Formulize', FALSE)) {
        throw new \RuntimeException('integration_api.php was included but did not define the Formulize class.');
      }
      // Loads mainfile.php, which boots ICMS and establishes the Formulize session.
      \Formulize::init();
      $this->loaded = TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to load Formulize from @root: @message', [
        '@root' => $root,
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      $stray = ob_get_clean();
      $this->restoreErrorHandler($previousHandler);
    }

    if ($stray !== '' && $stray !== FALSE) {
      $this->logger->warning('Formulize produced unexpected output while loading, which has been discarded: @output', [
        '@output' => mb_substr($stray, 0, 2000),
      ]);
    }
    foreach ($captured as $notice) {
      $this->logger->info('PHP diagnostic while loading Formulize: @notice', ['@notice' => $notice]);
    }

    return $this->loaded;
  }

  /**
   * An error handler that collects diagnostics instead of letting them reach the page.
   *
   * @param array $captured
   *   Collected by reference.
   */
  private function diagnosticHandler(array &$captured): callable {
    return static function (int $severity, string $message, string $file = '', int $line = 0) use (&$captured): bool {
      if (!(error_reporting() & $severity)) {
        return TRUE;
      }
      $captured[] = sprintf('%s in %s line %d', $message, $file, $line);
      return TRUE;
    };
  }

  /**
   * Put Drupal's error handler back after Formulize has run.
   *
   * restore_error_handler() alone is not enough. ICMS installs an error handler of its own while
   * booting, so the handler stack is [Drupal, ours, ICMS] by the time we unwind: one restore pops
   * ICMS and leaves ours in place, which would then silently swallow Drupal's errors for the rest of
   * the request. Reinstating the handler we displaced explicitly avoids depending on the depth.
   *
   * @param callable|null $previous
   *   Whatever set_error_handler() returned when we installed ours.
   */
  private function restoreErrorHandler(?callable $previous): void {
    restore_error_handler();
    if ($previous !== NULL) {
      set_error_handler($previous);
    }
  }

  /**
   * Set the globals Formulize reads to identify the user and to link back to Drupal.
   */
  private function setGlobals(): void {
    $uid = (int) $this->currentUser->id();
    $authoritative = (bool) $this->configFactory->get('formulize.settings')->get('authoritative_session');

    if ($uid > 0) {
      $GLOBALS['formulizeHostSystemUserId'] = $uid;
    }
    elseif ($authoritative) {
      // Zero tells Formulize to tear its session down, which is what single sign-on means: no Drupal
      // user, no Formulize user. Leaving the global unset instead lets a session made by logging into
      // Formulize directly survive, which is friendlier when both systems are browsed side by side.
      $GLOBALS['formulizeHostSystemUserId'] = 0;
    }

    // Lets Formulize offer a link to Drupal's login form when a visitor lacks permission for a screen,
    // in place of the redirect it would use when running standalone.
    $GLOBALS['formulizeHostSystemLoginUrl'] = $this->loginUrl();
  }

  /**
   * Drupal's login URL, returning the visitor to where they were.
   */
  private function loginUrl(): string {
    try {
      $options = [];
      $current = \Drupal::request()->getRequestUri();
      if (is_string($current) && $current !== '') {
        $options['query']['destination'] = $current;
      }
      return Url::fromRoute('user.login', [], $options)->toString();
    }
    catch (\Throwable) {
      return '/user/login';
    }
  }

  /**
   * Whether Formulize can be loaded, without loading it.
   */
  public function isConfigured(): bool {
    return $this->pathResolver->getPath() !== NULL;
  }

  /**
   * The Formulize root directory, or NULL if it has not been resolved.
   */
  public function getRoot(): ?string {
    return $this->root ?? $this->pathResolver->getPath();
  }

  /**
   * Formulize's own base URL, which is what its markup and AJAX endpoints refer to.
   *
   * Only meaningful once Formulize has been loaded, since the constant is defined by mainfile.php.
   */
  public function getFormulizeUrl(): string {
    return defined('XOOPS_URL') ? (string) constant('XOOPS_URL') : '';
  }

  /**
   * Run a callback with Formulize loaded, keeping its output and diagnostics off the Drupal page.
   *
   * Formulize prints rather than returns in places, and legacy code paths emit notices, so anything
   * calling into it needs buffering. Centralised here so every caller behaves the same way.
   *
   * @param callable $callback
   *   Receives no arguments; its return value is passed back.
   * @param mixed $fallback
   *   Returned if Formulize is unavailable or the callback throws.
   *
   * @return mixed
   *   The callback's return value, or $fallback.
   */
  public function call(callable $callback, mixed $fallback = NULL): mixed {
    if (!$this->boot()) {
      return $fallback;
    }

    $captured = [];
    $previousHandler = set_error_handler($this->diagnosticHandler($captured));
    ob_start();

    $result = $fallback;
    try {
      $result = $callback();
    }
    catch (\Throwable $e) {
      $this->logger->error('Formulize raised @class while running: @message in @file line @line', [
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
        '@file' => $e->getFile(),
        '@line' => $e->getLine(),
      ]);
      $result = $fallback;
    }
    finally {
      $stray = ob_get_clean();
      $this->restoreErrorHandler($previousHandler);
    }

    if ($stray !== '' && $stray !== FALSE) {
      $this->logger->notice('Discarded stray output from Formulize: @output', [
        '@output' => mb_substr($stray, 0, 2000),
      ]);
    }
    foreach ($captured as $notice) {
      $this->logger->info('PHP diagnostic from Formulize: @notice', ['@notice' => $notice]);
    }

    return $result;
  }

}
