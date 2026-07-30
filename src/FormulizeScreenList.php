<?php

declare(strict_types=1);

namespace Drupal\formulize;

use Psr\Log\LoggerInterface;

/**
 * Supplies the list of Formulize screens available to pick from.
 */
class FormulizeScreenList {

  /**
   * Screen options, cached for the life of the request.
   *
   * Building the list boots Formulize and runs a query, and a node form or a block configuration form
   * can ask for it more than once.
   */
  private ?array $options = NULL;

  public function __construct(
    private readonly FormulizeBootstrap $bootstrap,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * All Formulize screens, as screen ID => label.
   *
   * @return array
   *   Screen IDs mapped to "Form title - Screen title" labels. Empty if Formulize is unreachable.
   */
  public function getOptions(): array {
    if ($this->options !== NULL) {
      return $this->options;
    }

    $options = $this->bootstrap->call(static function (): array {
      $screens = \Formulize::getScreens();
      if (!is_array($screens)) {
        return [];
      }
      // getScreens() appends a placeholder under key 0 when it finds nothing, but also whenever there
      // is no Formulize user in scope - which is common here, because the Drupal administrator editing
      // a node may have no mapped Formulize account. Screen IDs are auto increment, so 0 is never a
      // real one and dropping it is safe.
      unset($screens[0]);
      return $screens;
    }, []);

    if (!is_array($options)) {
      $options = [];
    }
    $this->options = $options;

    if ($options === [] && $this->bootstrap->isConfigured()) {
      $this->logger->warning('Formulize returned no screens. Either none have been created, or the Formulize database is not reachable.');
    }

    return $this->options;
  }

  /**
   * Whether a screen ID exists.
   */
  public function exists(int $screenId): bool {
    return $screenId > 0 && array_key_exists($screenId, $this->getOptions());
  }

  /**
   * A screen's label, or NULL if there is no such screen.
   */
  public function label(int $screenId): ?string {
    $options = $this->getOptions();
    return isset($options[$screenId]) ? (string) $options[$screenId] : NULL;
  }

}
