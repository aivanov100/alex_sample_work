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
    ];
    return $events;
  }

  /**
   * Does a postTransaction API call to IPC Transaction Api.
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

}
