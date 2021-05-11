<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ipc_syncdb\ProductImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\ipc_syncdb\UserImporter;
use Drupal\ipc_syncdb\CompanyImporter;

/**
 * Form to manage IPC SyncDB Data Sync operations.
 *
 * @internal
 */
class SyncDbDataSyncForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The inline form manager.
   *
   * @var \Drupal\ipc_syncdb\ProductImporter
   */
  protected $productImporter;

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
   * Constructs a new SyncDbDataSyncForm instance.
   *
   * @param \Drupal\ipc_syncdb\ProductImporter $productImporter
   *   The product importer.
   * @param \Drupal\ipc_syncdb\UserImporter $userImporter
   *   The user importer.
   * @param \Drupal\ipc_syncdb\CompanyImporter $companyImporter
   *   The company importer.
   */
  public function __construct(ProductImporter $productImporter, UserImporter $userImporter, CompanyImporter $companyImporter) {
    $this->productImporter = $productImporter;
    $this->userImporter = $userImporter;
    $this->companyImporter = $companyImporter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ipc_syncdb.product_importer'),
      $container->get('ipc_syncdb.user_importer'),
      $container->get('ipc_syncdb.company_importer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sync_db_data_sync_form';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['select_import'] = [
      '#type' => 'radios',
      '#title' => $this->t('Trigger polling for one of the data importers'),
      '#options' => [
        'user' => $this->t('User Importer'),
        'company' => $this->t('Company Importer'),
        'product' => $this->t('Product Importer'),
      ],
      '#attributes' => [
        'name' => 'field_select_import',
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Execute'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    // @todo Instantiate userImporter and companyImporter for this class.
    switch ($input['field_select_import']) {
      case 'user':
        $this->userImporter->pollForChangesToUsers();
        return;

      case 'company':
        $this->companyImporter->pollForChangesToCompanies();
        return;

      case 'product':
        $this->productImporter->pollForChangesToProducts();
        return;
    }
  }

}
