<?php

namespace Drupal\ipc_syncdb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Session\AccountProxy;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\entity\BundleFieldDefinition;
use Drupal\advancedqueue\Job;

/**
 * Custom controller for debugging.
 */
class CustomController extends ControllerBase {

  /**
   * Drupal\Core\Session\AccountProxy definition.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountProxy $current_user, EntityTypeManager $entity_type_manager) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Perform debugging routine.
   */
  public function alexdebug() {
    \Drupal::service('page_cache_kill_switch')->trigger();

    // $this->testPostTransaction();
    $this->testTransactionManager();
    // $this->testLicenseImporter();
    // $this->testProductImporter();
    // $this->testUserImporter();
    // $this->addToQueue();
    /*
    $this->deleteVariations();

    $variation_storage = \Drupal::service('entity_type.manager')->getStorage('commerce_product_variation');
    $variation = $variation_storage->load(40);

    $storage = $this->entityTypeManager->getStorage('group');
    $group = $storage->load(5);
     */

    return [
      '#markup' => 'The current time is: ' . time(),
    ];
  }

  /**
   * Test PostTransaction.
   */
  protected function testPostTransaction() {
    $order_id = 166;
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    $result = \Drupal::service('ipc_syncdb.transaction_manager')->postTransaction($order);
  }

  /**
   * Test Transaction Manager.
   */
  protected function testTransactionManager() {
    /*$commerce_invoice_storage = $this->entityTypeManager->getStorage('commerce_invoice');
    $entities = $commerce_invoice_storage->loadByProperties([
    'type' => 'quote',
    'syncdb_id' => 9438,
    ]);
    if ($entities) {
    $quote = reset($entities);
    $quote->delete();
    }*/

    // Invoice.
    /* \Drupal::service('ipc_syncdb.transaction_manager')->processUpdateForTransaction('', 1454); */
    // Quote.
    // \Drupal::service('ipc_syncdb.transaction_manager')->processUpdateForTransaction('', 9438);
    // \Drupal::service('ipc_syncdb.transaction_manager')->processUpdateForTransaction('', 9743);.
    // \Drupal::service('ipc_syncdb.transaction_manager')->processUpdateForTransaction('', 9762);.
    \Drupal::service('ipc_syncdb.transaction_manager')->processUpdateForTransaction('', 9761);
    // \Drupal::service('ipc_syncdb.transaction_manager')->processUpdateForTransaction('', 9502);
    // \Drupal::service('ipc_syncdb.transaction_manager')->processUpdateForTransaction('', 1173);
    // Sales Order.
    /*\Drupal::service('ipc_syncdb.transaction_manager')->processUpdateForTransaction(108, 174);*/

    // $this->testPostTransaction();
  }

  /**
   * Delete product from all active carts.
   */
  protected function deleteProductFromCarts() {
    $variation = $this->entityTypeManager()->getStorage('commerce_product_variation')->load(80);

    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $orderStorage->getQuery()
      ->condition('state', 'draft')
      ->condition('cart', TRUE)
      ->accessCheck(FALSE);
    $cart_ids = $query->execute();

    foreach ($cart_ids as $cart_id) {
      $order = $orderStorage->load($cart_id);
      $order_items = $order->getItems();
      foreach ($order_items as $order_item) {
        $purchased_entity_id = $order_item->getPurchasedEntityId();
        if ($purchased_entity_id == $variation->id()) {
          \Drupal::service('commerce_cart.cart_manager')->removeOrderItem($order, $order_item);
        }
      }
    }

    $d = 5;
  }

  /**
   * Test License Importer.
   */
  protected function testLicenseImporter() {
    // \Drupal::service('ipc_syncdb.license_importer')->importDigitalDownloadTransaction(6486);
    \Drupal::service('ipc_syncdb.license_importer')->importDigitalDownloadTransaction(4185);
  }

  /**
   * Test Product Importer.
   */
  protected function testProductImporter() {
    // \Drupal::service('ipc_syncdb.product_importer')->pollForChangesToProducts();
    // \Drupal::service('ipc_syncdb.product_importer')->importProduct(6448);
    \Drupal::service('ipc_syncdb.product_importer')->importProduct(6630);
  }

  /**
   * Test User Importer.
   */
  protected function testUserImporter() {
    \Drupal::service('ipc_syncdb.user_importer')->importLicensesForCompany(77586);
    \Drupal::service('ipc_syncdb.user_importer')->importLicensesByUserId(755764);
    \Drupal::service('ipc_syncdb.user_importer')->pollForChangesToLicenses("2021-06-23T16:17:36");
    \Drupal::service('ipc_syncdb.user_importer')->pollForChangesToUsers();
    \Drupal::service('ipc_syncdb.user_importer')->importUserByEmail('rick.rockwell@ge.com');
  }

  /**
   * Add to queue.
   */
  protected function addToQueue() {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_product_sync');
    $product_ids = [7397];
    foreach ($product_ids as $product_id) {
      $product_import_job = Job::create('syncdb_product_sync', [
        'product_id' => $product_id,
      ]);
      $queue->enqueueJob($product_import_job);
    }
  }

  /**
   * Function performOperationOnSavedEntity.
   */
  public function performOperationOnSavedEntity() {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $users = $user_storage->loadByProperties([
      'mail' => 'rick.rockwell@ge.com',
    ]);
    if ($users) {
      $user = reset($users);
      $user->set('syncdb_id', '');
      $user->save();
    }
    $this->testPostTransaction();
  }

  /**
   * Delete saved quotes.
   */
  public function deleteQuotes() {
    $storage = $this->entityTypeManager->getStorage('commerce_invoice');
    $entities = $storage->loadByProperties([
      'type' => 'quote',
    ]);
    if ($entities) {
      foreach ($entities as $entity) {
        $entity->delete();
      }
    }
  }

  /**
   * Delete saved variations.
   */
  public function deleteVariations() {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $variations = $storage->loadByProperties([
      'type' => 'multi_device_license',
    ]);
    if ($variations) {
      foreach ($variations as $variation) {
        $variation->delete();
      }
    }
  }

  /**
   * Utility function - delete all existing {products}.
   */
  public function deleteAllExistingProducts() {
    $result = \Drupal::service('ipc_syncdb.product_importer')->deleteAllExistingProducts(TRUE);
    $result2 = \Drupal::service('ipc_syncdb.product_importer')->deleteAllExistingProducts();
  }

  /**
   * Call Debug Routines.
   */
  protected function exampleCreateFieldDefinitions() {
    $entity_definition_update = \Drupal::entityDefinitionUpdateManager();

    /*$storage_definition = BundleFieldDefinition::create('string')
    ->setName('po_value')
    ->setTargetEntityTypeId('commerce_payment_method')
    ->setLabel(t('Value of PO'))
    ->setRequired(TRUE);
    $entity_definition_update->uninstallFieldStorageDefinition($storage_definition);*/

    $storage_definition = BundleFieldDefinition::create('integer')
      ->setLabel(t('Value of PO'))
      ->setRequired(TRUE);
    $entity_definition_update->installFieldStorageDefinition('po_value', 'commerce_payment_method', 'ipc_commerce', $storage_definition);

    $storage_definition = BundleFieldDefinition::create('email')
      ->setLabel(t('Billing Notification Email Address'))
      ->setRequired(TRUE);
    $entity_definition_update->installFieldStorageDefinition('po_billing_email', 'commerce_payment_method', 'ipc_commerce', $storage_definition);
  }

  /**
   * Use Query to Load Products.
   */
  protected function exampleUseQueryToLoadProducts($query) {
    $query
      ->condition('type', 'article')
      ->condition('status', TRUE)
      ->range(0, 10)
      ->sort('created', 'DESC');
    $ids = $query->execute();
    return $ids;
  }

  /**
   * Example: exampleLoadSomeSavedEntities.
   */
  public function exampleLoadSomeSavedEntities() {
    // Load all nodes of a certain type.
    \Drupal::entityTypeManager()->getStorage('node')
      ->loadByProperties(['type' => 'content_type', 'status' => 1]);

    // If you would like all nodes also unpublished just use:
    \Drupal::entityTypeManager()->getStorage('node')
      ->loadByProperties(['type' => 'content_type']);

    // If you would like all nodes also unpublished just use:
    $items = \Drupal::entityTypeManager()->getStorage('commerce_product')
      ->loadByProperties(['title' => 'title']);

    $id = 1111;
    /** @var NodeType $type */
    $type = $this->entityTypeManager()->getStorage('node_type')->load($id);

    $description = $type->getDescription();
    $description = $type->get('description');
    $id = $type->id();
    $label = $type->label();
    $uuid = $type->uuid();
    $bundle = $type->bundle();
    $language = $type->language();
  }

  /**
   * Example: exampleGetPurchasableEntityIds.
   */
  protected function exampleGetPurchasableEntityIds() {
    $variation_ids = [];

    $product_ids = $this->getProductIds();
    if (!empty($product_ids)) {
      foreach ($this->productStorage->loadMultiple($product_ids) as $product) {
        /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
        $variation_ids += $product->getVariationIds();
      }
    }

    return array_values($variation_ids);
  }

}
