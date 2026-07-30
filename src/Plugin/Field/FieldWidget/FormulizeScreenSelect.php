<?php

declare(strict_types=1);

namespace Drupal\formulize\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\formulize\FormulizeScreenList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chooses a Formulize screen from a list of the screens that exist.
 */
#[FieldWidget(
  id: 'formulize_screen_select',
  label: new TranslatableMarkup('Formulize screen list'),
  field_types: ['formulize_screen'],
)]
class FormulizeScreenSelect extends WidgetBase implements ContainerFactoryPluginInterface {

  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    protected readonly FormulizeScreenList $screenList,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get(FormulizeScreenList::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $current = $items[$delta]->value ?? NULL;
    $current = $current === NULL ? NULL : (int) $current;
    $options = $this->screenList->getOptions();

    if ($options === []) {
      // Fall back to entering the ID by hand rather than blocking the form. Formulize may simply be
      // unreachable from this request, and an editor should still be able to save the rest of the node.
      $element['value'] = [
        '#type' => 'number',
        '#title' => $this->t('Formulize screen ID'),
        '#default_value' => $current,
        '#min' => 1,
        '#description' => $this->t('The list of screens could not be loaded from Formulize, so the screen ID has to be entered directly. Check the Formulize settings and the site log.'),
      ];
      return $element;
    }

    // Keep an unrecognised saved value selectable, so opening and saving a node cannot silently
    // discard a screen that exists but is not currently listed.
    if ($current !== NULL && $current > 0 && !isset($options[$current])) {
      $options[$current] = $this->t('Screen @sid (no longer listed in Formulize)', ['@sid' => $current]);
    }

    $element['value'] = [
      '#type' => 'select',
      '#title' => $this->t('Formulize screen'),
      '#description' => $this->t('The screen to display. Its content and who may see it are governed by Formulize itself.'),
      '#options' => $options,
      '#default_value' => $current,
      '#empty_option' => $this->t('- Select a screen -'),
      '#empty_value' => '',
      '#required' => $element['#required'] ?? FALSE,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as $delta => $value) {
      if (!isset($value['value']) || $value['value'] === '' || (int) $value['value'] <= 0) {
        $values[$delta]['value'] = NULL;
        continue;
      }
      $values[$delta]['value'] = (int) $value['value'];
    }
    return $values;
  }

}
