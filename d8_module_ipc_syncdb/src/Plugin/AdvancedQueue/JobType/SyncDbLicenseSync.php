<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ipc_syncdb\LicenseImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides the job type for importing download license data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "syncdb_license_sync",
 *   label = @Translation("Sync DB License Sync"),
 * )
 */
class SyncDbLicenseSync extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The license importer.
   *
   * @var \Drupal\ipc_syncdb\LicenseImporter
   */
  protected $licenseImporter;

  /**
   * Constructs a new SyncDbLicenseSync object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\ipc_syncdb\LicenseImporter $license_importer
   *   The license importer.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, LicenseImporter $license_importer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->licenseImporter = $license_importer;
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
      $container->get('ipc_syncdb.license_importer')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $this->licenseImporter->importDigitalDownloadTransaction($payload['transaction_id']);
    return JobResult::success();
  }

}
