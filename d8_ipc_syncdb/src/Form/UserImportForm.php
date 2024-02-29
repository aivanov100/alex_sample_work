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

    $form['select_import'] = [
      '#type' => 'radios',
      '#title' => $this->t('Type of Import to Run'),
      '#options' => [
        'by_syncdb_id' => $this->t('By SyncDB ID'),
        'by_email' => $this->t('By User Email'),
      ],
      '#attributes' => [
        'name' => 'field_select_import',
      ],
    ];

    $form['sync_db_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Sync DB Id'),
      '#description' => t('Enter the Sync DB Id of the user to import. Good test values: "100" for a user with associated companies, "890" for a user with populated addressList.'),
      '#states' => [
        'visible' => [
          ':input[name="field_select_import"]' => ['value' => 'by_syncdb_id'],
        ],
      ],
    ];

    $form['user_email'] = [
      '#type' => 'email',
      '#title' => $this->t('User Email'),
      '#description' => t('Enter the email address of the user to import.'),
      '#states' => [
        'visible' => [
          ':input[name="field_select_import"]' => ['value' => 'by_email'],
        ],
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    switch ($input['field_select_import']) {
      case 'by_syncdb_id':
        $sync_db_id = $form_state->getValue('sync_db_id');
        $this->userImporter->importUser($sync_db_id);
        return;

      case 'by_email':
        $user_email = $form_state->getValue('user_email');
        $this->userImporter->importUserByEmail($user_email);
        return;
    }
  }

}
