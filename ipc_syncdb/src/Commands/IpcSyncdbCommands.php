<?php

namespace Drupal\ipc_syncdb\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Drupal\ipc_syncdb\ProductImporter;

/**
 * A Drush commandfile.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml.
 */
class IpcSyncdbCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The inline form manager.
   *
   * @var \Drupal\ipc_syncdb\ProductImporter
   */
  protected $productImporter;

  /**
   * Constructs a new IpcSyncdbCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ipc_syncdb\ProductImporter $productImporter
   *   The product importer.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ProductImporter $productImporter) {
    parent::__construct();

    $this->entityTypeManager = $entity_type_manager;
    $this->productImporter = $productImporter;
  }

  /**
   * Run Product Sync polling routine to enqueue products for import.
   *
   * @usage ipc_syncdb:product-sync
   *
   * @command ipc_syncdb:product-sync
   * @aliases product-sync
   */
  public function runProductSync() {
    $this->productImporter->enqueueAllProductsFromSyncDb();
    $this->logger()->success(dt('Product Sync polling complete, products with changes to import have been added to IPC Product Sync queue.'));
  }

  /**
   * Delete all existing products from the Drupal db.
   *
   * @param array $options
   *   Associative array of options. Values come from cli, aliases, config, etc.
   * @option delete-orders-first
   *   Delete all existing orders prior to deleting products.
   * @usage ipc_syncdb:delete-products
   * @usage ipc_syncdb:delete-products --delete-orders-first
   *
   * @command ipc_syncdb:delete-products
   * @aliases delete-products
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function deleteProducts(array $options = ['delete-orders-first' => FALSE]) {
    if ($options['delete-orders-first']) {
      if ($this->io()->confirm(dt("Are you sure you want to delete all existing orders and products?"))) {
        $this->logger()->notice(dt('Deleting all existing orders and products.'));
        $this->productImporter->deleteAllExistingProducts(TRUE);
        $this->logger()->success(dt('Delete operation completed successfully.'));
      }
    }
    else {
      if ($this->io()->confirm(dt("Are you sure you want to delete all existing products?"))) {
        $this->logger()->notice(dt('Deleting all existing products.'));
        $this->productImporter->deleteAllExistingProducts();
        $this->logger()->success(dt('Delete operation completed successfully.'));
      }
    }
  }

  /**
   * Set 'Published' status for all products/variations based on SyncDB values.
   *
   * Sets status to Published for all product variations for which the SyncDB
   * values 'Display in Web Site' = Yes && 'Inactive' = No.
   * Sets status to Published for all products that have at least one variation
   * that is published.
   *
   * @command ipc_syncdb:product-sync:set-published-status
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setPublishedStatusForProductsAndVariations() {
    if ($this->io()->confirm(dt("Set Published status for all current products/variations based on values in SyncDB?"))) {
      $this->logger()->notice(dt('Executing: Product Sync - Set Published Status.'));
      $this->productImporter->setPublishedStatusForProductsAndVariations();
      $this->logger()->success(dt('Execution complete.'));
    }
  }

}
