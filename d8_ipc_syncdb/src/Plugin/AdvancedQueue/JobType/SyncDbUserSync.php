<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ipc_syncdb\UserImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides the job type for importing user data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "syncdb_user_sync",
 *   label = @Translation("Sync DB User Sync"),
 * )
 */
class SyncDbUserSync extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The user importer.
   *
   * @var \Drupal\ipc_syncdb\UserImporter
   */
  protected $userImporter;

  /**
   * Constructs a new SyncDbUserSync object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\ipc_syncdb\UserImporter $user_importer
   *   The user importer.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, UserImporter $user_importer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->userImporter = $user_importer;
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
      $container->get('ipc_syncdb.user_importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $user_storage = $this->entityTypeManager->getStorage('user');

    $payload = $job->getPayload();
    if (isset($payload['user_id']) && $user_id = $payload['user_id']) {
      // Import user by User ID.
      $this->userImporter->importUser($user_id);
      if (!$user_storage->loadByProperties(['syncdb_id' => $user_id])) {
        return JobResult::failure('User not saved correctly.', 31, 86400);
      }
    }
    elseif (isset($payload['user_email']) && $user_email = $payload['user_email']) {
      // Import user by email.
      $this->userImporter->importUserByEmail($user_email);
      if (!$user_storage->loadByProperties(['mail' => $user_email])) {
        return JobResult::failure('User not saved correctly.', 31, 86400);
      }
    }

    return JobResult::success();
  }

}
