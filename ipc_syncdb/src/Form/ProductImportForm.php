<?php

namespace Drupal\ipc_syncdb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ipc_syncdb\ProductImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Contains a form for switching the view mode of a node during preview.
 *
 * @internal
 */
class ProductImportForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The inline form manager.
   *
   * @var \Drupal\ipc_syncdb\ProductImporter
   */
  protected $productImporter;

  /**
   * Constructs a new ProductImportForm instance.
   *
   * @param \Drupal\ipc_syncdb\ProductImporter $productImporter
   *   The product importer.
   */
  public function __construct(ProductImporter $productImporter) {
    $this->productImporter = $productImporter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ipc_syncdb.product_importer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'product_import_form';
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
        'single' => $this->t('Single Product'),
        'full' => $this->t('All Products (via IPC Product Sync Queue)'),
      ],
      '#attributes' => [
        'name' => 'field_select_import',
      ],
    ];

    $form['sync_db_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sync DB Id'),
      '#description' => t('Enter the Sync DB Id of the product to import. Good test values: "562" for Physical Product - Hard Copy, "4609" for Digital Product - Download, "6428" for Subscription Product, "5681" for Kit/Bundle Product.'),
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
        $this->productImporter->importProduct($sync_db_id);
        return;

      case 'full':
        $this->productImporter->enqueueAllProductsFromSyncDb();
        return;
    }
  }

}
