<?php

namespace Drupal\mwaa_search\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds the first letter of the item's title to the indexed data.
 *
 * @SearchApiProcessor(
 *   id = "mwaa_title_starts_with",
 *   label = @Translation("Title Starts With"),
 *   description = @Translation("Adds the first letter of the item's title to the indexed data."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class TitleStartsWith extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Title Starts With'),
        'description' => $this->t('The first letter of the item title.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['mwaa_title_starts_with'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $label = $item->getDatasource()->getItemLabel($item->getOriginalObject());
    if ($label) {
      $first_letter = strtolower(substr($label, 0, 1));
      $fields = $item->getFields(FALSE);
      $fields = $this->getFieldsHelper()
        ->filterForPropertyPath($fields, NULL, 'mwaa_title_starts_with');
      foreach ($fields as $field) {
        $field->addValue($first_letter);
      }
    }
  }

}
