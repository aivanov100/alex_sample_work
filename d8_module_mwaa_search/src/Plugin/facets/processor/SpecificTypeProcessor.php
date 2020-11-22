<?php

namespace Drupal\mwaa_search\Plugin\facets\processor;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\BuildProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Transforms the results to show the label as a field value.
 *
 * @FacetsProcessor(
 *  id = "specific_type_processor",
 *  label = @Translation("Specific Type Processor"),
 *  description = @Translation("Transform field_type results to news_type, event_type, etc."),
 *  stages = {
 *    "build" = 40
 *  }
 * )
 */
class SpecificTypeProcessor extends ProcessorPluginBase implements BuildProcessorInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request stack object.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a SpecificTypeProcessor object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet, array $results) {
    $conflicting_query_params = [
      'contracting-opportunity',
      'real-estate-type',
      'event-type',
      'news-type',
      'publication-topic',
    ];

    // For target facets, set associated vocabulary.
    switch ($facet->id()) {
      case 'contracting_opportunity_facet':
        $vocabulary = 'contracting_opportunity_type';
        $query_param = 'contracting-opportunity';
        break;

      case 'real_estate_type_facet':
        $vocabulary = 'real_estate_type';
        $query_param = 'real-estate-type';
        break;

      case 'event_type_facet':
        $vocabulary = 'event_type';
        $query_param = 'event-type';
        break;

      case 'news_type_facet':
        $vocabulary = 'news_type';
        $query_param = 'news-type';
        break;

      case 'publication_type_facet':
        $vocabulary = 'publication_type';
        $query_param = 'publication-topic';
        break;

      default:
        return $results;
    }

    // Return no results if search query contains one of the sister facets'
    // search parameters.
    unset($conflicting_query_params[array_search($query_param, $conflicting_query_params)]);
    $url_query_string = $this->requestStack->getCurrentRequest()->getQueryString();
    foreach ($conflicting_query_params as $param) {
      if (strpos($url_query_string, $param) !== FALSE) {
        return [];
      }
    }

    // Remove results not matching this facet's associated vocabulary.
    foreach ($results as $i => $result) {
      $tid = $result->getDisplayValue();
      if ($term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid)) {
        if ($term->bundle() !== $vocabulary) {
          unset($results[$i]);
        }
        $result->setDisplayValue($term->getName());
      }
    }

    return $results;
  }

}
