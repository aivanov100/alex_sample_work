<?php

namespace Drupal\ipc_syncdb;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\PayflowInterface;
use Drupal\Component\Serialization\Json;
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
   * The adjustment transformer.
   *
   * @var \Drupal\commerce_order\AdjustmentTransformerInterface
   */
  protected $adjustmentTransformer;

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
   * @param \Drupal\commerce_order\AdjustmentTransformerInterface $adjustment_transformer
   *   The adjustment transformer.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, MessengerInterface $messenger, LoggerInterface $logger, ConfigFactoryInterface $config_factory, State $state, AdjustmentTransformerInterface $adjustment_transformer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->adjustmentTransformer = $adjustment_transformer;
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
    $status = 'failure';
    if (!$config->get('export_orders_to_syncdb')) {
      return $status;
    }

    $json = $this->createJsonForPostTransaction($order);
    $requestParams = new \stdClass();
    try {
      $response = Transaction::postTransaction($requestParams, $json, $order->id());
      if ($response['responseInfo']['responseMessage'] == 'Success') {
        $order->set('syncdb_id', $response['transactionId']);
        $order->save();
        $status = 'success';
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

    return $status;
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
      'externalTransactionNumber' => 'CF-' . $order->getOrderNumber(),
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
    $this->addCopyToAddressBookFields($required_fields, $order);

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

    $this->addCustomerIsUserField($required_fields, $order);

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

      $shipping_method = $shipment->getShippingMethod();
      $ns_shipping_method_id = $shipping_method->get('ns_shipping_method_id')->getValue();
      $ns_shipping_method_id = $ns_shipping_method_id[0]['value'];
      $fields['nsShipMethodId'] = $ns_shipping_method_id;

      $shipping_account_number = $shipment->get('shipping_account_number')->getValue();
      $fields['shippingAccount'] = $shipping_account_number[0]['value'];
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
    $fields['type'] = 'purchaseorder';

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
      'termsId' => $this->getTermsIdField($fields, $order),
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
    $fields['type'] = 'creditcardorder';

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
   */
  protected function addTaxTotalField(array &$fields, OrderInterface $order) {
    $fields['taxTotal'] = 0;
    $adjustments = $order->collectAdjustments(['tax']);
    if ($adjustments) {
      $combined_adjustments = $this->adjustmentTransformer->combineAdjustments($adjustments);
      $combined_adjustment = reset($combined_adjustments);
      /** @var \Drupal\commerce_price\Price $tax_amount */
      $tax_amount = $combined_adjustment->getAmount();
      $fields['taxTotal'] = Calculator::round($tax_amount->getNumber(), 2);
    }
  }

  /**
   * Add field - customerIsUser.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function addCustomerIsUserField(array &$fields, OrderInterface $order) {
    if ($company_group = $this->getUserCompanyGroup($order)) {
      $fields['customerIsUser'] = FALSE;
    }
    else {
      $fields['customerIsUser'] = TRUE;
    }
  }

  /**
   * Add fields related to copy_to_address_book fields for profiles.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function addCopyToAddressBookFields(array &$fields, OrderInterface $order) {
    $billing_profile = $order->getBillingProfile();
    if ($billing_profile && $billing_profile->getData('address_book_profile_id')) {
      $fields['addBillingAddressToCustomer'] = TRUE;
    }
    $profiles = $order->collectProfiles();
    if (!empty($profiles['shipping'])) {
      $shipping_profile = $profiles['shipping'];
      if ($shipping_profile && $shipping_profile->getData('address_book_profile_id')) {
        $fields['addShippingAddressToCustomer'] = TRUE;
      }
    }
  }

  /**
   * Compute the value for the field - termsId.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getTermsIdField(array $fields, OrderInterface $order) {
    if ($group = $this->getUserCompanyGroup($order)) {
      if ($group->hasField('payment_terms') && !$group->get('payment_terms')->isEmpty()) {
        $payment_terms = $group->get('payment_terms')->value;
        if (substr($payment_terms, 0, 3) === 'net') {
          $allowed_values = $group->get('payment_terms')->getFieldDefinition()->getSetting('allowed_values');
          if ($terms_value = $allowed_values[$payment_terms]) {
            $syncdb_terms_value = str_replace('NET', 'Net', $terms_value);
            $requestVariables = new \stdClass();
            $response_terms_list = Transaction::getSalesOrderTermsList($requestVariables);
            foreach ($response_terms_list['salesOrderTermsList'] as $term_definition) {
              if ($term_definition['salesOrderTerms'] == $syncdb_terms_value) {
                return $term_definition['salesOrderTermsId'];
              }
            }
          }
        }
      }
    }
    return '';
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
    return $this->dateFormatter->format($placed_date, 'custom', 'Y-m-d', 'US/Central');
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
      'addressee' => 'organization',
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

    $phone_number = $billing_profile->get('phone_number')->getValue();
    $billing_address['phone'] = $phone_number[0]['value'];

    $address_attention_to = $billing_profile->get('address_attention_to')->getValue();
    $billing_address['attention'] = $address_attention_to[0]['value'];

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

    $phone_number = $shipping_profile->get('phone_number')->getValue();
    $shipping_address['phone'] = $phone_number[0]['value'];

    $address_attention_to = $shipping_profile->get('address_attention_to')->getValue();
    $shipping_address['attention'] = $address_attention_to[0]['value'];

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
  protected function getTransactionOrderStatusMapping() {
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
   * Query SyncDB for all orders that have been updated since last run.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getUpdatedTransactionIdsFromSyncDb($modified_on_after = '') {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    if (!$config->get('import_orders_from_syncdb')) {
      return;
    }
    $run_time = date('Y-m-d\TH:i:s');
    $orders_to_update = [];

    $requestVariables = new \stdClass();
    $requestedPage = 1;
    $requestVariables->requestedPage = $requestedPage;
    if ($modified_on_after) {
      $modifiedOnAfter = $modified_on_after;
    }
    else {
      $last_run_time = $this->state->get('ipcsync_order_sync_last_run');
      $modifiedOnAfter = $last_run_time ? $last_run_time : ApiHelper::POLLING_ROUTINE_START_TIME;
    }
    $requestVariables->modifiedOnAfter = $modifiedOnAfter;

    $transactions = Transaction::getTransactionList($requestVariables);
    while (!empty($transactions['transactionList'])) {
      foreach ($transactions['transactionList'] as $transaction) {
        $orders_to_update[] = [
          'order_id' => $transaction['externalId'],
          'transaction_id' => $transaction['transactionId'],
        ];
      }
      $requestedPage++;
      $requestVariables->requestedPage = $requestedPage;
      $transactions = Transaction::getTransactionList($requestVariables);
    }

    $this->state->set('ipcsync_order_sync_last_run', $run_time);
    return $orders_to_update;
  }

  /**
   * Poll for changes to orders in the Sync DB via IPCTransactionAPI.
   */
  public function pollForChangesToOrders($modified_on_after = '') {
    $queue_storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $queue_storage->load('ipc_order_sync');

    $orders_to_update = $this->getUpdatedTransactionIdsFromSyncDb($modified_on_after);
    foreach ($orders_to_update as $order) {
      $order_sync_job = Job::create('ipc_syncdb_order_get_transaction', [
        'order_id' => $order['order_id'],
        'transaction_id' => $order['transaction_id'],
      ]);
      $queue->enqueueJob($order_sync_job);
    }
  }

  /**
   * Process update for an order that has been modified in SyncDB.
   *
   * @param int $order_id
   *   The order ID.
   * @param int $transaction_id
   *   The SyncDB transaction ID.
   *
   * @return string
   *   The processing status, i.e. 'success' or 'failure'.
   */
  public function processUpdateForOrder($order_id, $transaction_id) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    if (!$order instanceof OrderInterface) {
      // Order with such ID was not found. We are skipping this transaction.
      return 'success';
    }
    try {
      $requestVariables = new \stdClass();
      $requestVariables->transactionId = $transaction_id;
      $result = Transaction::getTransaction($requestVariables);
      if (empty($result['responseInfo']) || $result['responseInfo']['responseMessage'] != 'Success' || empty($result['transaction']) || !is_numeric($result['transaction']['externalId'])) {
        return 'failure';
      }

      $transaction = $result['transaction'];
      $this->updateTransactionState($order, $transaction);

      return 'success';
    }
    catch (\Exception $exception) {
      $this->logger->error($this->t('Encountered exception when attempting to update order with Order ID: @order_id. Message: @message.', [
        '@order_id' => $order_id,
        '@message' => $exception->getMessage(),
      ]));
      return 'failure';
    }
  }

  /**
   * Updates state of transactions where requested for imported.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $transaction
   *   Transaction data from the API.
   */
  protected function updateTransactionState(OrderInterface $order, array $transaction) {
    $transaction_status_id = $transaction['transactionStatus']['transactionStatusId'];
    $order->set('netsuite_status', $transaction_status_id);
    $current_order_state = $order->getState()->getId();

    switch ($transaction_status_id) {
      // Orders updating the NetSuite status to 24 or 29 (Pending Approval or
      // Pending Billing) transition to the Pending state if not already in it.
      case 24:
      case 29:
        if ($current_order_state == 'draft') {
          // Transition to the Pending state.
          $transition_id = 'place';
          $order->getState()->applyTransitionById($transition_id);
          $this->logger->notice($this->t('Order Sync:: (Order ID) @order_id - Transitioning to Pending state.', [
            '@order_id' => $order->id(),
          ]));
        }
        elseif ($current_order_state != 'pending') {
          $this->logger->error($this->t('Invalid state transition. Order ID @order_id cannot transition to Pending state, as it is not currently in Draft state.', [
            '@order_id' => $order->id(),
          ]));
        }
        break;

      // Orders updating the NetSuite status to 25, 27, or 28 (Pending
      // Fulfillment, Partially Fulfilled, or Pending Billing/Partially
      // Fulfilled) transition to the Fulfillment state if not already in it.
      case 25:
      case 27:
      case 28:
        if ($current_order_state == 'draft' || $current_order_state == 'pending') {
          // Transition to the Fulfillment state.
          $transition_id = 'validate';
          $order->getState()->applyTransitionById($transition_id);
          $this->logger->notice($this->t('Order Sync:: (Order ID) @order_id - Transitioning to Fulfillment state.', [
            '@order_id' => $order->id(),
          ]));
        }
        elseif ($current_order_state != 'fulfillment') {
          // Create a watchdog warning if the current state is not Pending.
          $this->logger->error($this->t('Invalid state transition. Order ID @order_id cannot transition to Fulfillment state, as it is not currently in Draft or Pending state.', [
            '@order_id' => $order->id(),
          ]));
        }
        break;

      // Orders updating the NetSuite status to 30 (Billed) transition to the
      // Completed state.
      case 30:
        if ($current_order_state == 'fulfillment') {
          // Transition to the Completed state.
          $transition_id = 'fulfill';
          $order->getState()->applyTransitionById($transition_id);
          $this->logger->notice($this->t('Order Sync:: (Order ID) @order_id - Transitioning to Completed state.', [
            '@order_id' => $order->id(),
          ]));
        }
        if ($current_order_state == 'draft' || $current_order_state == 'pending') {
          // Transition to the Fulfillment state.
          $transition_id = 'validate';
          $order->getState()->applyTransitionById($transition_id);
          $this->logger->notice($this->t('Order Sync:: (Order ID) @order_id - Transitioning to Fulfillment state.', [
            '@order_id' => $order->id(),
          ]));
          // Transition to the Completed state.
          $transition_id = 'fulfill';
          $order->getState()->applyTransitionById($transition_id);
          $this->logger->notice($this->t('Order Sync:: (Order ID) @order_id - Transitioning to Completed state.', [
            '@order_id' => $order->id(),
          ]));
        }
        break;

      case 26:
      case 31:
        // Transition to the Canceled state.
        $transition_id = 'cancel';
        $order->getState()->applyTransitionById($transition_id);
        $this->logger->notice($this->t('Order Sync:: (Order ID) @order_id - Transitioning to Canceled state.', [
          '@order_id' => $order->id(),
        ]));
        break;
    }
    $order->save();
  }

}
