<?php

namespace Drupal\ipc_syncdb\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ipc_syncdb\TransactionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the job type for importing transaction (order) data from Sync DB.
 *
 * @AdvancedQueueJobType(
 *   id = "ipc_syncdb_order_get_transaction",
 *   label = @Translation("Updates orders on the site using data from SyncDB"),
 * )
 */
class SyncDbOrderGetTransaction extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Service for making API calls to SyncDb.
   *
   * @var \Drupal\ipc_syncdb\TransactionManager
   */
  protected $transactionManager;

  /**
   * Constructs a new SyncDbOrderGetTransaction object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory service.
   * @param \Drupal\ipc_syncdb\TransactionManager $transaction_manager
   *   Service for making API calls to SyncDb.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, MessengerInterface $messenger, LoggerInterface $logger, ConfigFactoryInterface $config_factory, TransactionManager $transaction_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->transactionManager = $transaction_manager;
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
      $container->get('date.formatter'),
      $container->get('messenger'),
      $container->get('logger.channel.ipc_syncdb'),
      $container->get('config.factory'),
      $container->get('ipc_syncdb.transaction_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $order_id = $job->getPayload()['order_id'];
    $transaction_id = $job->getPayload()['transaction_id'];
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    if (!$order instanceof OrderInterface) {
      // Order with such ID was not found. We are skipping this transaction.
      return JobResult::success();
    }
    try {
      $result = $this->transactionManager->getTransaction($transaction_id);
      $config = $this->configFactory->get('ipc_syncdb.settings');
      if ($config->get('log_api_calls')) {
        $this->logger->debug($this->t('getTransaction API call to SyncDB.<br><b>transactionID:</b> <pre><code>@transaction_id</code></pre> <b>Response:</b> <pre><code>@response</code></pre>', [
          '@transaction_id' => print_r($transaction_id, TRUE),
          '@response' => print_r($result, TRUE),
        ]));
      }
      if (empty($result['responseInfo']) || $result['responseInfo']['responseMessage'] != 'Success' || empty($result['transaction'])) {
        return JobResult::failure('Transaction was not synced', 31, 86400);
      }
      $transaction = $result['transaction'];
      $save_order = FALSE;
      $status_mapping = TransactionManager::transactionOrderStatusMapping();
      $current_order_state = $order->getState()->getId();
      $transaction_status = $transaction['trasactionStatus']['transactionPostStatus'];

      // Check, update status.
      if ($status_mapping[$transaction_status] !== 'pending' && $status_mapping[$transaction_status] !== $current_order_state) {
        $transition_id = 'fulfill';
        if ($status_mapping[$transaction_status] === 'canceled') {
          $transition_id = 'cancel';
        }
        $order->getState()->applyTransitionById($transition_id);
        $save_order = TRUE;
      }

      // Check, create or remove line items.
      $transaction_line_items = !empty($transaction['lineItems']) ? $transaction['lineItems'] : [];
      $product_clmn = array_column($transaction_line_items, 'product');
      $transaction_product_numbers = array_column($product_clmn, 'productNumber');

      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      foreach ($order->getItems() as $order_item) {
        $purchased_entity = $order_item->getPurchasedEntity();
        $order_item_product_number = $purchased_entity instanceof PurchasableEntityInterface && !$purchased_entity->get('sku')
          ->isEmpty() ? $purchased_entity->get('sku')->value : $order_item->label();
        $key = array_search($order_item_product_number, $transaction_product_numbers);
        if ($key === FALSE) {
          $order->removeItem($order_item);
          $save_order = TRUE;
        }
        else {
          // We have already order item in the order.
          unset($transaction_product_numbers[$key]);
        }
      }

      // If there are not products in orders items, create generic order items.
      if (!empty($transaction_product_numbers)) {
        foreach ($transaction_line_items as $transaction_line_item) {
          if (in_array($transaction_line_item['product']['productNumber'], $transaction_product_numbers)) {
            $product_variation = $this->entityTypeManager->getStorage('commerce_product_variation')
              ->loadBySku($transaction_line_item['product']['productNumber']);
            $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
            if ($product_variation instanceof PurchasableEntityInterface) {
              $order_item = $order_item_storage->createFromPurchasableEntity($product_variation);
            }
            else {
              $order_item = $order_item_storage
                ->create([
                  'type' => 'default',
                  'title' => $transaction_line_item['product']['productNumber'],
                  'quantity' => $transaction_line_item['quantity'],
                  'unit_price' => $transaction_line_item['rate'],
                ]);
            }
            $order_item->save();
            $order->addItem($order_item);
            $save_order = TRUE;
          }
        }
      }
      // Save order only if there were changes.
      if ($save_order) {
        $order->save();
      }
      return JobResult::success();
    }
    catch (\Exception $exception) {
      return $result = JobResult::failure($exception->getMessage(), 31, 86400);
    }
  }

}
