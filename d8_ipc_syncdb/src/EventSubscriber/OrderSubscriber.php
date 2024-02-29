<?php

namespace Drupal\ipc_syncdb\EventSubscriber;

use Drupal\advancedqueue\Job;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event Subscriber for Commerce events related to Transactions.
 */
class OrderSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new TransactionSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [
      'commerce_order.place.post_transition' => [
        'enqueueOrderToSendTransaction',
        -200,
      ],
      'commerce_invoice.confirm.post_transition' => [
        'enqueueInvoiceToSendTransaction',
        -200,
      ],
    ];
    return $events;
  }

  /**
   * Creates a job for an order in the IPC Transaction Sync queue.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function enqueueOrderToSendTransaction(WorkflowTransitionEvent $event) {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    if (!$config->get('export_orders_to_syncdb')) {
      return;
    }
    $order = $event->getEntity();
    $order_sync_job = Job::create('ipc_syncdb_order_post_transaction', [
      'order_id' => $order->id(),
    ]);
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_transaction_sync');
    $queue->enqueueJob($order_sync_job);
  }

  /**
   * Creates a job for a quote invoice in the IPC Transaction Sync queue.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function enqueueInvoiceToSendTransaction(WorkflowTransitionEvent $event) {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    if (!$config->get('export_orders_to_syncdb')) {
      return;
    }
    /** @var \Drupal\commerce_invoice\Entity\Invoice $invoice */
    $invoice = $event->getEntity();
    /** @var \Drupal\commerce_order\Entity\OrderInterface[] $orders */
    $orders = $invoice->getOrders();

    if ($orders) {
      $order = reset($orders);
      $order_state = $order->getState()->getId();

      if ($invoice->bundle() == 'quote' && $order_state == 'quoted') {
        $invoice_sync_job = Job::create('ipc_syncdb_order_post_transaction', [
          'invoice_id' => $invoice->id(),
        ]);

        $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
        /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
        $queue = $queue_storage->load('ipc_transaction_sync');
        $queue->enqueueJob($invoice_sync_job);
      }
    }
  }

}
