<?php

declare(strict_types=1);

namespace Drupal\formulize\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\formulize\FormulizeBootstrap;
use Drupal\formulize\FormulizeUserSync;

/**
 * Reports the state of the Formulize connection on Drupal's status report.
 */
class FormulizeRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly FormulizeBootstrap $bootstrap,
    private readonly FormulizeUserSync $sync,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $root = $this->bootstrap->getRoot();

    if ($root === NULL) {
      return [
        'formulize' => [
          'title' => $this->t('Formulize'),
          'value' => $this->t('Not found'),
          'description' => $this->t('Formulize could not be located. Set its path or URL on the Formulize settings page.'),
          'severity' => RequirementSeverity::Error,
        ],
      ];
    }

    if (!$this->bootstrap->boot()) {
      return [
        'formulize' => [
          'title' => $this->t('Formulize'),
          'value' => $this->t('Could not be loaded'),
          'description' => $this->t('Formulize was found at %path but failed to load. Check the site log.', ['%path' => $root]),
          'severity' => RequirementSeverity::Error,
        ],
      ];
    }

    $requirements = [
      'formulize' => [
        'title' => $this->t('Formulize'),
        'value' => $this->t('Loaded from %path', ['%path' => $root]),
        'severity' => RequirementSeverity::OK,
      ],
    ];

    $screens = $this->sync->screenCount();
    $requirements['formulize']['description'] = $this->t('Serving from @url. @count screens are available.', [
      '@url' => $this->bootstrap->getFormulizeUrl(),
      '@count' => $screens ?? 0,
    ]);

    // Without the mapping table there is nothing to tie a Drupal user to a Formulize one, so single
    // sign-on silently does nothing. The table is part of Formulize's own schema, not this module's.
    if (!$this->sync->mappingTableExists()) {
      $requirements['formulize_mapping'] = [
        'title' => $this->t('Formulize user mapping'),
        'value' => $this->t('Missing'),
        'description' => $this->t("Formulize's resource mapping table was not found. Single sign-on cannot work without it. It is created by Formulize's own installer, so check that the Formulize installation is complete and its database patches have been applied."),
        'severity' => RequirementSeverity::Error,
      ];
    }
    else {
      $requirements['formulize_mapping'] = [
        'title' => $this->t('Formulize user mapping'),
        'value' => $this->t('Present'),
        'severity' => RequirementSeverity::OK,
      ];
    }

    return $requirements;
  }

}
