<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ipc_syncdb\ProductImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides the job type for importing product data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "syncdb_product_sync",
 *   label = @Translation("Sync DB Product Sync"),
 * )
 */
class SyncDbProductSync extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The product importer.
   *
   * @var \Drupal\ipc_syncdb\ProductImporter
   */
  protected $productImporter;

  /**
   * Constructs a new SyncDbProductImport object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\ipc_syncdb\ProductImporter $product_importer
   *   The product importer.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, ProductImporter $product_importer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->productImporter = $product_importer;
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
      $container->get('ipc_syncdb.product_importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $product_id = $job->getPayload()['product_id'];
    $result = $this->productImporter->importProduct($product_id);
    switch ($result) {
      case 'Saved':
        $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
        if (!$storage->loadByProperties(['syncdb_id' => $product_id])) {
          return JobResult::failure('Product not saved correctly.');
        }
        return JobResult::success();

      case 'Skipped':
        return JobResult::success();

      case 'Failed':
        return JobResult::failure('Product not saved.', 31, 86400);
    }
  }

}
