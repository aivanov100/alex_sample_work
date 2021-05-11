<?php

namespace Drupal\ipc_syncdb;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\PayflowInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\State;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ipcsync\Api;
use Drupal\ipcsync\Utilities\Transaction;
use Drupal\physical\Calculator;
use Psr\Log\LoggerInterface;

/**
 * Handles IPCTransactionAPI integration.
 */
class TransactionManager {

  use StringTranslationTrait;

  const NONMEMBER_PRICE_LEVEL = 1;
  const MEMBER_PRICE_LEVEL = 2;
  const DISTRIBUTOR_PRICE_LEVEL = 3;

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
   * The State service.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * The api helper.
   *
   * @var \Drupal\ipc_syncdb\ApiHelper
   */
  protected $apiHelper;

  /**
   * Constructs a new TransactionSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory service.
   * @param \Drupal\Core\State\State $state
   *   The State service.
   * @param \Drupal\ipc_syncdb\ApiHelper $api_helper
   *   The api helper.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, MessengerInterface $messenger, LoggerInterface $logger, ConfigFactoryInterface $config_factory, State $state, ApiHelper $api_helper) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->apiHelper = $api_helper;
  }

  /**
   * Does a postTransaction API call to IPC Transaction Api.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   Result.
   */
  public function postTransaction(OrderInterface $order) {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    $status = 'failed';

    if (!$config->get('export_orders_to_syncdb')) {
      return $status;
    }
    if (!$this->checkOrderTotalMatchesExpectedTotal($order)) {
      return $status;
    }

    $json = $this->createJsonForPostTransaction($order);
    $requestParams = new \stdClass();
    $requestParams->logLevel = $config->get('transaction_api_log_level');
    $error_message = '';

    try {
      $response = Transaction::postTransaction($requestParams, $json);
      if ($response['responseInfo']['responseMessage'] == 'Success') {
        $order->set('syncdb_id', $response['transactionId']);
        $order->save();
        $this->logger->notice($this->t('Successfully completed postTransaction API Call. Transaction ID: @id', ['@id' => $response['transactionId']]));
        $status = 'success';
      }
      elseif ($response['responseInfo']['responseMessage'] == 'Error') {
        $this->logger->error($this->t('Received Error Response Message when attempting postTransaction API call to SyncDB.<br><b>Request params:</b> <pre><code>@request_params</code></pre> <b>Body:</b> <pre><code>@body</code></pre> <b>Response:</b> <pre><code>@response</code></pre>', [
          '@request_params' => print_r($requestParams, TRUE),
          '@body' => print_r(Json::decode($json, TRUE), TRUE),
          '@response' => !empty($response) ? print_r($response, TRUE) : $error_message,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('There was an Exception when attempting postTransaction API Call.'));
      $error_message = $e->getMessage();
      $this->logger->error($this->t('There was an Exception when attempting postTransaction API Call. The status code is @responseCode @responseMessage.', [
        '@responseCode' => $e->getCode(),
        '@responseMessage' => $error_message,
      ]));
    }
    finally {
      if ($config->get('log_transaction_api_calls')) {
        $this->apiHelper->generateDetailedLogMessage('postTransaction', $requestParams, $response, $json);
      }
    }

    return $status;
  }

  /**
   * Check whether the order total matches the expected total.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return bool
   *   Result.
   */
  protected function checkOrderTotalMatchesExpectedTotal(OrderInterface $order) {
    // Total = subtotal + taxTotal + shipCost + handlingCost - giftCertificate.
    // @todo Add giftCertificate to $expected_total equation.
    $total = Calculator::trim($order->getTotalPrice()->getNumber());
    $subtotal = Calculator::trim($order->getSubtotalPrice()->getNumber());
    $shipping_price = NULL;
    foreach ($order->getAdjustments(['shipping']) as $adjustment) {
      $shipping_price = $shipping_price ? $shipping_price->add($adjustment->getAmount()) : $adjustment->getAmount();
    }
    $shipping_price = empty($shipping_price) ? '0' : Calculator::trim($shipping_price->getNumber());
    $handling_price = NULL;
    foreach ($order->getAdjustments(['fee']) as $adjustment) {
      $handling_price = $handling_price ? $handling_price->add($adjustment->getAmount()) : $adjustment->getAmount();
    }
    $handling_price = empty($handling_price) ? '0' : Calculator::trim($handling_price->getNumber());
    $tax_total = 0;
    foreach ($this->getLineItems($order) as $lineItem) {
      $tax_total = Calculator::add($tax_total, $lineItem['taxAmount']);
    }
    $tax_total = Calculator::round($tax_total, 2);
    $expected_total = Calculator::add(Calculator::add(Calculator::add($subtotal, $tax_total), $shipping_price), $handling_price);
    if (Calculator::compare($total, $expected_total) != 0) {
      $this->logger->error(t('Error with Order number: @order_id. Order total does not match expected total.', [
        '@order_id' => $order->id(),
      ]));
      $this->messenger->addError(t('Error with Order number: @order_id. Order total does not match expected total.', [
        '@order_id' => $order->id(),
      ]));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Helper function to create JSON containing data for order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return false|string
   *   The JSON-encoded string containing data.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function createJsonForPostTransaction(OrderInterface $order) {
    $payment_gateway = $order->get('payment_gateway')->getValue();
    $payment_gateway = $payment_gateway[0]['target_id'];
    $config = $this->configFactory->get('ipc_syncdb.settings');

    $required_fields = [
      'externalId' => $order->id(),
      // Identifier for the type of order, e.g. Sales Order = "salesorder",
      // Quote = "estimate", Payment = "payment".
      'type' => 'salesorder',
      // Sync DB internal ID of the customer company (entity).
      'customerId' => $this->getCustomerId($order),
      // Sync DB internal ID of the end user.
      'endUserId' => $this->getEndUserId($order),
      // The date/time of the transaction.
      'transDate' => $this->getTransDate($order),
      // CC Order/Payment/Quote status, e.g. "Pending Approval",
      // "Deposited", "Pending Approval", respectively.
      'orderStatus' => 'Pending Fulfillment',
      // The exchange rate is 1 for all USD transactions.
      'currency' => 'US Dollar',
      'exchangeRate' => 1,
      'total' => Calculator::trim($order->getTotalPrice()->getNumber()),
      'subtotal' => Calculator::trim($order->getSubtotalPrice()->getNumber()),
      'billingAddress' => $this->getBillingAddress($order),
      'shippingAddress' => $this->getShippingAddress($order),
      'lineItems' => $this->getLineItems($order),
      'billToTierId' => $this->getBillToTierId($order),
      'logLevel' => $config->get('transaction_api_log_level'),
    ];

    $this->addShippingHandlingFields($required_fields, $order);
    $this->addCustomsFields($required_fields, $order);
    $this->addTaxTotalField($required_fields, $order);

    if ($this->getPriceLevel($order) == self::DISTRIBUTOR_PRICE_LEVEL) {
      $required_fields['distributorId'] = $this->getCustomerId($order);
    }

    if ($payment_gateway == 'ipc_purchase_orders') {
      // Add fields specific to orders paid by Purchase Order.
      $this->addPurchaseOrderFields($required_fields, $order);
    }
    else {
      // Add fields specific to orders NOT paid by Purchase Order.
      $this->addCcPaymentFields($required_fields, $order);
    }

    return Json::encode($required_fields);
  }

  /**
   * Add fields related to shipping and handling.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function addShippingHandlingFields(array &$fields, OrderInterface $order) {
    // Fix 'ValidatePostAddresses:Shipping Address NOT Found'.
    if (empty($fields['shippingAddress'])) {
      $fields['shippingAddress'] = $fields['billingAddress'];
    }

    /** @var \Drupal\commerce_price\Price $shipping_price */
    $shipping_price = NULL;
    foreach ($order->getAdjustments(['shipping']) as $adjustment) {
      $shipping_price = $shipping_price ? $shipping_price->add($adjustment->getAmount()) : $adjustment->getAmount();
    }
    $fields['shipCost'] = empty($shipping_price) ? '0' : Calculator::trim($shipping_price->getNumber());

    /** @var \Drupal\commerce_price\Price $handling_price */
    $handling_price = NULL;
    foreach ($order->getAdjustments(['handling_fee']) as $adjustment) {
      $handling_price = $handling_price ? $handling_price->add($adjustment->getAmount()) : $adjustment->getAmount();
    }
    $required_fields['handlingCost'] = empty($handling_price) ? '0' : Calculator::trim($handling_price->getNumber());
    $fields['handlingCost'] = empty($handling_price) ? '0' : Calculator::trim($handling_price->getNumber());

    $shipments = $order->get('shipments')->referencedEntities();
    if ($shipments) {
      $shipment = reset($shipments);
      $attention_to = $shipment->get('attention_to')->getValue();
      if ($attention_to) {
        $fields['shippingAttentionTo'] = $attention_to[0]['value'];
      }
    }
  }

  /**
   * Gets the Purchase Order fields.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function addPurchaseOrderFields(array &$fields, OrderInterface $order) {
    $payment_method_value = $order->get('payment_method')->getValue();
    $payment_method_id = $payment_method_value[0]['target_id'];
    $payment_method_storage = $this->entityTypeManager->getStorage('commerce_payment_method');
    $payment_method = $payment_method_storage->load($payment_method_id);

    $po_number = $payment_method->get('po_number')->getValue();
    $po_number = $po_number[0]['value'];
    $po_billing_email = $payment_method->get('po_billing_email')->getValue();
    $po_billing_email = $po_billing_email[0]['value'];
    $po_additional_info = $payment_method->get('po_additional_info')->getValue();
    $po_additional_info = $po_additional_info[0]['value'];

    $po_file_value = $payment_method->get('po_file')->getValue();
    $po_file_id = $po_file_value[0]['target_id'];
    $file_storage = $this->entityTypeManager->getStorage('file');
    $po_file = $file_storage->load($po_file_id);
    $po_filename = $po_file->getFilename();

    $fields = array_merge($fields, [
      'otherRefNum' => $po_number,
      'purchaseOrderFileName' => $po_filename,
      'billingContactEmail' => $po_billing_email,
      'billingInstructions' => $po_additional_info,
    ]);
  }

  /**
   * Add fields related to international shipping and customs information.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function addCustomsFields(array &$fields, OrderInterface $order) {
    /** @var \Drupal\commerce_shipping\Entity\Shipment[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();
    if ($shipments) {
      /** @var \Drupal\commerce_shipping\Entity\Shipment $shipment */
      $shipment = reset($shipments);

      if (!$shipment->get('customs_note')->isEmpty()) {
        $fields['includeEPOnlyNote'] = (bool) $shipment->get('customs_note')->value;
      }
      if (!$shipment->get('customs_state_bill_recipient')->isEmpty()) {
        $fields['billRecipientForTaxes'] = (bool) $shipment->get('customs_state_bill_recipient')->value;
      }
      if (!$shipment->get('customs_include_tax_id')->isEmpty()) {
        $fields['includeVATTaxFiscalId'] = (bool) $shipment->get('customs_include_tax_id')->value;
      }
      if (!$shipment->get('customs_tax_id')->isEmpty()) {
        $fields['vatTaxFiscalId'] = $shipment->get('customs_tax_id')->value;
      }
      if (!$shipment->get('customs_certificate')->isEmpty()) {
        $fields['certificateOfOrigin'] = (bool) $shipment->get('customs_certificate')->value;
      }
    }
  }

  /**
   * Add Credit Card payment fields to the request.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function addCcPaymentFields(array &$fields, OrderInterface $order) {
    // @todo Add support for gift certificate (it will change the $cc_paid_amount).
    $cc_paid_amount = Calculator::trim($order->getTotalPrice()->getNumber());
    $fields['ccPaidAmount'] = $cc_paid_amount;

    $order_payments = $this->entityTypeManager->getStorage('commerce_payment')->loadMultipleByOrder($order);
    if (!empty($order_payments)) {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = reset($order_payments);
      $remote_id = $payment->getRemoteId();
      if ($payment->getPaymentGateway() instanceof PayflowInterface) {
        // See Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\Payflow::getAuthorizationCode.
        $remote_id = (strpos($remote_id, '|') !== FALSE) ? explode('|', $remote_id)[1] : $remote_id;
      }
      $fields = array_merge($fields, [
        'ccPaymentAuth' => $remote_id,
        'ccPaymentAmount' => Calculator::trim($payment->getAmount()->getNumber()),
        'ccPaymentHold' => FALSE,
      ]);
    }
  }

  /**
   * Add fields related to shipping and handling.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function addTaxTotalField(array &$fields, OrderInterface $order) {
    $tax_total = 0;
    foreach ($this->getLineItems($order) as $lineItem) {
      $tax_total = Calculator::add($tax_total, $lineItem['taxAmount']);
    }
    $tax_total = Calculator::round($tax_total, 2);
    $fields['taxTotal'] = $tax_total;
  }

  /**
   * Gets customerId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   Value for 'customerId' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getCustomerId(OrderInterface $order) {
    if ($company_group = $this->getUserCompanyGroup($order)) {
      if (!$company_group->get('syncdb_account_number')->isEmpty()) {
        return $company_group->get('syncdb_account_number')->value;
      }
    }
    else {
      return $this->getEndUserId($order);
    }
  }

  /**
   * Get the primary Company Group for a user.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\group\Entity\Group
   *   The company group.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getUserCompanyGroup(OrderInterface $order) {
    $user = $order->getCustomer();
    if (!$user->get('primary_company')->isEmpty()) {
      return $user->get('primary_company')->entity;
    }
    return NULL;
  }

  /**
   * Gets priceLevelId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   Value for 'priceLevelId' in lineItem.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getPriceLevel(OrderInterface $order) {
    $user = $order->getCustomer();
    $mapping = [
      'nonmember' => self::NONMEMBER_PRICE_LEVEL,
      'member' => self::MEMBER_PRICE_LEVEL,
      'distributor' => self::DISTRIBUTOR_PRICE_LEVEL,
    ];
    if ($company_group = $this->getUserCompanyGroup($order)) {
      $price_level_field = $company_group->get('price_level');
      if (!$price_level_field->isEmpty()) {
        return $mapping[$price_level_field->value];
      }
    }
    else {
      $price_level_field = $user->get('price_level');
      if (!$price_level_field->isEmpty()) {
        return $mapping[$price_level_field->value];
      }
    }

    return '1';
  }

  /**
   * Gets endUserId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   Value for 'endUserId' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getEndUserId(OrderInterface $order) {
    $user = $order->getCustomer();
    if (!$user->get('syncdb_id')->isEmpty()) {
      return $user->get('syncdb_id')->value;
    }
    return '';
  }

  /**
   * Gets getTransDate.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   Value for 'getTransDate' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getTransDate(OrderInterface $order) {
    $placed_date = $order->get('placed')->value;
    return $this->dateFormatter->format($placed_date, 'custom', 'Y-m-d', 'UTC');
  }

  /**
   * Returns mapping for the address fields.
   *
   * @return array
   *   SyncDB field name => Drupal address field
   */
  protected function getAddressFieldsMapping() {
    return [
      'address1' => 'address_line1',
      'address2' => 'address_line2',
      'city' => 'locality',
      'countryOrRegion' => 'country_code',
      'postalCode' => 'postal_code',
      'stateOrProvince' => 'administrative_area',
    ];
  }

  /**
   * Gets billingAddress.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Value for 'billingAddress' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getBillingAddress(OrderInterface $order) {
    $billing_profile = $order->getBillingProfile();
    if (!$billing_profile || $billing_profile->get('address')->isEmpty()) {
      return [];
    }
    $billing_address = [];
    $address = $billing_profile->get('address')->first()->getValue();
    foreach ($this->getAddressFieldsMapping() as $sync_db_field => $profile_address_field) {
      $billing_address[$sync_db_field] = $address[$profile_address_field] ?? '';
    }
    $billing_address['defaultBillingAddress'] = $billing_profile->isDefault();

    return $billing_address;
  }

  /**
   * Gets shippingAddress.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Value for 'shippingAddress' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getShippingAddress(OrderInterface $order) {
    $profiles = $order->collectProfiles();
    if (empty($profiles['shipping'])) {
      return [];
    }
    $shipping_profile = $profiles['shipping'];
    if ($shipping_profile->get('address')->isEmpty()) {
      return [];
    }
    $shipping_address = [];
    $address = $shipping_profile->get('address')->first()->getValue();
    foreach ($this->getAddressFieldsMapping() as $sync_db_field => $profile_address_field) {
      $shipping_address[$sync_db_field] = $address[$profile_address_field] ?? '';
    }
    $shipping_address['defaultShippingAddress'] = $shipping_profile->isDefault();

    return $shipping_address;
  }

  /**
   * Gets lineItems.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Value for 'lineItems' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getLineItems(OrderInterface $order) {
    $line_items = [];
    $price_level = $this->getPriceLevel($order);
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $item */
    foreach ($order->getItems() as $item) {
      $purchased_entity = $item->getPurchasedEntity();
      if ($purchased_entity->hasField('syncdb_id')) {
        $syncdb_product_id = !$purchased_entity->get('syncdb_id')->isEmpty() ? $purchased_entity->get('syncdb_id')->value : '';
      }
      if ($purchased_entity->hasField('netsuite_id')) {
        $ns_product_id = !$purchased_entity->get('netsuite_id')->isEmpty() ? $purchased_entity->get('netsuite_id')->value : '';
      }
      if (empty($syncdb_product_id) || empty($ns_product_id)) {
        continue;
      }

      // We loop over all tax adjustments but know that under the current
      // configuration it will only ever be a single IL sales tax adjustment.
      // If that changes, this code needs to be revised to properly track the
      // tax amount against the related tax code.
      /** @var \Drupal\commerce_price\Price $tax_price */
      $tax_price = NULL;
      foreach ($item->getAdjustments(['tax']) as $adjustment) {
        $tax_price = $tax_price ? $tax_price->add($adjustment->getAmount()) : $adjustment->getAmount();
      }

      $line_items[] = [
        'nsProductId' => $ns_product_id,
        'productId' => $syncdb_product_id,
        'productNumber' => !$purchased_entity->get('sku')->isEmpty() ? $purchased_entity->get('sku')->value : '',
        'quantity' => (int) $item->getQuantity(),
        'rate' => Calculator::trim($item->getUnitPrice()->getNumber()),
        'amount' => Calculator::trim($item->getTotalPrice()->getNumber()),
        'taxAmount' => empty($tax_price) ? 0 : Calculator::trim($tax_price->getNumber()),
        // Line items with IL sales tax should use a code of 6 (IL Sales Tax),
        // without tax use 1 (-Not Taxable-).
        'taxCodeId' => empty($tax_price) ? '1' : '6',
        'nsPriceLevelId' => $price_level,
        'priceLevelId' => $price_level,
      ];
    }

    return $line_items;
  }

  /**
   * Gets billToTierId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   Value for 'billToTierId' in PostTransaction.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getBillToTierId(OrderInterface $order) {
    // WHEN Price Level is Distributor, billToTier is 3.
    if ($this->getPriceLevel($order) == self::DISTRIBUTOR_PRICE_LEVEL) {
      return 3;
    }
    // WHEN Price Level is Member or Nonmember, billToTier is 1.
    else {
      return 1;
    }
  }

  /**
   * Gets transaction by it's ID from SyncDB.
   *
   * @param string $transaction_id
   *   Transaction ID.
   *
   * @return array
   *   Response.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getTransaction($transaction_id) {
    $url = Api::getApiEndpoint('IPCTransactionAPI') . "/transaction/GetTransaction?transactionId=" . $transaction_id;
    return Api::connectApi('GET', $url);
  }

  /**
   * Returns mapping of SyncDB transaction status and order state.
   *
   * @return array
   *   Associative array 'SyncDB transaction status' => 'commerce order status'.
   */
  public static function transactionOrderStatusMapping() {
    return [
      'Pending Approval' => 'pending',
      'Pending Billing' => 'pending',
      'Pending Fulfillment' => 'fulfillment',
      'Pending Billing/Partially Fulfilled' => 'fulfillment',
      'Partially Fulfilled' => 'fulfillment',
      'Billed' => 'completed',
      'Closed' => 'canceled',
      'Cancelled' => 'canceled',
    ];
  }

  /**
   * Makes GetTransactionList call to SyncDB.
   *
   * @param array $params
   *   Array of params.
   *
   * @return array
   *   Result.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getTransactionList(array $params = []) {
    $url = Api::getApiEndpoint('IPCTransactionAPI') . "/transaction/GetTransactionList";
    if (!empty($params)) {
      $url .= '?' . UrlHelper::buildQuery($params);
    }
    return Api::connectApi('GET', $url);
  }

  /**
   * Saves time when transactions where requested for imported.
   *
   * @param string $transaction_type
   *   Transaction type e.g. 'salesorder'.
   */
  public function setLastSyncDbTransactionImport(string $transaction_type = 'salesorder') {
    $this->state->set('last_syncdb_transaction_' . $transaction_type . '_import', time());
  }

  /**
   * Returns time when transactions where requested for imported.
   *
   * @param string $transaction_type
   *   Transaction type e.g. 'salesorder'.
   *
   * @return string|null
   *   Time or NULL.
   */
  public function getLastSyncDbTransactionImport(string $transaction_type = 'salesorder') {
    return $this->state->get('last_syncdb_transaction_' . $transaction_type . '_import');
  }

  /**
   * Add transactions to queue for updating orders.
   */
  public function enqueueTransactionsToUpdateOrders() {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    if (!$config->get('import_orders_from_syncdb')) {
      return;
    }
    $params = [
      'requestedPage' => 0,
    ];
    $last_syncdb_transaction_import = $this->getLastSyncDbTransactionImport();
    if (!empty($last_syncdb_transaction_import)) {
      $params['modifiedOnAfter'] = $this->dateFormatter->format($last_syncdb_transaction_import, 'custom', "Y-m-d\TH:i:s", 'UTC');
    }
    $transactions = $this->getTransactionList($params);
    while (!empty($transactions['transactionList'])) {
      $this->setLastSyncDbTransactionImport();
      foreach ($transactions['transactionList'] as $transaction) {
        if ($transaction['transactionType']['transactionPostType'] === 'salesorder' && is_numeric($transaction['externalId'])) {
          $this->enqueueTransactionForOrderUpdate($transaction['externalId'], $transaction['transactionId']);
        }
      }
      $params['requestedPage']++;

      $transactions = $this->getTransactionList($params);
    }
  }

  /**
   * Enqueue transaction to update and order on the site.
   *
   * @param string $external_id
   *   Value of 'externalId' field from SynDB transaction. Equal to order's ID.
   * @param string $transaction_id
   *   Value of the 'transactionId' field  from SynDB transaction.
   */
  public function enqueueTransactionForOrderUpdate($external_id, $transaction_id) {
    $order_sync_job = Job::create('ipc_syncdb_order_get_transaction', [
      'order_id' => $external_id,
      'transaction_id' => $transaction_id,
    ]);
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_syncdb');
    $queue->enqueueJob($order_sync_job);
  }

}
