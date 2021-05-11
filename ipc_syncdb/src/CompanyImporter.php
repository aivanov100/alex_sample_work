<?php

namespace Drupal\ipc_syncdb;

use Drupal\advancedqueue\Job;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Psr\Log\LoggerInterface;
use Drupal\group\Entity\Group;
use Drupal\ipcsync\Utilities\UserSync;
use Drupal\profile\Entity\Profile;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Imports products from Sync DB via IPCTransactionApi.
 */
class CompanyImporter {

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
   * The api helper.
   *
   * @var \Drupal\ipc_syncdb\ApiHelper
   */
  protected $apiHelper;

  /**
   * Constructs a new CompanyImporter object.
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
   * @param \Drupal\ipc_syncdb\ApiHelper $api_helper
   *   The api helper.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, LoggerInterface $logger, StateInterface $state, ConfigFactoryInterface $config_factory, ApiHelper $api_helper) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->apiHelper = $api_helper;
  }

  /**
   * Imports company with the corresponding SyncDB Account ID.
   *
   * @param int $sync_db_id
   *   The SyncDB Account ID of the company.
   */
  public function importCompany($sync_db_id) {
    $requestVariables = new \stdClass();
    $requestVariables->accountId = $sync_db_id;
    $config = $this->configFactory->get('ipc_syncdb.settings');
    $requestVariables->logLevel = $config->get('entities_api_log_level');
    $response_company = UserSync::getCompany($requestVariables);
    $this->apiHelper->processApiResponse($response_company, 'getCompany', 'accountId', $sync_db_id);
    if ($config->get('log_entities_api_calls')) {
      $this->apiHelper->generateDetailedLogMessage('getCompany', $requestVariables, $response_company);
    }
    $company = $this->createOrUpdateCompany($response_company['company']);
    $this->displayCompanyImportSuccessMessage($company);
  }

  /**
   * Creates or updates a company with data obtained from SyncDB.
   *
   * @param array $company_from_response
   *   The company data from the Api response.
   *
   * @return Drupal\group\Entity\Group
   *   The company.
   */
  public function createOrUpdateCompany(array $company_from_response) {
    $company = $this->getCompanyIfCompanyExists($company_from_response);
    if (!$company) {
      $company = Group::create([
        'type' => 'company',
      ]);
      $company->save();
      $this->logger->notice(t('Company group created: @url', [
        '@url' => $company->toUrl()->toString(),
      ]));
    }
    $this->setCompanyFields($company, $company_from_response);
    return $company;
  }

  /**
   * Get the company IDs for all companies from SyncDB via IPCEntitiesAPI.
   */
  public function getAllCompanyIdsFromSyncDb() {
    $all_ids = [];
    $requestedPage = 1;
    $requestVariables = new \stdClass();
    $requestVariables->requestedPage = $requestedPage;
    $config = $this->configFactory->get('ipc_syncdb.settings');
    $requestVariables->logLevel = $config->get('entities_api_log_level');
    $response = UserSync::getCompanyList($requestVariables);
    $this->apiHelper->processApiResponse($response, 'getCompanyList', 'requestedPage', $requestedPage);
    $companyList = $response['companyList'];

    while ($companyList) {
      $ids = array_column($companyList, 'accountId');
      $all_ids = array_merge($all_ids, $ids);
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $response = UserSync::getCompanyList($requestVariables);
      $this->apiHelper->processApiResponse($response, 'getCompanyList', 'requestedPage', $requestedPage);
      $companyList = $response['companyList'];
    }

    return $all_ids;
  }

  /**
   * Enqueues all Company IDs from SyncDB for data import.
   */
  public function enqueueAllCompaniesFromSyncDb() {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_company_sync');
    $all_company_ids = $this->getAllCompanyIdsFromSyncDb();
    foreach ($all_company_ids as $company_id) {
      $company_import_job = Job::create('syncdb_company_sync', [
        'company_id' => $company_id,
      ]);
      $queue->enqueueJob($company_import_job);
    }
  }

  /**
   * Returns group if group that matches target identifiers exists.
   *
   * @param array $company_from_response
   *   The company data from the Api response.
   */
  public function getCompanyIfCompanyExists(array $company_from_response) {
    $storage = $this->entityTypeManager->getStorage('group');
    $companies = $storage->loadByProperties([
      'type' => 'company',
      'syncdb_account_number' => $company_from_response['accountId'],
    ]);
    if ($companies) {
      $company = reset($companies);
      $this->logger->notice(t('Existing company group loaded: <a href=":url">:label</a>', [
        ':url' => $company->toUrl()->toString(),
        ':label' => $company->label(),
      ]));
      return $company;
    }
    return FALSE;
  }

  /**
   * Sets the company group fields with values retrieved via Api call.
   *
   * @param Drupal\group\Entity\Group $company
   *   The company.
   * @param array $company_from_response
   *   The company from the Api response.
   */
  protected function setCompanyFields(Group &$company, array $company_from_response) {
    $company->setOwnerId(1);
    $company->set('syncdb_account_number', $company_from_response['accountId']);
    $company->set('label', $company_from_response['companyName']);
    $company->set('billing_email', $company_from_response['billingContactEmail']);
    $company->save();
    $this->setPriceLevelField($company, $company_from_response);
    $this->updateCustomerProfiles($company, $company_from_response);
  }

  /**
   * Sets the price_level field.
   *
   * @param Drupal\group\Entity\Group $company
   *   The company.
   * @param array $company_from_response
   *   The company from the Api response.
   */
  protected function setPriceLevelField(Group &$company, array $company_from_response) {
    switch ($company_from_response['companyPriceLevel']['priceLevel']) {
      case 'Non-Member':
        $price_level = 'nonmember';
        break;

      case 'Member':
        $price_level = 'member';
        break;

      case 'Distributor':
        $price_level = 'distributor';
        break;

      default:
        $price_level = '';
    }
    $company->set('price_level', $price_level);
    $company->save();
  }

  /**
   * Creates/updates customer profiles with addresses retrieved via Api call.
   *
   * @param Drupal\group\Entity\Group $company
   *   The company.
   * @param array $company_from_response
   *   The company from the Api response.
   */
  protected function updateCustomerProfiles(Group &$company, array $company_from_response) {
    $requestVariables = new \stdClass();
    $requestVariables->accountId = $company_from_response['accountId'];
    $config = $this->configFactory->get('ipc_syncdb.settings');
    $requestVariables->logLevel = $config->get('entities_api_log_level');
    $response_address_list = UserSync::getCompanyAddressList($requestVariables);
    $this->apiHelper->processApiResponse($response_address_list, 'getCompanyAddressList', 'accountId', $requestVariables->accountId);
    if ($config->get('log_entities_api_calls')) {
      $this->apiHelper->generateDetailedLogMessage('getCompanyAddressList', $requestVariables, $response_address_list);
    }
    foreach ($response_address_list['addressList'] as $address_from_response) {
      $profile = $this->updateProfile($address_from_response);
      $company->addContent($profile, 'group_profile:customer');
    }
  }

  /**
   * Creates or updates a profile with data obtained from SyncDB.
   *
   * @param array $address_from_response
   *   The address data from the Api response.
   *
   * @return Drupal\profile\Entity\Profile
   *   The profile.
   */
  public function updateProfile(array $address_from_response) {
    $profile = $this->getProfileIfProfileExists($address_from_response);
    if (!$profile) {
      $profile = Profile::create([
        'type' => 'customer',
        'syncdb_id' => $address_from_response['addressId'],
      ]);
      $profile->save();
    }
    $this->setProfileFields($profile, $address_from_response);
    return $profile;
  }

  /**
   * Sets the profile fields with values retrieved via Api call.
   *
   * @param Drupal\profile\Entity\Profile $profile
   *   The profile.
   * @param array $address_from_response
   *   The address from the Api response.
   */
  protected function setProfileFields(Profile &$profile, array $address_from_response) {
    $profile->set('netsuite_id', $address_from_response['nsAddressId']);
    $address = [
      'country_code' => $address_from_response['country']['twoLetterISOCode'],
      'address_line1' => $address_from_response['address1'],
      'address_line2' => $address_from_response['address2'],
      'locality' => $address_from_response['city'],
      'administrative_area' => $address_from_response['stateOrProvince'],
      'postal_code' => $address_from_response['postalCode'],
    ];
    $profile->set('address', $address);
    if ($address_from_response['primaryAddress'] == TRUE) {
      $profile->set('address_type', 'primary');
    }
    $profile->set('primary_address', $address_from_response['primaryAddress']);
    $profile->set('default_billing_address', $address_from_response['defaultBillingAddress']);
    $profile->set('default_shipping_address', $address_from_response['defaultShippingAddress']);
    $profile->set('home_address', $address_from_response['homeAddress']);
    $profile->set('residential_address', $address_from_response['residentialAddress']);
    $profile->save();
  }

  /**
   * Returns profile if profile that matches target identifiers exists.
   *
   * @param array $address_from_response
   *   The address data from the Api response.
   */
  public function getProfileIfProfileExists(array $address_from_response) {
    $storage = $this->entityTypeManager->getStorage('profile');
    $profiles = $storage->loadByProperties([
      'type' => 'customer',
      'syncdb_id' => $address_from_response['addressId'],
    ]);
    if ($profiles) {
      $profile = reset($profiles);
      return $profile;
    }
    return FALSE;
  }

  /**
   * Displays Company Import success message to the user.
   *
   * @param Drupal\group\Entity\Group $company
   *   The company.
   */
  protected function displayCompanyImportSuccessMessage(Group $company) {
    $account = \Drupal::currentUser();
    if ($account->hasPermission('administer ipc_syncdb')) {
      $this->messenger->addMessage(t('Company imported successfully: <a href=":url">:label</a>', [
        ':url' => $company->toUrl()->toString(),
        ':label' => $company->label(),
      ]), 'status', FALSE);
    }
    $this->logger->notice(t('Company imported successfully: <a href=":url">:label</a>', [
      ':url' => $company->toUrl()->toString(),
      ':label' => $company->label(),
    ]));
  }

  /**
   * Poll for changes to companies in the Sync DB.
   */
  public function pollForChangesToCompanies() {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_company_sync');
    $updated_ids = $this->getUpdatedCompanyIdsFromSyncDb();
    foreach ($updated_ids as $updated_id) {
      // Only update existing companies, do not create.
      if ($this->doesCompanyExist($updated_id)) {
        $import_job = Job::create('syncdb_company_sync', [
          'company_id' => $updated_id,
        ]);
        $queue->enqueueJob($import_job);
      }
    }
  }

  /**
   * Get the IDs for all companies that have been updated since last run.
   */
  protected function getUpdatedCompanyIdsFromSyncDb() {
    $run_time = date('Y-m-d\TH:i:s');
    $all_ids = [];
    $requestVariables = new \stdClass();
    $requestedPage = 1;
    $requestVariables->requestedPage = $requestedPage;
    $last_run_time = $this->state->get('ipcsync_company_importer_last_run');
    $modifiedOnAfter = $last_run_time ? $last_run_time : $run_time;
    $requestVariables->modifiedOnAfter = $modifiedOnAfter;
    $config = $this->configFactory->get('ipc_syncdb.settings');
    $requestVariables->logLevel = $config->get('entities_api_log_level');
    $response = UserSync::getCompanyList($requestVariables);
    $this->apiHelper->processApiResponse($response, 'getCompanyList', 'modifiedOnAfter', $modifiedOnAfter);
    $response_list = $response['companyList'];

    while ($response_list) {
      $ids = array_column($response_list, 'accountId');
      $all_ids = array_merge($all_ids, $ids);
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $requestVariables->modifiedOnAfter = $modifiedOnAfter;
      $response = UserSync::getCompanyList($requestVariables);
      $this->apiHelper->processApiResponse($response, 'getCompanyList', 'modifiedOnAfter', $modifiedOnAfter);
      $response_list = $response['companyList'];
    }

    $this->state->set('ipcsync_company_importer_last_run', $run_time);
    return $all_ids;
  }

  /**
   * Checks whether or not the company exists in the Drupal db.
   *
   * @param int $syncdb_account_number
   *   The SyncDB account number of the company.
   */
  protected function doesCompanyExist(int $syncdb_account_number) {
    $storage = $this->entityTypeManager->getStorage('group');
    $companies = $storage->loadByProperties([
      'type' => 'company',
      'syncdb_account_number' => $syncdb_account_number,
    ]);
    if ($companies) {
      return TRUE;
    }
    return FALSE;
  }

}
