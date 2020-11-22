<?php

namespace Drupal\mwaa_search\Plugin\search_api\processor;

use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds Natural Sort field to Search API Index.
 *
 * @SearchApiProcessor(
 *   id = "mwaa_search_site_identifier",
 *   label = @Translation("MWAA Search Site Identifier"),
 *   description = @Translation("Adds Site Identifier field to the index"),
 *   stages = {
 *     "add_properties" = 1,
 *     "pre_index_save" = -5,
 *     "preprocess_index" = -20
 *   }
 * )
 */
class SiteIdentifierSearchProcessor extends ProcessorPluginBase implements PluginFormInterface {

  /**
   * The request service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $processor->setRequestStack($container->get('request_stack'));

    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $configuration = parent::defaultConfiguration();

    $configuration += [
      'default_config' => [
        'mwaa:MWAA',
        'flydulles:Fly Dulles',
        'flyreagan:Fly Reagan',
        'dullestollroad:Dulles Tull Road',
      ],
    ];

    return $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    if (!empty($id_mappings = $this->getConfiguration()['id_mappings'])) {
      $mappings_config = '';
      foreach ($id_mappings as $mapping_key => $mapping_value) {
        $mappings_config .= $mapping_key . ":" . $mapping_value . "\n";
      }
      $config_default = rtrim($mappings_config);
    }
    else {
      $config_default = implode("\n", $this->getConfiguration()['default_config']);
    }
    $form['site_id_config'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Site Identifiers and Corresponding Display Text'),
      '#description' => $this->t('Specify site identifiers and their display text. Enter one key/value pair per line, in the format identifier:display_text.'),
      '#default_value' => $config_default,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $site_id_config = rtrim($form_state->getValues()['site_id_config']);
    $input_strings = preg_split('/\r\n|\r|\n/', $site_id_config);
    foreach ($input_strings as $input_line) {
      if (!preg_match('/\w+:\w+(\s\w+)*/', $input_line)) {
        $el = $form['site_id_config'];
        $form_state->setError($el, $this->t('Search Site Identifier - The entered text is not in the valid format.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $site_id_config = rtrim($form_state->getValues()['site_id_config']);
    $input_strings = preg_split('/\r\n|\r|\n/', $site_id_config);
    $id_mappings = [];
    foreach ($input_strings as $input_line) {
      $key_value = explode(":", $input_line);
      $id_mappings[$key_value[0]] = $key_value[1];
    }
    $this->setConfiguration(['id_mappings' => $id_mappings]);
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      // Ensure that our fields are defined.
      $fields = $this->getFieldsDefinition();

      foreach ($fields as $field_id => $field_definition) {
        $properties[$field_id] = new DataDefinition($field_definition);
      }
    }
    return $properties;
  }

  /**
   * Helper function for defining our custom fields.
   */
  protected function getFieldsDefinition() {
    $fields['site_id_field'] = [
      'label' => $this->t('Site Identifier'),
      'description' => $this->t('Site-specific identifier field to differentiate sites in multisite installation'),
      'type' => 'string',
      'processor_id' => $this->getPluginId(),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIndexItems(array $items) {
    foreach ($items as $item) {
      // Find target index fields matching our defined field name.
      $site_id_fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(), NULL, 'site_id_field');
      // Get host name for current request.
      $host = $this->requestStack->getCurrentRequest()->getHost();
      if (!empty($id_mappings = $this->getConfiguration()['id_mappings'])) {
        // Loop through all target index fields.
        foreach ($site_id_fields as $site_id_field) {
          // Find a mapping matching current host name.
          foreach ($id_mappings as $mapping_key => $mapping_value) {
            // Set field value to display text value for current mapping.
            if (strpos($host, $mapping_key) !== FALSE) {
              $site_id_field->addValue($mapping_value);
              break;
            }
          }
        }
      }
    }
  }

  /**
   * Sets the request object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request object.
   */
  public function setRequestStack(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

}
