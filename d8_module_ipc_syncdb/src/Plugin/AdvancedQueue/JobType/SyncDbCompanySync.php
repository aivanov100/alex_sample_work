<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ipc_syncdb\CompanyImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides the job type for importing company data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "syncdb_company_sync",
 *   label = @Translation("Sync DB Company Sync"),
 * )
 */
class SyncDbCompanySync extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The company importer.
   *
   * @var \Drupal\ipc_syncdb\CompanyImporter
   */
  protected $companyImporter;

  /**
   * Constructs a new SyncDbCompanyImport object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\ipc_syncdb\CompanyImporter $companyImporter
   *   The company importer.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, CompanyImporter $companyImporter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->companyImporter = $companyImporter;
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
      $container->get('ipc_syncdb.company_importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $company_id = $job->getPayload()['company_id'];
    $this->companyImporter->importCompany($company_id);
    $storage = $this->entityTypeManager->getStorage('group');
    if (!$storage->loadByProperties(['syncdb_account_number' => $company_id])) {
      return JobResult::failure('Company not saved correctly.', 31, 86400);
    }
    return JobResult::success();
  }

}
