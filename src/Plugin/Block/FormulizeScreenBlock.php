<?php

declare(strict_types=1);

namespace Drupal\formulize\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\formulize\FormulizeScreenList;
use Drupal\formulize\FormulizeScreenRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Places a Formulize screen in a region.
 */
#[Block(
  id: 'formulize_screen',
  admin_label: new TranslatableMarkup('Formulize screen'),
  category: new TranslatableMarkup('Formulize'),
)]
class FormulizeScreenBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly FormulizeScreenRenderer $screenRenderer,
    protected readonly FormulizeScreenList $screenList,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(FormulizeScreenRenderer::class),
      $container->get(FormulizeScreenList::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['screen_id' => 0];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $options = $this->screenList->getOptions();
    $current = (int) $this->configuration['screen_id'];

    if ($options === []) {
      $form['screen_id'] = [
        '#type' => 'number',
        '#title' => $this->t('Formulize screen ID'),
        '#default_value' => $current ?: NULL,
        '#min' => 1,
        '#required' => TRUE,
        '#description' => $this->t('The list of screens could not be loaded from Formulize, so the screen ID has to be entered directly.'),
      ];
      return $form;
    }

    if ($current > 0 && !isset($options[$current])) {
      $options[$current] = $this->t('Screen @sid (no longer listed in Formulize)', ['@sid' => $current]);
    }

    $form['screen_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Formulize screen'),
      '#options' => $options,
      '#default_value' => $current ?: NULL,
      '#empty_option' => $this->t('- Select a screen -'),
      '#empty_value' => '',
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['screen_id'] = (int) $form_state->getValue('screen_id');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->screenRenderer->render((int) $this->configuration['screen_id']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    // A screen also processes form submissions posted to the current URL, and its content depends on
    // the viewing user's Formulize permissions, so it can never be cached.
    return 0;
  }

}
