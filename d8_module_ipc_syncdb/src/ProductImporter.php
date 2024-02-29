<?php

namespace Drupal\ipc_syncdb;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ipcsync\Utilities\ProductSync;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Messenger\MessengerInterface;
use Psr\Log\LoggerInterface;
use Drupal\advancedqueue\Job;
use Drupal\commerce_pricelist\Entity\PriceListItem;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Imports products from Sync DB via IPCTransactionApi.
 */
class ProductImporter {

  // These are constants representing error conditions for Product Importer.
  const PRODUCTFORMAT_MISSING = 'productFormat field not set';
  const PRODUCT_TYPE_NOT_FOUND = 'unable to map productFormat value to a product type';
  const VARIATION_TYPE_NOT_FOUND = 'unable to map productFormat value to a variation type';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new ProductImporter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, LoggerInterface $logger, StateInterface $state, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->state = $state;
    $this->configFactory = $config_factory;
  }

  /**
   * Imports product with the specified SyncDB ID via IPCTransactionAPI.
   *
   * @param int $sync_db_id
   *   The SyncDB ID of the product.
   *
   * @return string
   *   The result of the import, e.g. "Saved", "Skipped", "Failed".
   */
  public function importProduct($sync_db_id) {
    // Get the product data from the API.
    $requestVariables = new \stdClass();
    $requestVariables->productId = $sync_db_id;
    $response = ProductSync::getProductByProductId($requestVariables);
    $product_from_response = $response['product'];

    // Exit if productFromat value is not set, as it is necessary for import.
    if (!isset($product_from_response['format']['productFormat'])) {
      $this->generateProductImportErrorMessage(self::PRODUCTFORMAT_MISSING, $sync_db_id);
      return "Skipped";
    }
    $product_type = $this->determineProductType($product_from_response['format']['productFormat']);
    // Exit if unable to map productFormat value to a product type.
    if (!$product_type) {
      $this->generateProductImportErrorMessage(self::PRODUCT_TYPE_NOT_FOUND, $sync_db_id, $product_from_response['format']['productFormat']);
      return "Skipped";
    }
    $product = $this->createOrUpdateProduct($product_type, $product_from_response);

    $product_variation_type = $this->determineProductVariationType($product_from_response['format']['productFormat'], $product_from_response['formatCode']);
    // Exit if unable to map productFormat value to a product variation type.
    if (!$product_variation_type) {
      $this->generateProductImportErrorMessage(self::VARIATION_TYPE_NOT_FOUND, $sync_db_id, $product_from_response['format']['productFormat']);
      return "Skipped";
    }
    $this->ensureVariationIsAssignedToCorrectProduct($product_from_response, $product);
    $variation = $this->createOrUpdateProductVariation($product->getVariations(), $product_variation_type, $product_from_response);

    $product->addVariation($variation);
    $product->set('status', $this->isProductPublished($product));
    $product->save();
    $this->updatePriceLists($variation, $product_from_response);
    $this->displayProductImportSuccessMessage($product, $variation);
    return "Saved";
  }

  /**
   * Creates or updates a product with data obtained from SyncDB.
   *
   * @param string $product_type
   *   The product type of the product.
   * @param array $product_from_response
   *   The product data from the Api response.
   *
   * @return \Drupal\commerce_product\Entity\Product
   *   The product.
   */
  public function createOrUpdateProduct(string $product_type, array $product_from_response) {
    $newly_created = FALSE;
    $product = $this->getProductIfProductExists(
      $product_type,
      $product_from_response['programCode'],
      $product_from_response['specialProductCode'],
      $product_from_response['language']['language'],
      $product_from_response['revisionCode'],
      $product_from_response['productId']
    );
    if (!$product) {
      /** @var \Drupal\commerce_store\StoreStorageInterface $store_storage */
      $store_storage = $this->entityTypeManager->getStorage('commerce_store');
      $default_store = $store_storage->loadDefault();
      $product = Product::create([
        'type' => $product_type,
        'stores' => [$default_store],
      ]);
      $product->save();
      $newly_created = TRUE;
    }
    $this->setProductFields($product_type, $product, $product_from_response, $newly_created);
    return $product;
  }

  /**
   * Sets the product fields with values retrieved via api call.
   *
   * @param string $product_type
   *   The product type.
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param array $product_from_response
   *   The product from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function setProductFields(string $product_type, Product &$product, array $product_from_response, bool $newly_created = FALSE) {
    switch ($product_type) {
      case 'document':
        $this->setCoreFieldsCommonToAllProducts($product, $product_from_response, $newly_created);
        $product->set('isbn', $product_from_response['isbn']);
        $product->set('pages', $product_from_response['numberOfPages']);
        $product->set('table_of_contents', $product_from_response['tableOfContentsURL']);
        $product->set('netsuite_prog_code', $product_from_response['programCode']);
        $product->set('netsuite_spec_code', $product_from_response['specialProductCode']);
        if ($product_from_response['publishedYear']) {
          $this->setTaxonomyTermField('year', $product, 'years', $product_from_response['publishedYear']);
        }
        if (isset($product_from_response['revisionCode'])) {
          $this->setTaxonomyTermField('revision', $product, 'revisions', $product_from_response['revisionCode']);
        }
        $published_date = substr($product_from_response['publishedDate'], 0, 10);
        $product->set('published_date', $published_date);
        $product->set('ansi_approved', $product_from_response['ansiApproved']);
        $product->set('dod_adopted', $product_from_response['dodAdopted']);
        $product->set('sample_pages_url', $product_from_response['samplePagesURL']);
        $product->set('toc_url', $product_from_response['tableOfContentsURL']);
        if ($product_from_response['laterRevision']['productId']) {
          $this->setLaterRevisionField($product, $product_from_response);
        }
        if ($newly_created) {
          $this->setDocumentNumbersField($product, $product_from_response);
        }
        if (isset($product_from_response['variety']) && isset($product_from_response['variety']['productVariety'])) {
          $this->setTaxonomyTermField('product_variety', $product, 'product_variety', $product_from_response['variety']['productVariety']);
        }
        $product->save();
        break;

      case 'service':
        $this->setCoreFieldsCommonToAllProducts($product, $product_from_response, $newly_created);
        $product->save();
        break;

      case 'kit':
        $this->setCoreFieldsCommonToAllProducts($product, $product_from_response, $newly_created);
        $product->set('netsuite_prog_code', $product_from_response['programCode']);
        $product->set('netsuite_spec_code', $product_from_response['specialProductCode']);
        if ($product_from_response['publishedYear']) {
          $this->setTaxonomyTermField('year', $product, 'years', $product_from_response['publishedYear']);
        }
        if (isset($product_from_response['revisionCode'])) {
          $this->setTaxonomyTermField('revision', $product, 'revisions', $product_from_response['revisionCode']);
        }
        $published_date = substr($product_from_response['publishedDate'], 0, 10);
        $product->set('published_date', $published_date);
        $product->set('ansi_approved', $product_from_response['ansiApproved']);
        $product->set('dod_adopted', $product_from_response['dodAdopted']);
        if ($product_from_response['laterRevision']['productId']) {
          $this->setLaterRevisionField($product, $product_from_response);
        }
        if ($newly_created) {
          $this->setDocumentNumbersField($product, $product_from_response);
        }
        $product->save();
        break;

    }
  }

  /**
   * Creates or updates a variation with data obtained from SyncDB.
   *
   * @param array $variations
   *   The existing product variations to check against.
   * @param string $product_variation_type
   *   The product variation type.
   * @param array $product_from_response
   *   The product data from the Api response.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariation
   *   The product variation.
   */
  public function createOrUpdateProductVariation(array $variations, string $product_variation_type, array $product_from_response) {
    $newly_created = FALSE;
    $variation = $this->getVariationIfVariationExists(
      $variations,
      $product_variation_type,
      $product_from_response['productId'],
    );
    if (!$variation) {
      $variation = ProductVariation::create([
        'type' => $product_variation_type,
      ]);
      $variation->save();
      $newly_created = TRUE;
    }
    $this->setProductVariationFields($product_variation_type, $variation, $product_from_response, $newly_created);
    return $variation;
  }

  /**
   * Sets the product variation fields with values retrieved via api call.
   *
   * @param string $variation_type
   *   The product variation type.
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function setProductVariationFields(string $variation_type, ProductVariation &$variation, array $product_from_response, bool $newly_created = FALSE) {
    switch ($variation_type) {
      case 'physical_document':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $this->setProductVariationWeightField($variation, $product_from_response);
        $release_date = substr($product_from_response['releaseDate'], 0, 10);
        $variation->set('release_date', $release_date);
        $this->setProductVariationFormatField($variation, $product_from_response);
        $this->setProductVariationProductFormatField($variation, $product_from_response);
        $variation->set('dropshipped', $product_from_response['dropShipProduct']);
        $variation->set('stock_level', $product_from_response['quantityAvailable']);
        $variation->save();
        break;

      case 'digital_document':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $release_date = substr($product_from_response['releaseDate'], 0, 10);
        $variation->set('release_date', $release_date);
        $this->setProductVariationFormatField($variation, $product_from_response);
        $variation->set('drm', $product_from_response['drm']);
        $this->setProductVariationProductFormatField($variation, $product_from_response);
        $this->setProductVariationItemTypeField($variation, $product_from_response);
        $this->setProductVariationLicenseFields($variation, $product_from_response);
        if ($product_from_response['minimumQuantity']) {
          $variation->set('minimum_order_quantity', $product_from_response['minimumQuantity']);
        }
        $variation->save();
        break;

      case 'multi_device_license':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $release_date = substr($product_from_response['releaseDate'], 0, 10);
        $variation->set('release_date', $release_date);
        $this->setProductVariationFormatField($variation, $product_from_response);
        $variation->set('drm', $product_from_response['drm']);
        $this->setProductVariationProductFormatField($variation, $product_from_response);
        $this->setProductVariationItemTypeField($variation, $product_from_response);
        if ($product_from_response['minimumQuantity']) {
          $variation->set('minimum_order_quantity', $product_from_response['minimumQuantity']);
        }
        $variation->save();
        break;

      case 'service':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $variation->set('minimum_order_quantity', $product_from_response['minimumQuantity']);
        $variation->save();
        break;

      case 'kit':
        $this->setCoreFieldsCommonToAllVariations($variation, $product_from_response, $newly_created);
        $this->setProductVariationWeightField($variation, $product_from_response);
        if ($product_from_response['productComponents']) {
          $this->setKitProductsField($variation, $product_from_response);
        }
        $variation->save();
        break;

    }
  }

  /**
   * Enqueues all Product IDs from SyncDB for data import.
   *
   * @param bool $delete_existing
   *   Delete all existing products prior to enqueueing.
   */
  public function enqueueAllProductsFromSyncDb(bool $delete_existing = FALSE) {
    if ($delete_existing) {
      $this->deleteAllExistingProducts();
    }
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_product_sync');
    $all_product_ids = $this->getAllProductIdsFromSyncDb();
    foreach ($all_product_ids as $product_id) {
      $product_import_job = Job::create('syncdb_product_sync', [
        'product_id' => $product_id,
      ]);
      $queue->enqueueJob($product_import_job);
    }
  }

  /**
   * Poll for changes to products in the Sync DB via IPCTransactionAPI.
   */
  public function pollForChangesToProducts() {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_product_sync');
    $product_ids = $this->getUpdatedProductIdsFromSyncDb();
    foreach ($product_ids as $product_id) {
      $product_import_job = Job::create('syncdb_product_sync', [
        'product_id' => $product_id,
      ]);
      $queue->enqueueJob($product_import_job);
    }
  }

  /**
   * Delete all existing products and product variations.
   *
   * @param bool $delete_orders
   *   Delete all existing orders prior to enqueueing products.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function deleteAllExistingProducts(bool $delete_orders = FALSE) {
    $database = \Drupal::database();
    // Delete orders.
    if ($delete_orders) {
      $results_orders = $database->select('commerce_order', 'co')
        ->fields('co', ['order_id'])
        ->execute()->fetchCol();
      if ($results_orders) {
        $this->processDeleteOrdersBatch($results_orders);
      }
    }
    // Delete product variations.
    $results_variations = $database->select('commerce_product_variation', 'cpv')
      ->fields('cpv', ['variation_id'])
      ->execute()->fetchCol();
    if ($results_variations) {
      $this->processDeleteVariationsBatch($results_variations);
    }
    // Delete products.
    $results_products = $database->select('commerce_product', 'cp')
      ->fields('cp', ['product_id'])
      ->execute()->fetchCol();
    if ($results_products) {
      $this->processDeleteProductsBatch($results_products);
    }
    drush_backend_batch_process();
  }

  /**
   * Set 'Published' status for all products/variations based on SyncDB values.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setPublishedStatusForProductsAndVariations() {
    $database = \Drupal::database();
    // Set 'Published' status for all product variations.
    $results_variations = $database->select('commerce_product_variation', 'cpv')
      ->fields('cpv', ['variation_id'])
      ->execute()->fetchCol();
    if ($results_variations) {
      $batch = [
        'title' => t('Set Published status for all product variations'),
        'operations' => [],
        'init_message'     => t('Commencing execution of Set Published Status'),
        'progress_message' => t('Processed @current out of @total.'),
        'error_message'    => t('An error occurred during processing'),
        'finished' => '\Drupal\ipc_syncdb\ProductImporter::batchFinishedCallback',
      ];
      foreach ($results_variations as $id) {
        $batch['operations'][] = [
          '\Drupal\ipc_syncdb\ProductImporter::setPublishedStatusForVariationCallback',
          [$id],
        ];
      }
      batch_set($batch);
    }
    drush_backend_batch_process();
  }

  /**
   * Callback to set 'Published' status for a product variation.
   *
   * Currently this function also sets the status for the product
   * that is associated with the variation.
   *
   * @param int $id
   *   The id of the entity to delete.
   * @param object $context
   *   The batch operation context.
   */
  public function setPublishedStatusForVariationCallback(int $id, object &$context) {
    $storage_handler = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');
    /** @var \Drupal\commerce_product\Entity\ProductVariation $variation */
    $variation = $storage_handler->load($id);
    $sync_db_id = $variation->get('syncdb_id')->value;

    if ($sync_db_id) {
      $requestVariables = new \stdClass();
      $requestVariables->productId = $sync_db_id;
      $response = ProductSync::getProductByProductId($requestVariables);
      $product_from_response = $response['product'];

      $display_in_website = $product_from_response['displayInWebsite'];
      $inactive = $product_from_response['inActive'];
      $discontinued_item = $product_from_response['discontinuedItem'];
      if ($display_in_website && !$inactive && !$discontinued_item) {
        $variation->set('status', TRUE);
        $variation->save();
        $product_id = $variation->getProductId();
        $product_storage = \Drupal::entityTypeManager()->getStorage('commerce_product');
        /** @var \Drupal\commerce_product\Entity\Product $product */
        $product = $product_storage->load($product_id);
        $product->set('status', TRUE);
        $product->save();
      }
    }
  }

  /**
   * Creates batch for processing delete operations for orders.
   *
   * @param array $ids
   *   The ids of the orders to process.
   */
  protected function processDeleteOrdersBatch(array $ids) {
    $batch = [
      'title' => t('Delete existing orders'),
      'operations' => [],
      'init_message'     => t('Commencing'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => '\Drupal\ipc_syncdb\ProductImporter::batchFinishedCallback',
    ];
    foreach ($ids as $id) {
      $batch['operations'][] = [
        '\Drupal\ipc_syncdb\ProductImporter::deleteOrderCallback',
        [$id],
      ];
    }
    batch_set($batch);
  }

  /**
   * Creates batch for processing delete operations for product variations.
   *
   * @param array $ids
   *   The ids of the product variations to process.
   */
  protected function processDeleteVariationsBatch(array $ids) {
    $batch = [
      'title' => t('Delete existing product variations'),
      'operations' => [],
      'init_message'     => t('Commencing'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => '\Drupal\ipc_syncdb\ProductImporter::batchFinishedCallback',
    ];
    foreach ($ids as $id) {
      $batch['operations'][] = [
        '\Drupal\ipc_syncdb\ProductImporter::deleteVariationCallback',
        [$id],
      ];
    }
    batch_set($batch);
  }

  /**
   * Creates batch for processing delete operations for products.
   *
   * @param array $ids
   *   The ids of the products to process.
   */
  protected function processDeleteProductsBatch(array $ids) {
    $batch = [
      'title' => t('Delete existing products'),
      'operations' => [],
      'init_message'     => t('Commencing'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => '\Drupal\ipc_syncdb\ProductImporter::batchFinishedCallback',
    ];
    foreach ($ids as $id) {
      $batch['operations'][] = [
        '\Drupal\ipc_syncdb\ProductImporter::deleteProductCallback',
        [$id],
      ];
    }
    batch_set($batch);
  }

  /**
   * Callback for Delete Orders batch operation.
   *
   * @param int $id
   *   The id of the entity to delete.
   * @param object $context
   *   The batch operation context.
   */
  public function deleteOrderCallback(int $id, object &$context) {
    $storage_handler = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $entity = $storage_handler->load($id);
    $storage_handler->delete([$entity]);
  }

  /**
   * Callback for Delete Product Variations batch operation.
   *
   * @param int $id
   *   The id of the entity to delete.
   * @param object $context
   *   The batch operation context.
   */
  public function deleteVariationCallback(int $id, object &$context) {
    $storage_handler = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');
    $entity = $storage_handler->load($id);
    $storage_handler->delete([$entity]);
  }

  /**
   * Callback for Delete Products batch operation.
   *
   * @param int $id
   *   The id of the entity to delete.
   * @param object $context
   *   The batch operation context.
   */
  public function deleteProductCallback(int $id, object &$context) {
    $storage_handler = \Drupal::entityTypeManager()->getStorage('commerce_product');
    $entity = $storage_handler->load($id);
    $storage_handler->delete([$entity]);
  }

  /**
   * Finished Callback for batch operations.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   * @param array $results
   *   An array of all the results that were updated in update_do_one().
   * @param array $operations
   *   A list of the operations that had not been completed by the batch API.
   */
  public function batchFinishedCallback($success, array $results, array $operations) {
    if (!$success) {
      $this->logger->error(t('Error encountered during batch processing. <br><b>Results:</b> <pre><code>@results</code></pre> <b>Operations:</b> <pre><code>@operations</code></pre>', [
        '@results' => print_r($results, TRUE),
        '@operations' => print_r($operations, TRUE),
      ]));
    }
  }

  /**
   * Get the product IDs for all products that have been updated since last run.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function getUpdatedProductIdsFromSyncDb() {
    $run_time = date('Y-m-d\TH:i:s');
    $all_ids = [];

    $requestVariables = new \stdClass();
    $requestedPage = 1;
    $requestVariables->requestedPage = $requestedPage;

    $last_run_time = $this->state->get('ipc_product_sync_product_importer_last_run');
    $modifiedOnAfter = $last_run_time ? $last_run_time : ApiHelper::POLLING_ROUTINE_START_TIME;
    $requestVariables->modifiedOnAfter = $modifiedOnAfter;
    $response = ProductSync::getProductList($requestVariables);
    $productList = $response['productList'];

    while ($productList) {
      $ids = array_column($productList, 'productId');
      $all_ids = array_merge($all_ids, $ids);
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $response = ProductSync::getProductList($requestVariables);
      $productList = $response['productList'];
    }

    $this->state->set('ipc_product_sync_product_importer_last_run', $run_time);
    return $all_ids;
  }

  /**
   * Get the product IDs for all products from SyncDB via IPCTransactionAPI.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function getAllProductIdsFromSyncDb() {
    $all_ids = [];
    $requestedPage = 1;
    $requestVariables = new \stdClass();
    $requestVariables->requestedPage = $requestedPage;
    $response = ProductSync::getProductList($requestVariables);
    $productList = $response['productList'];

    while ($productList) {
      $ids = array_column($productList, 'productId');
      $all_ids = array_merge($all_ids, $ids);
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $response = ProductSync::getProductList($requestVariables);
      $productList = $response['productList'];
    }

    return $all_ids;
  }

  /**
   * Determines product type of the imported product.
   *
   * @param string $product_format
   *   The value of the productFormat field returned from the api call.
   */
  protected function determineProductType($product_format) {
    switch ($product_format) {
      case 'CD':
      case 'DVD':
      case 'Hard Copy':
      case 'Download':
      case 'Download Item':
        return 'document';

      case 'Kit/Bundle':
        return 'kit';

      case 'Subscription':
      case 'Exam Funds':
        return 'service';

      default:
        return NULL;
    }
  }

  /**
   * Determines product variation type of the imported product.
   *
   * @param string $product_format
   *   The value of the productFormat field returned from the api call.
   * @param string $format_code
   *   The value of the productFormat field returned from the api call.
   */
  protected function determineProductVariationType($product_format, $format_code) {
    switch ($product_format) {
      case 'CD':
      case 'DVD':
      case 'Hard Copy':
        return 'physical_document';

      case 'Download Item':
        return 'digital_document';

      case 'Download':
        if ($format_code == 'MDL') {
          return 'multi_device_license';
        }
        else {
          return 'digital_document';
        }

      case 'Kit/Bundle':
        return 'kit';

      case 'Subscription':
      case 'Exam Funds':
        return 'service';

      default:
        return NULL;
    }
  }

  /**
   * Returns product if product that matches target identifiers exists.
   *
   * @param string $product_type
   *   The product type of the product.
   * @param string $prog_code
   *   The value of the productFormat field returned from the api call.
   * @param string $spec_code
   *   The value of the specialProductCode field returned from the api call.
   * @param string $language
   *   The value of the language field returned from the api call.
   * @param string $revision
   *   The value of the revision field returned from the api call.
   * @param string $syncdb_id
   *   Optional SyncDB ID to look for on a product's variation.
   */
  protected function getProductIfProductExists($product_type, $prog_code, $spec_code, $language, $revision, $syncdb_id = '') {
    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $language_tid = $language ? $this->getTaxonomyTermTid('languages', $language) : '';
    $revision_tid = isset($revision) ? $this->getTaxonomyTermTid('revisions', $revision) : '';

    // Build an entity query that can properly accommodate empty field values.
    $query = $product_storage->getQuery();
    $query
      ->condition('type', $product_type)
      ->sort('product_id', 'DESC')
      ->accessCheck(FALSE);

    // Service products are matched via SyncDB ID on a child variation, because
    // they do not currently have all the same matching field values set as the
    // other product types. Additionally, we know each Service product will
    // have only a single variation.
    if ($product_type == 'service') {
      // If we did not get a SyncDB ID, return FALSE now.
      if (empty($syncdb_id)) {
        return FALSE;
      }

      $query->condition('variations.entity:commerce_product_variation.syncdb_id', $syncdb_id);
    }

    // Only documents and kits currently match on the field values.
    if (in_array($product_type, ['document', 'kit'])) {
      // All product types should have a language value set. Only Document and
      // Kit products will have the others set at the moment.
      if (empty($language_tid)) {
        $query->notExists('language');
      }
      else {
        $query->condition('language.target_id', $language_tid);
      }

      // This value may be '0', so we cannot just check if it's empty().
      if (is_null($prog_code) || $prog_code === '') {
        $query->notExists('netsuite_prog_code');
      }
      else {
        $query->condition('netsuite_prog_code', $prog_code);
      }

      // This value may be '0', so we cannot just check if it's empty().
      if (is_null($spec_code) || $spec_code === '') {
        $query->notExists('netsuite_spec_code');
      }
      else {
        $query->condition('netsuite_spec_code', $spec_code);
      }

      if (empty($revision_tid)) {
        $query->notExists('revision');
      }
      else {
        $query->condition('revision.target_id', $revision_tid);
      }
    }

    $result = $query->execute();
    $product_ids = array_values($result);

    if ($product_ids) {
      $product_id = reset($product_ids);
      return $product_storage->load($product_id);
    }
    return FALSE;
  }

  /**
   * Returns variation if variation that matches target identifiers exists.
   *
   * @param array $variations
   *   The existing product variations to check against.
   * @param string $variation_type
   *   The product variation type.
   * @param string $productId
   *   The value of the productId (Sync DB Id) field returned from the api call.
   */
  protected function getVariationIfVariationExists(array $variations, string $variation_type, string $productId) {
    foreach ($variations as $variation) {
      $type = $variation->get('type')->first();
      $target_variation = $type->getValue('target_id');
      $syncdb_id_first_value = $variation->get('syncdb_id')->first();
      if ($syncdb_id_first_value) {
        $syncdb_id = $syncdb_id_first_value->getValue('value');
        if ($target_variation['target_id'] == $variation_type && $syncdb_id['value'] == $productId) {
          return $variation;
        }
      }
    }
    return FALSE;
  }

  /**
   * Sets the value of the given taxonomy field to the corresponding tid.
   *
   * Creates a taxonomy term with the given term name if a corresponding
   * term does not yet exist.
   *
   * @param string $field_name
   *   The name of the vocabulary term.
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param string $vid
   *   The taxonomy vocabulary id.
   * @param string $term_name
   *   The name of the vocabulary term.
   */
  protected function setTaxonomyTermField(string $field_name, Product $product, string $vid, string $term_name) {
    $term_name = trim($term_name);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $match_exists = FALSE;
    $terms = $storage->loadByProperties([
      'vid' => $vid,
      'name' => strval($term_name),
    ]);
    if ($terms) {
      // There is an existing Taxonomy term matching our term name.
      $match_exists = TRUE;
      $term = reset($terms);
    }
    if (!$match_exists) {
      // No Taxonomy term matching our term name, need to create a term.
      $term = Term::create([
        'name' => strval($term_name),
        'vid' => $vid,
      ]);
      $term->save();
    }
    $product->set($field_name, ['target_id' => $term->id()]);
    $product->save();
  }

  /**
   * Get tid of taxonomy term given the term name.
   *
   * @param string $vid
   *   The taxonomy vocabulary id.
   * @param string $term_name
   *   The name of the vocabulary term.
   */
  protected function getTaxonomyTermTid(string $vid, string $term_name) {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => $vid,
      'name' => strval($term_name),
    ]);
    if ($terms) {
      $term = reset($terms);
      return $term->id();
    }
    return '';
  }

  /**
   * Sets the core product fields with values retrieved via api call.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param array $product_from_response
   *   The product from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function setCoreFieldsCommonToAllProducts(Product &$product, array $product_from_response, bool $newly_created) {
    $product->setOwnerId(0);
    $product->setTitle($product_from_response['pageTitle']);
    $is_published = $newly_created ? FALSE : $product->get('status')->value;
    $product->set('status', $is_published);
    if ($product_from_response['language']['language']) {
      $this->setTaxonomyTermField('language', $product, 'languages', $product_from_response['language']['language']);
    }
    $product->set('body', [
      'value' => $product_from_response['storeDetailedDescription'],
      'format' => 'basic_html',
    ]);
    $product->save();
  }

  /**
   * Sets the core product fields with values retrieved via api call.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setDocumentNumbersField(Product &$product, array $product_from_response) {
    $program_code = $product_from_response['programCode'];
    if ($program_code) {
      $this->setTaxonomyTermFieldByProgCode('document_number', $product, 'document_numbers', $program_code);
    }
    $product->save();
  }

  /**
   * Sets the value of the given taxonomy field based on Prog Code mappings.
   *
   * @param string $field_name
   *   The name of the field to update.
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param string $vid
   *   The taxonomy vocabulary id.
   * @param string $program_code
   *   The Program Code associated with the product.
   */
  protected function setTaxonomyTermFieldByProgCode(string $field_name, Product $product, string $vid, string $program_code) {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => $vid,
      'netsuite_prog_code' => $program_code,
    ]);
    if ($terms) {
      $term = reset($terms);
      $product->set($field_name, ['target_id' => $term->id()]);
      $product->save();
    }
  }

  /**
   * Sets the core product variation fields with values retrieved via api call.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function setCoreFieldsCommonToAllVariations(ProductVariation &$variation, array $product_from_response, bool $newly_created = FALSE) {
    $variation->setOwnerId(0);
    $variation->setTitle($product_from_response['pageTitle']);
    $is_published = $this->isProductVariationPublished($variation, $product_from_response, $newly_created);
    $variation->set('status', $is_published);
    $variation->set('netsuite_id', $product_from_response['nsProductId']);
    $variation->set('syncdb_id', $product_from_response['productId']);
    $variation->setSku($product_from_response['productNumber']);
    $variation->set('avatax_tax_code', $product_from_response['avaTaxCode']);
    $variation->save();
  }

  /**
   * Displays Product Import Success message to the user.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product.
   */
  protected function displayProductImportSuccessMessage(Product $product, ProductVariation $variation) {
    $account = \Drupal::currentUser();
    if ($account->hasPermission('administer ipcsync')) {
      $this->messenger->addMessage(t('Product with SyncDB ID :syncdb_id imported successfully: <a href=":url">:title</a>', [
        ':syncdb_id' => $variation->get('syncdb_id')->value,
        ':url' => $product->toUrl()->toString(),
        ':title' => $product->getTitle(),
      ]), 'status', FALSE);
    }
    $this->logger->notice(t('Product with SyncDB ID :syncdb_id imported successfully: <a href=":url">:title</a>', [
      ':syncdb_id' => $variation->get('syncdb_id')->value,
      ':url' => $product->toUrl()->toString(),
      ':title' => $product->getTitle(),
    ]));
  }

  /**
   * Sets the product variation attribute_format entity reference field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationFormatField(ProductVariation &$variation, array $product_from_response) {
    $productFormat = $product_from_response['formatCode'];
    $avs = $this->entityTypeManager->getStorage('commerce_product_attribute_value')->loadByProperties([
      'attribute' => 'format',
      'name' => $productFormat,
    ]);
    if ($avs) {
      $av = reset($avs);
      $variation->set('attribute_format', ['target_id' => $av->id()]);
      $variation->save();
    }
  }

  /**
   * Sets the product variation weight field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationWeightField(ProductVariation &$variation, array $product_from_response) {
    $weight = $product_from_response['weight'];
    $weight_units = $product_from_response['weightUnits']['weightUnits'];
    // @todo Check weightUnits value against all allowed values for weight field.
    $variation->set('weight', ['number' => $weight, 'unit' => $weight_units]);
    $variation->save();
  }

  /**
   * Sets the product variation product_format field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationProductFormatField(ProductVariation &$variation, array $product_from_response) {
    switch ($product_from_response['format']['productFormat']) {
      case 'CD':
        $product_format = "cd";
        break;

      case 'DVD':
        $product_format = "dvd";
        break;

      case 'Hard Copy':
        $product_format = "hardcopy";
        break;

      case 'Download':
      case 'Download Item':
        $product_format = "download";
        break;

      default:
        $product_format = "";
    }
    $variation->set('product_format', $product_format);
    $variation->save();
  }

  /**
   * Sets the product variation item_type field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationItemTypeField(ProductVariation &$variation, array $product_from_response) {
    switch ($product_from_response['type']['productType']) {
      case 'Download Item':
        $item_type = "download";
        break;

      case 'Service':
        $item_type = "service";
        break;

      default:
        $item_type = "";
    }
    $variation->set('item_type', $item_type);
    $variation->save();
  }

  /**
   * Sets the product variation commerce_file field and license fields.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function setProductVariationLicenseFields(ProductVariation &$variation, array $product_from_response) {
    // Construct new value for commerce_file field based on data from the API.
    if (!empty($product_from_response['productFiles'])) {
      $saved_files = [];
      foreach ($product_from_response['productFiles'] as $product_file) {
        $file_filename = $product_file['filename'];
        $file_url = "s3://$file_filename";

        // Create file if it doesn't exist in Drupal yet.
        /** @var \Drupal\file\FileStorageInterface $file_storage */
        $file_storage = $this->entityTypeManager->getStorage('file');
        $files = $file_storage->loadByProperties(['filename' => $file_filename]);
        if ($files) {
          $file = reset($files);
        }
        else {
          $file = $file_storage->create([
            'filename' => $file_filename,
            'uri' => $file_url,
            'status' => FILE_STATUS_PERMANENT,
          ]);
          $file->save();
        }
        $saved_files[] = $file;
      }
      $variation->set('commerce_file', $saved_files);
    }

    if ($product_from_response['drm'] == TRUE) {
      $variation->set('license_expiration', [
        'target_plugin_id' => 'unlimited',
      ]);
      $variation->set('license_type', [
        'target_plugin_id' => 'commerce_file',
        'target_plugin_configuration' => [
          'file_download_limit' => 0,
        ],
      ]);
    }
    else {
      $variation->set('license_expiration', [
        'target_plugin_id' => 'rolling_interval',
        'target_plugin_configuration' => [
          'interval' => [
            'interval' => 30,
            'period' => 'day',
          ],
        ],
      ]);
      $variation->set('license_type', [
        'target_plugin_id' => 'commerce_file',
        'target_plugin_configuration' => [
          'file_download_limit' => 3,
        ],
      ]);
    }
    $variation->save();
  }

  /**
   * Updates Price Lists for the target Product Variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product from the Api response.
   */
  protected function updatePriceLists(ProductVariation &$variation, array $product_from_response) {
    $requestVariables = new \stdClass();
    $requestVariables->productId = $product_from_response['productId'];
    $response = ProductSync::getProductPriceListByProductId($requestVariables);

    $price_list_from_response = $response['productPriceList'];
    $currency_prices = $price_list_from_response['currencyPrices'];
    foreach ($currency_prices as $currency_price) {
      $currency = $currency_price['currency']['currency'];
      if ($currency != 'US Dollar') {
        break;
      }
      $has_nonmember_price = FALSE;
      $has_member_price = FALSE;
      $has_distributor_price = FALSE;
      $priceLevelPrices = $currency_price['priceLevelPrices'];
      foreach ($priceLevelPrices as $priceLevelPrice) {
        $priceLevel = $priceLevelPrice['priceLevel'];
        $price_list = $this->getProductPriceList($priceLevel['priceLevel']);
        if ($price_list) {
          $priceBreaks = $priceLevelPrice['priceBreaks'];
          foreach ($priceBreaks as $priceBreak) {
            // Set $minQuantity to 1 if value from the API is 0.
            $minQuantity = $priceBreak['minQuantity'] != 0 ? $priceBreak['minQuantity'] : 1;
            $unitPrice = $priceBreak['unitPrice'];
            if ($unitPrice === NULL) {
              $this->logger->error(t('Product Importer Error - SyncDB ID: @sync_db_id. Price List for level "@level" does not have unit price set.', [
                '@sync_db_id' => $product_from_response['productId'],
                '@level' => $priceLevel['priceLevel'],
              ]));
            }
            if ($priceLevel['priceLevel'] == 'Non-Member' && $minQuantity == 1) {
              $variation->setPrice(new Price($unitPrice, 'USD'));
              $variation->save();
            }
            else {
              $price_list_item = $this->updatePriceListItem($price_list->id(), $variation->id(), $minQuantity, $unitPrice);
              $price_list_item->save();
            }
            switch ($priceLevel['priceLevel']) {
              case 'Non-Member':
                $has_nonmember_price = TRUE;
                break;

              case 'Member':
                $has_member_price = TRUE;
                break;

              case 'Distributor':
                $has_distributor_price = TRUE;
                break;
            }
          }
        }
      }
      $this->generatePriceListsLogMessages($product_from_response, $has_nonmember_price, $has_member_price, $has_distributor_price);
    }
  }

  /**
   * Creates or loads the Price List corresponding to the given Price Level.
   *
   * @param string $price_level_name
   *   The name of the Price Level, e.g. "Member", "Non-Member".
   */
  protected function getProductPriceList(string $price_level_name) {
    if ($price_level_name == 'Non-Member') {
      $price_level_name = 'Nonmember bulk pricing';
    }
    $storage = $this->entityTypeManager->getStorage('commerce_pricelist');
    $price_lists = $storage->loadByProperties([
      'name' => $price_level_name,
    ]);
    if ($price_lists) {
      $price_list = reset($price_lists);
      return $price_list;
    }
  }

  /**
   * Creates or updates a Price List Item with data obtained from SyncDB.
   *
   * @param int $price_list_id
   *   The ID of the Price List.
   * @param int $variation_id
   *   The ID of the Product Variation.
   * @param int $minQuantity
   *   The minimum quantity.
   * @param float $unitPrice
   *   The unit price.
   *
   * @return \Drupal\commerce_pricelist\Entity\PriceListItem
   *   The Price List Item.
   */
  public function updatePriceListItem(int $price_list_id, int $variation_id, int $minQuantity, float $unitPrice) {
    $storage = $this->entityTypeManager->getStorage('commerce_pricelist_item');
    $entities = $storage->loadByProperties([
      'type' => 'commerce_product_variation',
      'price_list_id' => $price_list_id,
      'purchasable_entity' => $variation_id,
      'quantity' => $minQuantity,
    ]);
    if ($entities) {
      $price_list_item = reset($entities);
      $price_list_item->setPrice(new Price($unitPrice, 'USD'));
    }
    else {
      $price_list_item = PriceListItem::create([
        'type' => 'commerce_product_variation',
        'price_list_id' => $price_list_id,
        'purchasable_entity' => $variation_id,
        'quantity' => $minQuantity,
        'price' => new Price($unitPrice, 'USD'),
      ]);
    }
    return $price_list_item;
  }

  /**
   * Sets the value of the later_revision field.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   * @param array $product_from_response
   *   The product data from the Api response.
   */
  protected function setLaterRevisionField(Product &$product, array $product_from_response) {
    if ($product_from_response['laterRevision']['productId'] != $product_from_response['productId']) {
      $this->importProduct($product_from_response['laterRevision']['productId']);
      $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $variations = $storage->loadByProperties([
        'syncdb_id' => $product_from_response['laterRevision']['productId'],
      ]);
      if ($variations) {
        $variation = reset($variations);
        $product->set('later_revision', ['target_id' => $variation->getProductId()]);
        $product->save();
      }
    }
  }

  /**
   * Sets the value of the kit_products field.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product data from the Api response.
   */
  protected function setKitProductsField(ProductVariation &$variation, array $product_from_response) {
    $kit_products = [];
    foreach ($product_from_response['productComponents'] as $component) {
      $product_id = $component['productId'];
      if ($product_id != $product_from_response['productId']) {
        $this->importProduct($product_id);
      }
      $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $variations = $storage->loadByProperties([
        'syncdb_id' => $product_id,
      ]);
      if ($variations) {
        $kit_variation = reset($variations);
        $kit_products[] = ['target_id' => $kit_variation->id()];
      }
    }
    $variation->set('kit_products', $kit_products);
    $variation->save();
  }

  /**
   * Determines the Published status of the product variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   * @param array $product_from_response
   *   The product data from the Api response.
   * @param bool $newly_created
   *   Whether or not the product is newly-created by current import process.
   */
  protected function isProductVariationPublished(ProductVariation &$variation, array $product_from_response, bool $newly_created) {
    $inactive = $product_from_response['inActive'];
    $display_in_website = $product_from_response['displayInWebsite'];
    $discontinued_item = $product_from_response['discontinuedItem'];
    // 'Inactive' = Yes or 'Display in Web Site' = No => 'Unpublished'.
    if ($inactive || !$display_in_website || $discontinued_item) {
      // This covers products that are unpublished but purchasable,
      // where 'Inactive' = No and 'Display in Web Site' = No.
      return FALSE;
    }
    // In Writing / pre-published.
    if (!$inactive && $display_in_website && !$discontinued_item) {
      if ($newly_created) {
        // Publish to Drupal is always manual.
        return FALSE;
      }
      else {
        // Return published status of existing product variation.
        return $variation->get('status')->value;
      }
    }
  }

  /**
   * Determines whether or not this product status should be 'Published'.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   The product.
   */
  protected function isProductPublished(Product $product) {
    $published_variation_exists = FALSE;
    foreach ($product->getVariations() as $variation) {
      $variation_status = $variation->get('status')->value;
      if ($variation_status) {
        $published_variation_exists = TRUE;
      }
    }
    if (!$published_variation_exists) {
      // Product should be unpublished if no published variation exists.
      return FALSE;
    }
    // Preserve existing status if published variation exists.
    return $product->get('status')->value;
  }

  /**
   * Log error message for product import routine.
   *
   * @param string $message_type
   *   The type of message to generate.
   * @param string $sync_db_id
   *   SyncDB ID of the product.
   * @param string $product_format
   *   The productFormat value from the API Call.
   */
  protected function generateProductImportErrorMessage($message_type, $sync_db_id, $product_format = NULL) {
    switch ($message_type) {
      case self::PRODUCTFORMAT_MISSING:
        $this->logger->error(t('Unable to import product with SyncDB ID @sync_db_id, because it does not have productFormat field set.', [
          '@sync_db_id' => $sync_db_id,
        ]));
        break;

      case self::PRODUCT_TYPE_NOT_FOUND:
        $this->logger->error(t('Unable to create product for SyncDB ID @sync_db_id. Unable to map productFormat value "@productFormat" to a product type.', [
          '@sync_db_id' => $sync_db_id,
          '@productFormat' => $product_format,
        ]));
        break;

      case self::VARIATION_TYPE_NOT_FOUND:
        $this->logger->error(t('Unable to create product variation for SyncDB ID @sync_db_id. Unable to map productFormat value "@productFormat" to a variation type.', [
          '@sync_db_id' => $sync_db_id,
          '@productFormat' => $product_format,
        ]));
        break;
    }
  }

  /**
   * Delete orphaned product variation (one linked to wrong product).
   *
   * @param array $product_from_response
   *   The product data from the Api response.
   * @param \Drupal\commerce_product\Entity\Product $target_product
   *   The target product.
   */
  protected function ensureVariationIsAssignedToCorrectProduct(array $product_from_response, Product $target_product) {
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $variations = $variation_storage->loadByProperties([
      'syncdb_id' => $product_from_response['productId'],
    ]);
    if ($variations) {
      /** @var \Drupal\commerce_product\Entity\ProductVariation $variation */
      $variation = reset($variations);
      $product_id = $variation->getProductId();

      if ($product_id && $product_id != $target_product->id()) {
        // Attach the variation to the right product.
        $product = $variation->getProduct();
        $product->removeVariation($variation);
        $product->save();
        $target_product->addVariation($variation);
        $target_product->save();
        $variation->set('product_id', $target_product->id());
        $variation->save();

        // Unpublish orphaned product if no other variations for it exist.
        if (!$product->hasVariations()) {
          $product->set('status', FALSE);
        }
      }
    }
  }

  /**
   * Generate log messages for missing Price List values.
   *
   * @param array $product_from_response
   *   The product data from the Api response.
   * @param bool $has_nonmember_price
   *   Whether the product has a Non-Member price set.
   * @param bool $has_member_price
   *   Whether the product has a Member price set.
   * @param bool $has_distributor_price
   *   Whether the product has a Distributor price set.
   */
  protected function generatePriceListsLogMessages(array $product_from_response, bool $has_nonmember_price, bool $has_member_price, bool $has_distributor_price) {
    if (!$has_nonmember_price) {
      $this->logger->error(t('Product Importer Error - SyncDB ID: @sync_db_id. Price List for level "@level" does not exist.', [
        '@sync_db_id' => $product_from_response['productId'],
        '@level' => 'Non-Member',
      ]));
    }
    if (!$has_member_price) {
      $this->logger->error(t('Product Importer Error - SyncDB ID: @sync_db_id. Price List for level "@level" does not exist.', [
        '@sync_db_id' => $product_from_response['productId'],
        '@level' => 'Member',
      ]));
    }
    if (!$has_distributor_price) {
      $this->logger->error(t('Product Importer Error - SyncDB ID: @sync_db_id. Price List for level "@level" does not exist.', [
        '@sync_db_id' => $product_from_response['productId'],
        '@level' => 'Distributor',
      ]));
    }
  }

}
