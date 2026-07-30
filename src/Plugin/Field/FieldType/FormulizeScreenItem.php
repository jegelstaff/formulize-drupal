<?php

declare(strict_types=1);

namespace Drupal\formulize\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A reference to a screen in Formulize.
 *
 * Holding the screen in a field rather than in a table of the module's own means any entity type can
 * carry one, and revisions, translations and Views support come from the Field API.
 */
#[FieldType(
  id: 'formulize_screen',
  label: new TranslatableMarkup('Formulize screen'),
  description: new TranslatableMarkup('A screen from Formulize, rendered in place wherever this field is displayed.'),
  category: 'formulize',
  default_widget: 'formulize_screen_select',
  default_formatter: 'formulize_screen',
)]
class FormulizeScreenItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['value'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Screen ID'))
      ->setDescription(new TranslatableMarkup("The screen's ID in Formulize."))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'value' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
      ],
      'indexes' => [
        'value' => ['value'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $value = $this->get('value')->getValue();
    return $value === NULL || $value === '' || (int) $value <= 0;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {
    return ['value' => mt_rand(1, 20)];
  }

}
