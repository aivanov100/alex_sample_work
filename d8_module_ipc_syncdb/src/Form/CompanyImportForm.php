<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ipc_syncdb\CompanyImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Contains a form for executing company import from SyncDB.
 *
 * @internal
 */
class CompanyImportForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The company importer.
   *
   * @var \Drupal\ipc_syncdb\CompanyImporter
   */
  protected $companyImporter;

  /**
   * Constructs a new CompanyImportForm instance.
   *
   * @param \Drupal\ipc_syncdb\CompanyImporter $companyImporter
   *   The company importer.
   */
  public function __construct(CompanyImporter $companyImporter) {
    $this->companyImporter = $companyImporter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ipc_syncdb.company_importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'company_import_form';
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
      '#title' => $this->t('Type of Import to Run'),
      '#options' => [
        'single' => $this->t('Single Company'),
        'full' => $this->t('All Companies (via IPC Company Sync Queue)'),
      ],
      '#attributes' => [
        'name' => 'field_select_import',
      ],
    ];

    $form['sync_db_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sync DB Id'),
      '#description' => t('Enter the SyncDB Customer Account Id of the company to import. Good test value: "170".'),
      '#states' => [
        'visible' => [
          ':input[name="field_select_import"]' => ['value' => 'single'],
        ],
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run Import'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    switch ($input['field_select_import']) {
      case 'single':
        $sync_db_id = $form_state->getValue('sync_db_id');
        $this->companyImporter->importCompany($sync_db_id);
        return;

      case 'full':
        $this->companyImporter->enqueueAllCompaniesFromSyncDb();
        return;
    }
  }

}
