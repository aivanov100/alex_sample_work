<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ipc_syncdb\LicenseImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * A form for License Importer operations.
 *
 * @internal
 */
class LicenseImportForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The license importer.
   *
   * @var \Drupal\ipc_syncdb\LicenseImporter
   */
  protected $licenseImporter;

  /**
   * Constructs a new LicenseImportForm instance.
   *
   * @param \Drupal\ipc_syncdb\LicenseImporter $license_importer
   *   The license importer.
   */
  public function __construct(LicenseImporter $license_importer) {
    $this->licenseImporter = $license_importer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ipc_syncdb.license_importer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'license_import_form';
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
    $form['transaction_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transaction Id'),
      '#description' => t('Enter the Sync DB Id of the digital download transaction to import.'),
      '#required' => TRUE,
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
    $transaction_id = $form_state->getValue('transaction_id');
    $this->licenseImporter->importDigitalDownloadTransaction($transaction_id);
  }

}
