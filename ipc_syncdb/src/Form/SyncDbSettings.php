<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure SyncDB Data Sync settings for this site.
 */
class SyncDbSettings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'ipc_syncdb.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ipc_syncdb_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ipc_syncdb.settings');

    $form['group_transaction_api'] = [
      '#type' => 'fieldset',
      '#title' => t('Transaction API Settings'),
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
    ];
    $form['group_transaction_api']['export_orders_to_syncdb'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable post transaction to SyncDB'),
      '#description' => $this->t('Export orders to SyncDB after an order is placed'),
      '#default_value' => $config->get('export_orders_to_syncdb'),
    ];
    $form['group_transaction_api']['import_orders_from_syncdb'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Import salesorder transactions from SyncDB'),
      '#description' => $this->t('Update orders based on transaction data from SyncDB'),
      '#default_value' => $config->get('import_orders_from_syncdb'),
    ];
    $form['group_transaction_api']['transaction_api_log_level'] = [
      '#type' => 'select',
      '#title' => $this->t('IPCTransactionAPI logLevel'),
      '#description' => $this->t('Pass logLevel when making IPCTransactionAPI calls'),
      '#default_value' => $config->get('transaction_api_log_level') ?? 'Error',
      '#options' => [
        'All' => 'All',
        'Debug' => 'Debug',
        'Info' => 'Info',
        'Warn' => 'Warn',
        'Error' => 'Error',
        'Fatal' => 'Fatal',
        'Off' => 'Off',
      ],
    ];
    $form['group_transaction_api']['log_transaction_api_calls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log Full Requests/Responses for IPCTransactionAPI calls'),
      '#description' => $this->t('All data from request and response will be logged'),
      '#default_value' => $config->get('log_transaction_api_calls'),
    ];

    $form['group_entities_api'] = [
      '#type' => 'fieldset',
      '#title' => t('Entities API Settings'),
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
    ];
    $form['group_entities_api']['entities_api_log_level'] = [
      '#type' => 'select',
      '#title' => $this->t('IPCEntitiesAPI logLevel'),
      '#description' => $this->t('Pass logLevel when making IPCEntitiesAPI calls'),
      '#default_value' => $config->get('entities_api_log_level') ?? 'Error',
      '#options' => [
        'All' => 'All',
        'Debug' => 'Debug',
        'Info' => 'Info',
        'Warn' => 'Warn',
        'Error' => 'Error',
        'Fatal' => 'Fatal',
        'Off' => 'Off',
      ],
    ];
    $form['group_entities_api']['log_entities_api_calls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log Full Requests/Responses for IPCEntitiesAPI calls'),
      '#description' => $this->t('All data from request and response will be logged'),
      '#default_value' => $config->get('log_entities_api_calls'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('ipc_syncdb.settings')
      ->set('export_orders_to_syncdb', $form_state->getValue('export_orders_to_syncdb'))
      ->set('import_orders_from_syncdb', $form_state->getValue('import_orders_from_syncdb'))
      ->set('log_transaction_api_calls', $form_state->getValue('log_transaction_api_calls'))
      ->set('transaction_api_log_level', $form_state->getValue('transaction_api_log_level'))
      ->set('entities_api_log_level', $form_state->getValue('entities_api_log_level'))
      ->set('log_entities_api_calls', $form_state->getValue('log_entities_api_calls'))
      ->save();
  }

}
