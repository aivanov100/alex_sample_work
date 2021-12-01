<?php

namespace Drupal\ipc_syncdb;

use Drupal\commerce_license\Entity\License;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ipcsync\Utilities\ProductSync;
use Drupal\ipcsync\Utilities\TransactionSync;
use Psr\Log\LoggerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\advancedqueue\Job;

/**
 * Imports licenses from Sync DB via IPCEntitiesApi.
 */
class LicenseImporter {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

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
   * The user importer.
   *
   * @var \Drupal\ipc_syncdb\UserImporter
   */
  protected $userImporter;

  /**
   * The company importer.
   *
   * @var \Drupal\ipc_syncdb\CompanyImporter
   */
  protected $companyImporter;

  /**
   * The product importer.
   *
   * @var \Drupal\ipc_syncdb\ProductImporter
   */
  protected $productImporter;

  /**
   * Constructs a new LicenseImporter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Drupal\ipc_syncdb\UserImporter $user_importer
   *   The user importer.
   * @param \Drupal\ipc_syncdb\CompanyImporter $company_importer
   *   The company importer.
   * @param \Drupal\ipc_syncdb\ProductImporter $product_importer
   *   The product importer.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, StateInterface $state, UserImporter $user_importer, CompanyImporter $company_importer, ProductImporter $product_importer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->state = $state;
    $this->userImporter = $user_importer;
    $this->companyImporter = $company_importer;
    $this->productImporter = $product_importer;
  }

  /**
   * Poll for changes to download licenses in the Sync DB.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function pollForChangesToLicenses($modified_on_after = '') {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_license_sync');
    $transactions = $this->getUpdatedDownloadTransactionsFromSyncDb($modified_on_after);
    foreach ($transactions as $transaction_id) {
      $import_job = Job::create('syncdb_license_sync', [
        'transaction_id' => $transaction_id,
      ]);
      $queue->enqueueJob($import_job);
    }
  }

  /**
   * Get all licenses that have been updated since last run.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function getUpdatedDownloadTransactionsFromSyncDb($modified_on_after = '') {
    $run_time = date('Y-m-d\TH:i:s');
    $all_entries = [];

    $requestVariables = new \stdClass();
    $requestedPage = 1;
    $requestVariables->requestedPage = $requestedPage;
    if ($modified_on_after) {
      $modifiedOnAfter = $modified_on_after;
    }
    else {
      $last_run_time = $this->state->get('ipcsync_license_importer_last_run');
      $modifiedOnAfter = $last_run_time ?: ApiHelper::POLLING_ROUTINE_START_TIME;
    }
    $requestVariables->modifiedOnAfter = $modifiedOnAfter;

    $response = TransactionSync::getDigitalDownloadTransactionList($requestVariables);
    $response_list = $response['transactionList'];
    while ($response_list) {
      foreach ($response_list as $transaction) {
        if ($transaction['hasDigitalDownload']) {
          $all_entries[] = $transaction['transactionId'];
        }
      }
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $response = TransactionSync::getDigitalDownloadTransactionList($requestVariables);
      $response_list = $response['transactionList'];
    }

    $this->state->set('ipcsync_license_importer_last_run', $run_time);
    return $all_entries;
  }

  /**
   * Imports digital download license for a transaction.
   *
   * @param int $transaction_id
   *   The Transaction ID associated with the transaction.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function importDigitalDownloadTransaction($transaction_id) {
    $requestVariables = new \stdClass();
    $requestVariables->transactionId = $transaction_id;
    $response = TransactionSync::getTransaction($requestVariables);
    $transaction = $response['transaction'];

    // Skip import for transactions created in Drupal.
    if ($transaction['externalTransactionNumber']) {
      $this->logger->notice(t('License Sync - Skipping import for license for transaction ID @tid because transaction originated in Drupal.', [
        '@tid' => $transaction_id,
      ]));
      return;
    }

    // Cannot import transaction if neither "customer" is set.
    if (empty($transaction['customer']['user']) && empty($transaction['customer']['company'])) {
      $this->logger->error(t('License Sync Error - Cannot import license for transaction ID @tid because "customer" field is not set', [
        '@tid' => $transaction_id,
      ]));
      return;
    }

    foreach ($transaction['lineItems'] as $line_item) {
      if ($line_item['isDigitalDownload']) {
        $product_id = $line_item['product']['productId'];
        $quantity = $line_item['quantity'];

        $requestVariables = new \stdClass();
        $requestVariables->productId = $product_id;
        $response = ProductSync::getProductByProductId($requestVariables);
        $product_from_response = $response['product'];

        $product_files = $product_from_response['productFiles'];
        foreach ($product_files as $product_file) {
          $this->importProductFileLicense($transaction, $product_id, $product_file, $quantity);
        }
      }
    }
  }

  /**
   * Imports Download License data.
   *
   * @param array $transaction
   *   The Transaction data returned from the API.
   * @param int $product_id
   *   The Product ID.
   * @param array $product_file
   *   Product file data.
   * @param int $quantity
   *   The quantity of the licenses to be imported.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function importProductFileLicense(array $transaction, int $product_id, array $product_file, int $quantity) {
    $variation = $this->createOrUpdateFileAndVariationForLicense($product_id, $product_file, $transaction['transactionId']);
    // Proceed to create licenses if file and product variation saved correctly.
    if ($variation) {
      // Extract license field values from the variation.
      $license_expiration = $variation->get('license_expiration')->first()->getValue();
      $license_type = $variation->get('license_type')->first()->getValue();
      // Expect 'target_plugin_id' to equal 'unlimited' or 'rolling_interval'.
      $target_plugin_id = $license_expiration['target_plugin_id'];
      $license_interval = $license_expiration['target_plugin_configuration']['interval'];
      $file_download_limit = $license_type['target_plugin_configuration']['file_download_limit'];

      // Skip import if license has expired.
      if ($target_plugin_id == 'rolling_interval' && !empty($license_interval['interval']) && !empty($license_interval['period'])) {
        $transaction_date = DrupalDateTime::createFromFormat("Y-m-d\TH:i:s\Z", $transaction['transactionDate']);
        $expiration_date = $transaction_date->modify('+' . $license_interval['interval'] . ' ' . $license_interval['period'] . 's');
        if (time() > $expiration_date->getTimestamp()) {
          return;
        }
      }

      $quantity_existing_licenses = 0;
      $quantity_licenses_created = 0;
      $license_storage = $this->entityTypeManager->getStorage('commerce_license');
      $query = $license_storage->getQuery()
        ->condition('type', 'commerce_file')
        ->condition('product_variation.target_id', $variation->id())
        ->condition('originating_ns_transaction_id', $transaction['transactionId'])
        ->accessCheck(FALSE);
      $license_ids = $query->execute();
      if ($license_ids) {
        $quantity_existing_licenses = count($license_ids);
      }

      while ($quantity_existing_licenses + $quantity_licenses_created < $quantity) {
        /** @var \Drupal\commerce_license\Entity\License $license */
        $license = License::create([
          'type' => 'commerce_file',
          'state' => 'active',
          'originating_ns_transaction_id' => $transaction['transactionId'],
          'product_variation' => [
            'target_id' => $variation->id(),
          ],
        ]);
        if ($target_plugin_id == 'unlimited') {
          $license->set('expiration_type', [
            'target_plugin_id' => 'unlimited',
            'target_plugin_configuration' => [],
          ]);
        }
        elseif ($target_plugin_id == 'rolling_interval') {
          $license->set('expiration_type', [
            'target_plugin_id' => 'fixed_reference_date_interval',
            'target_plugin_configuration' => [
              'reference_date' => substr($transaction['transactionDate'], 0, 10),
              'interval' => $license_interval,
            ],
          ]);
          $license->set('file_download_limit', $file_download_limit);
        }

        $license->save();
        $this->setRelationshipsForLicense($variation->id(), $license, $transaction['customer'], $transaction['transactionId']);
        $this->generateLicenseImportSuccessMessage($license, $transaction['customer'], $transaction['transactionId']);

        $quantity_licenses_created++;
      }
    }
  }

  /**
   * Create or update File and Product Variation For License.
   *
   * @param int $product_id
   *   The Product ID.
   * @param array $product_file
   *   Product File data from IPCTransactionAPI.
   * @param int $transaction_id
   *   The Transaction ID.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function createOrUpdateFileAndVariationForLicense(int $product_id, array $product_file, int $transaction_id) {
    $file_filename = $product_file['filename'];

    // Load product variation with commerce_file field that matches our file.
    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $variation_storage */
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $query = $variation_storage->getQuery()
      ->condition('type', 'digital_document')
      ->condition('commerce_file.entity.filename', $file_filename)
      ->accessCheck(FALSE);
    $variation_ids = $query->execute();

    if ($variation_ids) {
      $variation_ids = array_values($variation_ids);
      $variation_id = reset($variation_ids);
      $variation = $variation_storage->load($variation_id);
    }
    else {
      // Ensure that product and product variation are imported.
      $result = $this->productImporter->importProduct($product_id);
      if ($result == 'Saved') {
        // Load the imported variation.
        $variations = $variation_storage->loadByProperties([
          'syncdb_id' => $product_id,
        ]);
        if ($variations) {
          /** @var \Drupal\commerce_product\Entity\ProductVariation $variation */
          $variation = reset($variations);
        }
      }
      else {
        $this->logger->error(t('License Sync Error - Cannot import license for transaction ID @tid - a product variation for the file @filename could not be generated', [
          '@tid' => $transaction_id,
          '@filename' => $file_filename,
        ]));
      }
    }

    return $variation;
  }

  /**
   * Sets the uid field and assigns license to user/company.
   *
   * @param int $variation_id
   *   The variation ID.
   * @param \Drupal\commerce_license\Entity\License $license
   *   The license.
   * @param array $customer_data
   *   Customer data.
   * @param int $transaction_id
   *   The Transaction ID.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function setRelationshipsForLicense(int $variation_id, License $license, array $customer_data, int $transaction_id) {
    if ($customer_data['user']) {
      // Assign the license to a user.
      if ($user = $this->userImporter->importUserByEmail($customer_data['user']['email'])) {
        $license->set('uid', $user->id());
      }
      else {
        $this->logger->error(t('License Sync Error - Transaction ID @tid - Error importing license with Drupal ID @drupal_license_id - Unable to import the associated user with email: @email', [
          '@tid' => $transaction_id,
          '@drupal_license_id' => $license->id(),
          '@email' => $customer_data['user']['email'],
        ]));
        return;
      }
    }
    elseif ($customer_data['company']) {
      // Assign the license to a company.
      if ($company = $this->companyImporter->importCompany($customer_data['company']['accountId'])) {
        $license->set('uid', 0);
        $group_type = $company->getGroupType();
        if ($group_type->hasContentPlugin('group_license')) {
          $company->addContent($license, 'group_license');
        }
      }
      else {
        $this->logger->error(t('License Sync Error - Transaction ID @tid - Error importing license with Drupal ID @drupal_license_id - Unable to import the associated company with AccountId: @account_id', [
          '@tid' => $transaction_id,
          '@drupal_license_id' => $license->id(),
          '@account_id' => $customer_data['company']['accountId'],
        ]));
        return;
      }
    }
    $license->save();
  }

  /**
   * Generates the License Import success log message.
   *
   * @param \Drupal\commerce_license\Entity\License $license
   *   The license.
   * @param array $customer_data
   *   Customer data.
   * @param int $transaction_id
   *   The Transaction ID.
   */
  protected function generateLicenseImportSuccessMessage(License $license, array $customer_data, int $transaction_id) {
    if ($customer_data['user']) {
      $this->logger->notice(t('License Importer Success - Transaction ID @tid - Created license with Drupal ID @drupal_license_id, for user with email @email', [
        '@tid' => $transaction_id,
        '@drupal_license_id' => $license->id(),
        '@email' => $customer_data['user']['email'],
      ]));
    }
    elseif ($customer_data['company']) {
      $this->logger->notice(t('License Importer Success - Transaction ID @tid - Created license with Drupal ID @drupal_license_id, for company with SyncDB AccountId: @account_id', [
        '@tid' => $transaction_id,
        '@drupal_license_id' => $license->id(),
        '@account_id' => $customer_data['company']['accountId'],
      ]));
    }
  }

}
