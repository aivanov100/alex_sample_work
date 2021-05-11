<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ipc_syncdb\UserImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Contains a form for executing single-user import.
 *
 * @internal
 */
class UserImportForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The user importer.
   *
   * @var \Drupal\ipc_syncdb\UserImporter
   */
  protected $userImporter;

  /**
   * Constructs a new UserImportForm instance.
   *
   * @param \Drupal\ipc_syncdb\UserImporter $userImporter
   *   The user importer.
   */
  public function __construct(UserImporter $userImporter) {
    $this->userImporter = $userImporter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ipc_syncdb.user_importer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'user_import_form';
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

    $form['sync_db_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sync DB Id'),
      '#description' => t('Enter the Sync DB Id of the user to import. Good test values: "100" for a user with associated companies, "890" for a user with populated addressList.'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $sync_db_id = $form_state->getValue('sync_db_id');
    $this->userImporter->importUser($sync_db_id);
  }

}
