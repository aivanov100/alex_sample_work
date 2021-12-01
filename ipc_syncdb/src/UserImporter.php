<?php

namespace Drupal\ipc_syncdb;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\ipcsync\Utilities\UserSync;
use Psr\Log\LoggerInterface;
use Drupal\user\Entity\User;
use Drupal\profile\Entity\Profile;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\advancedqueue\Job;
use Drupal\commerce_cart\CartProviderInterface;

/**
 * Imports users from Sync DB via IPCEntitiesApi.
 */
class UserImporter {

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
   * The logger instance.
   *
   * @var \Drupal\ipc_syncdb\CompanyImporter
   */
  protected $companyImporter;

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
   * The cart provider.
   *
   * @var \Drupal\commerce_cart\CartProviderInterface
   */
  protected $cartProvider;

  /**
   * Constructs a new UserImporter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\ipc_syncdb\CompanyImporter $company_importer
   *   The company importer.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\commerce_cart\CartProviderInterface $cart_provider
   *   The cart provider.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, LoggerInterface $logger, CompanyImporter $company_importer, StateInterface $state, ConfigFactoryInterface $config_factory, CartProviderInterface $cart_provider) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->companyImporter = $company_importer;
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->cartProvider = $cart_provider;
  }

  /**
   * Imports user with the corresponding SyncDB ID via IPCEntitiesAPI.
   *
   * @param int $sync_db_id
   *   The SyncDB ID of the user.
   *
   * @return \Drupal\user\UserInterface|false
   *   The imported user.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function importUser($sync_db_id) {
    $requestVariables = new \stdClass();
    $requestVariables->userId = $sync_db_id;
    $response_user = UserSync::getUser($requestVariables);
    if ($response_user['user']) {
      $user = $this->importDataForUser($response_user['user']);
      return $user;
    }
    return FALSE;
  }

  /**
   * Imports user with the corresponding email via IPCEntitiesAPI.
   *
   * @param string $user_email
   *   The email of the user.
   *
   * @return \Drupal\user\UserInterface|false
   *   The imported user.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function importUserByEmail($user_email) {
    $requestVariables = new \stdClass();
    $requestVariables->email = $user_email;
    $response_user = UserSync::getUserByEmail($requestVariables);
    if ($response_user['user']) {
      $user = $this->importDataForUser($response_user['user']);
      return $user;
    }
    return FALSE;
  }

  /**
   * Imports data for the given user.
   *
   * @param array $user_from_response
   *   The product data from the Api response.
   *
   * @return \Drupal\user\UserInterface|false
   *   The imported user.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function importDataForUser(array $user_from_response) {
    $user = $this->createOrLoadUser($user_from_response);
    $requestVariables = new \stdClass();
    $requestVariables->userId = $user_from_response['userId'];
    $response_company_affiliation_list = UserSync::getUserCompanyAffiliationListByUserId($requestVariables);
    $this->removeStrayCompanyRelationships($user, $user_from_response);

    $userCompanyAffiliationList = $response_company_affiliation_list['userCompanyAffiliationList'];
    foreach ($userCompanyAffiliationList as $record) {
      $company = $this->companyImporter->createOrUpdateCompany($record['company']);
      $values = ['group_roles' => ['target_id' => 'company-contact']];
      $company->addMember($user, $values);
      $this->logger->notice(t('Processed user membership for Group: <a href=":url">:label</a>', [
        ':url' => $company->toUrl()->toString(),
        ':label' => $company->label(),
      ]));
    }

    $this->setUserFields($user, $user_from_response);
    $this->displayUserImportSuccessMessage($user);

    return $user;
  }

  /**
   * Creates or updates a user with data obtained from SyncDB.
   *
   * @param array $user_from_response
   *   The user from the Api response.
   *
   * @return \Drupal\user\UserInterface
   *   The user.
   */
  public function createOrLoadUser(array $user_from_response) {
    $user = $this->getUserIfUserExists($user_from_response);
    if (!$user) {
      $user = User::create([
        'name' => $user_from_response['email'],
        'mail' => $user_from_response['email'],
      ]);
      $user->activate();
      $user->save();
    }
    return $user;
  }

  /**
   * Returns user if user that matches target identifiers exists.
   *
   * @param array $user_from_response
   *   The user data from the Api response.
   */
  protected function getUserIfUserExists(array $user_from_response) {
    $storage = $this->entityTypeManager->getStorage('user');
    $users = $storage->loadByProperties([
      'mail' => $user_from_response['email'],
    ]);
    if ($users) {
      $user = reset($users);
      return $user;
    }
    return FALSE;
  }

  /**
   * Sets the user fields with values retrieved via Api call.
   *
   * @param Drupal\user\Entity\User $user
   *   The user.
   * @param array $user_from_response
   *   The user from the Api response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setUserFields(User &$user, array $user_from_response) {
    $user->set('syncdb_id', $user_from_response['userId']);
    $user->set('netsuite_id', $user_from_response['nsUserId']);
    $primary_company = $this->companyImporter->getCompanyIfCompanyExists(['accountId' => $user_from_response['accountId']]);
    if ($primary_company) {
      $user->set('primary_company', ['target_id' => $primary_company->id()]);
    }
    $this->setPriceLevelField($user, $user_from_response);
    $this->updateCustomerProfiles($user, $user_from_response);
    $this->setAvataxFields($user, $user_from_response);
    $user->save();
  }

  /**
   * Sets the price_level field.
   *
   * @param Drupal\user\Entity\User $user
   *   The user.
   * @param array $user_from_response
   *   The user from the Api response.
   */
  protected function setPriceLevelField(User &$user, array $user_from_response) {
    switch ($user_from_response['individualPriceLevel']['priceLevel']) {
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
    $user->set('price_level', $price_level);
  }

  /**
   * Sets the Avatax fields.
   *
   * @param Drupal\user\Entity\User $user
   *   The user.
   * @param array $user_from_response
   *   The user from the Api response.
   */
  protected function setAvataxFields(User &$user, array $user_from_response) {
    $user->set('avatax_tax_exemption_number', $user_from_response['exemptionCertificateNumber']);
    $user->set('avatax_customer_code', $user_from_response['nsUserId']);
  }

  /**
   * Creates/updates customer profiles with addresses retrieved via Api call.
   *
   * @param Drupal\user\Entity\User $user
   *   The user.
   * @param array $user_from_response
   *   The user from the Api response.
   */
  protected function updateCustomerProfiles(User &$user, array $user_from_response) {
    $requestVariables = new \stdClass();
    $requestVariables->userId = $user_from_response['userId'];
    $response_address_list = UserSync::getUserAddressList($requestVariables);
    foreach ($response_address_list['addressList'] as $address_from_response) {
      $profile = $this->updateProfile($user->id(), $address_from_response);
    }
  }

  /**
   * Creates or updates a profile with data obtained from SyncDB.
   *
   * @param string $uid
   *   The uid of the user associated with the profile.
   * @param array $address_from_response
   *   The address data from the Api response.
   *
   * @return Drupal\profile\Entity\Profile
   *   The profile.
   */
  public function updateProfile(string $uid, array $address_from_response) {
    $profile = $this->getProfileIfProfileExists($uid, $address_from_response);
    if (!$profile) {
      $profile = Profile::create([
        'type' => 'customer',
        'syncdb_id' => $address_from_response['addressId'],
        'uid' => $uid,
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
      'organization' => $address_from_response['addressee'],
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
    $profile->set('phone_number', $address_from_response['phone']);
    $profile->set('address_attention_to', $address_from_response['attention']);
    $profile->save();
  }

  /**
   * Returns profile if profile that matches target identifiers exists.
   *
   * @param string $uid
   *   The uid of the user associated with the profile.
   * @param array $address_from_response
   *   The address data from the Api response.
   */
  public function getProfileIfProfileExists(string $uid, array $address_from_response) {
    $storage = $this->entityTypeManager->getStorage('profile');
    $profiles = $storage->loadByProperties([
      'type' => 'customer',
      'syncdb_id' => $address_from_response['addressId'],
      'uid' => $uid,
    ]);
    if ($profiles) {
      $profile = reset($profiles);
      return $profile;
    }
    return FALSE;
  }

  /**
   * Displays User Import success message to the user.
   *
   * @param Drupal\user\Entity\User $user
   *   The user.
   */
  protected function displayUserImportSuccessMessage(User $user) {
    $account = \Drupal::currentUser();
    if ($account->hasPermission('import ipcsync user')) {
      $this->messenger->addMessage(t('User imported successfully: <a href=":url">:username</a>', [
        ':url' => $user->toUrl()->toString(),
        ':username' => $user->getUsername(),
      ]), 'status', FALSE);
    }
    $this->logger->notice(t('User imported successfully: <a href=":url">:username</a>', [
      ':url' => $user->toUrl()->toString(),
      ':username' => $user->getUsername(),
    ]));
  }

  /**
   * Poll for changes to users in the Sync DB.
   */
  public function pollForChangesToUsers() {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_user_sync');
    $updated_ids = $this->getUpdatedUserIdsFromSyncDb();
    foreach ($updated_ids as $updated_id) {
      $import_job = Job::create('syncdb_user_sync', [
        'user_id' => $updated_id,
      ]);
      $queue->enqueueJob($import_job);
    }
  }

  /**
   * Get the user IDs for all users that have been updated since last run.
   */
  protected function getUpdatedUserIdsFromSyncDb() {
    $run_time = date('Y-m-d\TH:i:s');
    $all_ids = [];

    $requestVariables = new \stdClass();
    $requestedPage = 1;
    $requestVariables->requestedPage = $requestedPage;
    $last_run_time = $this->state->get('ipcsync_user_importer_last_run');
    $modifiedOnAfter = $last_run_time ? $last_run_time : ApiHelper::POLLING_ROUTINE_START_TIME;
    $requestVariables->modifiedOnAfter = $modifiedOnAfter;

    $response = UserSync::getUserList($requestVariables);
    $response_list = $response['userList'];
    while ($response_list) {
      $ids = array_column($response_list, 'userId');
      $all_ids = array_merge($all_ids, $ids);
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $response = UserSync::getUserList($requestVariables);
      $response_list = $response['userList'];
    }

    $all_ids_filtered = [];
    foreach ($all_ids as $sync_db_id) {
      $storage = $this->entityTypeManager->getStorage('user');
      $users = $storage->loadByProperties([
        'syncdb_id' => $sync_db_id,
      ]);
      if ($users) {
        $all_ids_filtered[] = $sync_db_id;
      }
    }
    $this->state->set('ipcsync_user_importer_last_run', $run_time);
    return $all_ids_filtered;
  }

  /**
   * Remove old company relationship if a user's primary_company changes.
   *
   * @param Drupal\user\Entity\User $user
   *   The user.
   * @param array $user_from_response
   *   The user from the Api response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function removeStrayCompanyRelationships(User &$user, array $user_from_response) {
    $primary_company = $user->get('primary_company')->getValue();
    if (!empty($primary_company[0]['target_id'])) {
      $primary_company_id = $primary_company[0]['target_id'];
      /** @var Drupal\group\Entity\Group $company */
      $company = $this->entityTypeManager->getStorage('group')->load($primary_company_id);
      if (!$company->get('syncdb_account_number')->isEmpty()) {
        $syncdb_account_number = $company->get('syncdb_account_number')->value;

        // Check if the stored primary company matches that from API response.
        if ((int) $syncdb_account_number !== (int) $user_from_response['accountId']) {
          // Remove association with old company.
          $company->removeMember($user);
          $company->save();
          // Purge shopping carts for this user.
          /** @var \Drupal\commerce_order\Entity\OrderInterface[] $carts */
          $carts = $this->cartProvider->getCarts($user);
          foreach ($carts as $cart) {
            $cart->delete();
          }
        }
      }
    }
  }

  /**
   * Creates a job for IPC User Sync queue to import a specific user.
   *
   * @param string $user_email
   *   The email of the user.
   */
  public function enqueueUserForImportByEmail(string $user_email) {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_user_sync');
    $import_job = Job::create('syncdb_user_sync', [
      'user_email' => $user_email,
    ]);
    $queue->enqueueJob($import_job);
  }

}
