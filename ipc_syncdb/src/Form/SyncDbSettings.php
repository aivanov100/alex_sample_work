<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\ipcsync\Form\IpcSyncSettings;

/**
 * Configure SyncDB Data Sync settings for this site.
 */
class SyncDbSettings extends IpcSyncSettings {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('ipc_syncdb.settings');

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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('ipc_syncdb.settings')
      ->set('export_orders_to_syncdb', $form_state->getValue('export_orders_to_syncdb'))
      ->set('import_orders_from_syncdb', $form_state->getValue('import_orders_from_syncdb'))
      ->save();
  }

}
