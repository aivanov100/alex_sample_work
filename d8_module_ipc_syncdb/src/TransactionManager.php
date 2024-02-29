<?php

namespace Drupal\ipc_syncdb;

use Drupal\advancedqueue\Job;
use Drupal\commerce_invoice\Entity\InvoiceInterface;
use Drupal\commerce_invoice\Entity\Invoice;
use Drupal\commerce_invoice\Entity\InvoiceItem;
use Drupal\commerce_invoice\Entity\InvoiceItemInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\PayflowInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\State;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ipcsync\Api;
use Drupal\ipcsync\Utilities\Transaction;
use Drupal\commerce_price\Calculator;
use Drupal\profile\Entity\Profile;
use Psr\Log\LoggerInterface;
use Drupal\ipc_commerce_avatax\Plugin\Commerce\TaxType\IpcAvatax;

/**
 * Handles IPCTransactionAPI integration for transactions and orders.
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
   * The user importer.
   *
   * @var \Drupal\ipc_syncdb\UserImporter
   */
  protected $userImporter;

  /**
   * The company importer.
   *
   * @var \Drupal\ipc_syncdb\CompanyImporter
   */
  protected $companyImporter;

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
   * @param \Drupal\ipc_syncdb\UserImporter $user_importer
   *   The user importer.
   * @param \Drupal\ipc_syncdb\CompanyImporter $company_importer
   *   The company importer.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, MessengerInterface $messenger, LoggerInterface $logger, ConfigFactoryInterface $config_factory, State $state, AdjustmentTransformerInterface $adjustment_transformer, UserImporter $user_importer, CompanyImporter $company_importer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->adjustmentTransformer = $adjustment_transformer;
    $this->userImporter = $user_importer;
    $this->companyImporter = $company_importer;
  }

  /**
   * Does a postTransaction API call to IPC Transaction Api.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_invoice_payment\Entity\Invoice $invoice
   *   The invoice, which exists for Quote transactions.
   *
   * @return string
   *   Result.
   */
  public function postTransaction(OrderInterface $order, Invoice $invoice = NULL) {
    $config = $this->configFactory->get('ipc_syncdb.settings');
    $status = 'failure';
    if (!$config->get('export_orders_to_syncdb')) {
      return $status;
    }
    if (!$this->checkThatUserHasCustomerId($order)) {
      // If the customer is a user and the user does not have a SyncDB ID
      // the transaction will be skipped and re-added to the queue.
      $user = $order->getCustomer();
      $this->logger->error($this->t('Post Transaction - skipping job for Order ID @order_id - The customer (@email) is a user who does not have a value set for SyncDB ID.', [
        '@order_id' => $order->id(),
        '@email' => $user->get('mail')->value,
      ]));
      // Enqueue the user for import so that SyncDB ID can be pulled in.
      $this->userImporter->enqueueUserForImportByEmail($user->get('mail')->value);
      return 'skipped';
    }

    $json = $this->createJsonForPostTransaction($order, $invoice);
    if ($json === 'skip_post_transaction_for_quote_without_ns_id') {
      return 'skipped';
    }
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
   * Helper function to create JSON containing data for transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_invoice_payment\Entity\Invoice $invoice
   *   The invoice, which exists for Quote transactions.
   *
   * @return false|string
   *   The JSON-encoded string containing data.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function createJsonForPostTransaction(OrderInterface $order, Invoice $invoice = NULL) {
    if ($invoice) {
      $external_id = $invoice->id();
      $external_transaction_number = 'QT-' . $invoice->getInvoiceNumber();
    }
    else {
      $external_id = $order->id();
      $external_transaction_number = 'CF-' . $order->getOrderNumber();
    }
    $payment_gateway = $order->get('payment_gateway')->getValue();
    $payment_gateway = $payment_gateway[0]['target_id'];
    $order_state = $order->getState()->getValue()['value'];

    $required_fields = [
      'externalId' => $external_id,
      'externalTransactionNumber' => $external_transaction_number,
      // Sync DB internal ID of the customer company (entity).
      'customerId' => $this->getCustomerId($order),
      // Sync DB internal ID of the end user.
      'endUserId' => $this->getEndUserId($order),
      // The date/time of the transaction.
      'transDate' => $this->getTransDate($order),
      // The exchange rate is 1 for all USD transactions.
      'currency' => 'US Dollar',
      'exchangeRate' => 1,
      'billingAddress' => $this->getBillingAddress($order),
      'shippingAddress' => $this->getShippingAddress($order),
      'lineItems' => $this->getLineItems($order),
      'billToTierId' => $this->getBillToTierId($order),
    ];

    if ($order->getSubtotalPrice() !== NULL) {
      $required_fields['subtotal'] = Calculator::trim($order->getSubtotalPrice()->getNumber());
    }
    if ($order->getTotalPrice() !== NULL) {
      $required_fields['total'] = Calculator::trim($order->getTotalPrice()->getNumber());
    }

    $this->addShippingHandlingFields($required_fields, $order);
    $this->addCustomsFields($required_fields, $order);
    $this->addTaxTotalField($required_fields, $order);
    $this->addCopyToAddressBookFields($required_fields, $order);

    if ($this->getPriceLevel($order) == self::DISTRIBUTOR_PRICE_LEVEL) {
      $required_fields['distributorId'] = $this->getCustomerId($order);
    }

    if ($order_state == 'quoted') {
      // Add fields specific to Quotes.
      $this->addQuoteFields($required_fields, $order);
      if (!empty($required_fields['skip_post_transaction_for_quote_without_ns_id'])) {
        return 'skip_post_transaction_for_quote_without_ns_id';
      }
    }
    elseif ($payment_gateway == 'ipc_purchase_orders') {
      // Add fields specific to orders paid by Purchase Order.
      $this->addPurchaseOrderFields($required_fields, $order);
    }
    else {
      // Add fields specific to Credit Card orders.
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

    $shipments = $order->get('shipments')->referencedEntities();
    if ($shipments) {
      $shipment = reset($shipments);

      if ($shipment->getOriginalAmount()->getNumber()) {
        $fields['shipCost'] = $shipment->getOriginalAmount()->getNumber() ? Calculator::trim($shipment->getOriginalAmount()->getNumber()) : '0';

        /** @var \Drupal\commerce_order\Adjustment $adjustment */
        foreach ($shipment->getAdjustments() as $adjustment) {
          $type = $adjustment->getType();
          $label = $adjustment->getLabel();
          $adjustment_amount = $adjustment->getAmount()->getNumber() ? Calculator::trim($adjustment->getAmount()->getNumber()) : '0';
          $tax_rate_percentage = $adjustment->getPercentage();
          $tax_rate = $tax_rate_percentage ? Calculator::trim(Calculator::multiply($tax_rate_percentage, 100)) : '0';

          if ($type == 'handling_fee') {
            $fields['handlingCost'] = $adjustment_amount;
          }
          if ($type == 'tax') {
            if ($label == IpcAvatax::HANDLING_FEE_TAX_ADJ_LABEL) {
              $fields['handlingTaxAmount'] = $adjustment_amount;
              $fields['handlingTaxRate'] = $tax_rate;
            }
            else {
              $fields['shipTaxAmount'] = $adjustment_amount;
              $fields['shipTaxRate'] = $tax_rate;
            }
          }
        }

        $shipping_method = $shipment->getShippingMethod();
        $ns_shipping_method_id = $shipping_method->get('ns_shipping_method_id')->getValue();
        if (isset($ns_shipping_method_id[0]['value'])) {
          $fields['nsShipMethodId'] = $ns_shipping_method_id[0]['value'];
        }

        $shipping_account_number = $shipment->get('shipping_account_number')->getValue();
        if (isset($shipping_account_number[0]['value'])) {
          $fields['shippingAccount'] = $shipping_account_number[0]['value'];
        }
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
    $fields['type'] = 'purchaseorder';
    $fields['orderStatus'] = 'Pending Fulfillment';

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
    $fields['orderStatus'] = 'Pending Fulfillment';

    $fields['otherRefNum'] = $order->hasField('your_reference') ? $order->get('your_reference')->value : NULL;

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
   * Add fields specific to Quotes.
   *
   * @param array $fields
   *   Associative array of fields.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function addQuoteFields(array &$fields, OrderInterface $order) {
    $fields['type'] = 'estimate';
    $fields['orderStatus'] = 'Pending Approval';
    if ($po_number_reference = $order->get('your_reference')->getValue()) {
      $fields['otherRefNum'] = $po_number_reference[0]['value'];
    }

    if ($order->bundle() === 'quote_purchase') {
      if ($order->hasField('created_from') && !$order->get('created_from')->isEmpty()) {
        $quote = $order->get('created_from')->entity;
        if ($quote instanceof InvoiceInterface) {
          if ($netsuite_id = $quote->get('netsuite_id')) {
            $fields['createdFromTransactionId'] = $quote->get('syncdb_id');
            $fields['nsCreatedFromTransactionId'] = $netsuite_id;
          }
          else {
            // Skip Post Transaction if the quote does not yet have a NS id.
            $fields['skip_post_transaction_for_quote_without_ns_id'] = TRUE;
          }
        }
      }
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
    if ($placed_date = $order->get('placed')->value) {
      return $this->dateFormatter->format($placed_date, 'custom', 'Y-m-d', 'US/Central');
    }
    elseif ($created_date = $order->get('created')->value) {
      return $this->dateFormatter->format($created_date, 'custom', 'Y-m-d', 'US/Central');
    }
    else {
      return NULL;
    }
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
      return NULL;
    }
    $billing_address = [];
    $address = $billing_profile->get('address')->first()->getValue();
    foreach ($this->getAddressFieldsMapping() as $sync_db_field => $profile_address_field) {
      $billing_address[$sync_db_field] = $address[$profile_address_field] ?? '';
    }
    $billing_address['defaultBillingAddress'] = $billing_profile->isDefault();

    $phone_number = $billing_profile->get('phone_number')->getValue();
    if (isset($phone_number[0]['value'])) {
      $billing_address['phone'] = $phone_number[0]['value'];
    }

    $address_attention_to = $billing_profile->get('address_attention_to')->getValue();
    if (isset($address_attention_to[0]['value'])) {
      $billing_address['attention'] = $address_attention_to[0]['value'];
    }

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
    if (isset($phone_number[0]['value'])) {
      $shipping_address['phone'] = $phone_number[0]['value'];
    }

    $address_attention_to = $shipping_profile->get('address_attention_to')->getValue();
    if (isset($address_attention_to[0]['value'])) {
      $shipping_address['attention'] = $address_attention_to[0]['value'];
    }

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
      if ($purchased_entity && $purchased_entity->hasField('syncdb_id')) {
        $syncdb_product_id = !$purchased_entity->get('syncdb_id')->isEmpty() ? $purchased_entity->get('syncdb_id')->value : '';
      }
      if ($purchased_entity && $purchased_entity->hasField('netsuite_id')) {
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
      $tax_rate = 0;
      foreach ($item->getAdjustments(['tax']) as $adjustment) {
        $tax_price = $tax_price ? $tax_price->add($adjustment->getAmount()) : $adjustment->getAmount();

        // Since we are only expecting a single sales tax adjustment,
        // this code works to get the taxRate for the line item.
        $tax_rate_percentage = $adjustment->getPercentage();
        $tax_rate = $tax_rate_percentage ? Calculator::multiply($tax_rate_percentage, 100) : 0;
      }
      $tax_amount = $tax_price ? Calculator::trim($tax_price->getNumber()) : 0;

      $line_items[] = [
        'externalLineId' => $item->id(),
        'nsProductId' => $ns_product_id,
        'productId' => $syncdb_product_id,
        'productNumber' => !$purchased_entity->get('sku')->isEmpty() ? $purchased_entity->get('sku')->value : '',
        'quantity' => (int) $item->getQuantity(),
        'rate' => Calculator::trim($item->getUnitPrice()->getNumber()),
        'amount' => Calculator::trim($item->getTotalPrice()->getNumber()),
        'nsPriceLevelId' => $price_level,
        'priceLevelId' => $price_level,
        'taxAmount' => $tax_amount,
        'taxRate' => Calculator::trim($tax_rate),
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
   * Process update for a transaction.
   *
   * @param int $order_id
   *   The order ID.
   * @param int $transaction_id
   *   The SyncDB transaction ID.
   *
   * @return string
   *   The processing status, i.e. 'success' or 'failure'.
   */
  public function processUpdateForTransaction($order_id, $transaction_id) {
    try {
      $requestVariables = new \stdClass();
      $requestVariables->transactionId = $transaction_id;
      $result = Transaction::getTransaction($requestVariables);
      if (empty($result['responseInfo']) || $result['responseInfo']['responseMessage'] != 'Success' || empty($result['transaction'])) {
        return 'failure';
      }
      $transaction = $result['transaction'];

      if ($transaction['transactionType']['transactionType'] === 'Quote') {
        // Process update for Quote.
        $this->processUpdateForQuote($transaction);
      }
      elseif ($transaction['transactionType']['transactionTypeId'] == 11) {
        // Process update for Order.
        if (!is_numeric($result['transaction']['externalId'])) {
          return 'failure';
        }
        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
        if (!$order instanceof OrderInterface) {
          // Order with such ID was not found. We are skipping this transaction.
          return 'success';
        }
        $this->updateTransactionState($order, $transaction);
      }

      return 'success';
    }
    catch (\Exception $exception) {
      $this->logger->error($this->t('Encountered exception when attempting to update order with Transaction ID: @tid. Message: @message.', [
        '@tid' => $transaction_id,
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

  /**
   * Creates/updates a quote using data retrieved from IPCTransactionAPI.
   *
   * @param array $transaction
   *   Transaction data from the API.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function processUpdateForQuote(array $transaction) {
    $commerce_invoice_storage = $this->entityTypeManager->getStorage('commerce_invoice');

    // Load the Quote by externalId, if it is provided.
    if (!empty($transaction['externalId']) && is_numeric($transaction['externalId'])) {
      $quote = $commerce_invoice_storage->load($transaction['externalId']);
    }
    else {
      // Attempt to load existing Quote by SyncDB ID.
      $entities = $commerce_invoice_storage->loadByProperties([
        'type' => 'quote',
        'syncdb_id' => $transaction['transactionId'],
      ]);
      if ($entities) {
        $quote = reset($entities);
      }
      else {
        if ($transaction['availableInCustomerCenter']) {
          // Quote does not exist - create the Quote.
          $quote = Invoice::create([
            'type' => 'quote',
            'store_id' => 1,
            'syncdb_id' => $transaction['transactionId'],
          ]);
          $quote->save();

          // Transition to the Pending (Open) state.
          $transition_id = 'confirm';
          $quote->getState()->applyTransitionById($transition_id);
        }
        else {
          return;
        }
      }
    }

    if ($quote && $transaction) {
      $this->setQuoteFields($quote, $transaction);
      $this->setQuoteLineItems($quote, $transaction);
      $quote->save();
      $this->setRelationshipsForQuote($quote, $transaction);
      $this->generateQuoteImportSuccessLogMessage($quote, $transaction);
    }
    else {
      $this->generateQuoteImportFailLogMessage($transaction);
    }
  }

  /**
   * Assigns the quote to the appropriate user or company.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $quote
   *   The quote.
   * @param array $transaction
   *   Transaction data from the API.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function setRelationshipsForQuote(Invoice $quote, array $transaction) {
    if ($transaction['customer']['user']) {
      // Assign the Quote to a user.
      if ($user = $this->userImporter->importUserByEmail($transaction['customer']['user']['email'])) {
        $quote->set('uid', $user->id());
      }
      else {
        $this->logger->error(t('Quote Sync Error - Transaction ID @tid - Error importing Quote with Drupal ID @drupal_id - Unable to import the associated user with email: @email', [
          '@tid' => $transaction['transactionId'],
          '@drupal_id' => $quote->id(),
          '@email' => $transaction['customer']['user']['email'],
        ]));
        return;
      }
    }
    elseif ($transaction['customer']['company']) {
      // Assign the Quote to a company.
      if ($company = $this->companyImporter->importCompany($transaction['customer']['company']['accountId'])) {
        $quote->set('uid', 0);
        $group_type = $company->getGroupType();
        if ($group_type->hasContentPlugin('group_invoice:quote')) {
          $company->addContent($quote, 'group_invoice:quote');
        }
      }
      else {
        $this->logger->error(t('Quote Sync Error - Transaction ID @tid - Error importing Quote with Drupal ID @drupal_id - Unable to import the associated company with AccountId: @account_id', [
          '@tid' => $transaction['transactionId'],
          '@drupal_id' => $quote->id(),
          '@account_id' => $transaction['customer']['company']['accountId'],
        ]));
        return;
      }
    }
    $quote->save();
  }

  /**
   * Sets the quote fields with values retrieved via IPCTransactionAPI.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $quote
   *   The quote.
   * @param array $transaction
   *   Transaction data from the API.
   */
  protected function setQuoteFields(Invoice $quote, array $transaction) {
    $quote->set('netsuite_id', $transaction['nsTransactionId']);
    $quote->set('netsuite_status', $transaction['transactionStatus']['transactionStatusId']);
    $quote->set('quote_status', $transaction['quoteStatus']['quoteStatusId']);
    $quote->set('reference_number', $transaction['documentNumber']);
    $quote->set('po_number', $transaction['otherReferenceNumber']);
    $quote->set('available_in_customer_center', $transaction['availableInCustomerCenter']);

    $quote_expiration_date = substr($transaction['quoteExpirationDate'], 0, 10);
    $quote->set('quote_expiration_date', $quote_expiration_date);
    $sales_order_terms = $this->getMappedSalesOrderTerms($transaction['salesOrderTerms']['salesOrderTerms']);
    $quote->set('terms', $sales_order_terms);

    $quote->set('customs_note', $transaction['includeEPOnlyNote']);
    $quote->set('customs_include_tax_id', $transaction['includeVATTaxFiscalId']);
    $quote->set('customs_tax_id', $transaction['vatTaxFiscalId']);

    $quote->set('shipping_account_number', $transaction['shippingAccount']);
    if (!empty($transaction['shippingAccount'])) {
      $quote->set('delivery_method', "account");
    }
    else {
      $quote->set('delivery_method', "standard");
    }
    if (!empty($transaction['shippingMethod']['shippingMethod'])) {
      $quote->set('shipping_method', $transaction['shippingMethod']['shippingMethod']);
    }

    // Create profile for shipping information.
    $shipping_profile = $this->createOrUpdateProfile($transaction['shippingAddress']);
    $quote->set('shipping_information', ['target_id' => $shipping_profile->id()]);

    // Create profile for billing information.
    $billing_profile = $this->createOrUpdateProfile($transaction['billingAddress']);
    $quote->set('billing_profile', $billing_profile);
  }

  /**
   * Sets the quote line items using data retrieved via IPCTransactionAPI.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $quote
   *   The quote.
   * @param array $transaction
   *   Transaction data from the API.
   */
  protected function setQuoteLineItems(Invoice $quote, array $transaction) {
    $existing_invoice_items = $quote->get('invoice_items')->getValue();
    $existing_invoice_item_ids = array_map(function ($value) {
      return $value['target_id'];
    }, $existing_invoice_items);
    $invoice_item_storage = $this->entityTypeManager->getStorage('commerce_invoice_item');

    foreach ($transaction['lineItems'] as $line_item) {
      $create_invoice_item = TRUE;
      if ($line_item['product']) {
        // Load the invoice item by the Drupal ID, if present.
        if ($external_line_id = $line_item['externalLineId']) {
          $invoice_item = $invoice_item_storage->load($external_line_id);
          $create_invoice_item = FALSE;
        }
        else {
          // Check if invoice item already exists for this product.
          if ($existing_invoice_item_ids) {
            $query = $invoice_item_storage->getQuery();
            $query
              ->condition('type', 'quote')
              ->condition('invoice_item_id', $existing_invoice_item_ids, 'IN')
              ->condition('syncdb_id', $line_item['lineId'])
              ->accessCheck(FALSE);
            $result = $query->execute();
            if ($result) {
              // Invoice item exists - load the existing item.
              $create_invoice_item = FALSE;
              $invoice_item_ids = array_values($result);
              $invoice_item_id = reset($invoice_item_ids);
              /** @var \Drupal\commerce_invoice\Entity\InvoiceItemInterface $invoice_item */
              $invoice_item = $invoice_item_storage->load($invoice_item_id);
            }
          }
          if ($create_invoice_item) {
            // Invoice item doesn't exist - create the invoice item.
            /** @var \Drupal\commerce_invoice\Entity\InvoiceItemInterface $invoice_item */
            $invoice_item = InvoiceItem::create([
              'type' => 'quote',
              'syncdb_id' => $line_item['lineId'],
            ]);
          }
        }

        if ($invoice_item && $line_item) {
          $this->setQuoteLineItemFields($invoice_item, $line_item);
          $invoice_item->save();

          // If invoice item ID is not already in $existing_invoice_item_ids,
          // Add the invoice item to the Quote.
          $key = array_search($invoice_item->id(), $existing_invoice_item_ids, FALSE);
          if ($key === FALSE) {
            $quote->addItem($invoice_item);
          }
          else {
            // If invoice item ID is already in $existing_invoice_item_ids,
            // Remove the ID for this line item from $existing_invoice_item_ids
            // so that only unmatched line items shall remain in this array.
            unset($existing_invoice_item_ids[$key]);
          }
        }
      }
    }

    // Loop through remaining entries in $existing_invoice_item_ids, delete the
    // corresponding line items as they have not been matched with line items
    // coming in for the freshly-imported Quote.
    foreach ($existing_invoice_item_ids as $existing_invoice_item_id) {
      $invoice_item = $invoice_item_storage->load($existing_invoice_item_id);
      $quote->removeItem($invoice_item);
      $invoice_item->delete();
    }
  }

  /**
   * Sets the Quote Line Item fields using data from IPCTransactionAPI.
   *
   * @param \Drupal\commerce_invoice\Entity\InvoiceItemInterface $invoice_item
   *   The Invoice Item.
   * @param array $line_item
   *   Line Item data from the API.
   */
  protected function setQuoteLineItemFields(InvoiceItemInterface $invoice_item, array $line_item) {
    // If the total (tax inclusive) price of the line item doesn't match,
    // clear all the adjustments and then add them back.
    if (!$invoice_item->get('total_price')->isEmpty()) {
      $total_price = $invoice_item->get('total_price')->first()->toPrice()->getNumber();
      if (Calculator::compare($total_price, $line_item['amount']) !== 0) {
        foreach ($invoice_item->getAdjustments(['tax']) as $adjustment) {
          $invoice_item->removeAdjustment($adjustment);
        }
      }
    }
    // Add adjustment if it has not already been added.
    if ($line_item['taxAmount']) {
      $tax_adjustment_added = FALSE;
      foreach ($invoice_item->getAdjustments(['tax']) as $adjustment) {
        $adjustment_tax_amount = Calculator::trim($adjustment->getAmount()->getNumber());
        if ($adjustment_tax_amount === Calculator::trim($line_item['taxAmount'])) {
          $tax_adjustment_added = TRUE;
        }
      }
      if (!$tax_adjustment_added) {
        $adjustment = new Adjustment([
          'type' => 'tax',
          'label' => $this->t('Sales tax'),
          'amount' => new Price($line_item['taxAmount'], 'USD'),
        ]);
        $invoice_item->addAdjustment($adjustment);
      }
    }

    $invoice_item->set('netsuite_id', $line_item['nsLineId']);
    $invoice_item->set('product_id', $line_item['product']['productId']);
    $invoice_item->set('ns_product_id', $line_item['product']['nsProductId']);
    $invoice_item->set('product_number', $line_item['product']['productNumber']);
    $invoice_item->set('title', $line_item['product']['displayName']);
    $invoice_item->set('display_name', $line_item['product']['displayName']);
    $invoice_item->set('description', $line_item['product']['description']);
    $invoice_item->set('transaction_discount', $line_item['transactionDiscount']);
    $invoice_item->set('quantity', $line_item['quantity']);
    $invoice_item->set('unit_price', new Price($line_item['rate'], 'USD'));
    $invoice_item->set('total_price', new Price($line_item['amount'], 'USD'));
  }

  /**
   * Creates or updates a profile with data obtained from SyncDB.
   *
   * @param array $address_from_response
   *   The address data from the Api response.
   *
   * @return Drupal\profile\Entity\Profile
   *   The profile.
   */
  public function createOrUpdateProfile(array $address_from_response) {
    $profile = $this->getProfileIfProfileExists($address_from_response);
    if (!$profile) {
      $profile = Profile::create([
        'type' => 'customer',
        'syncdb_id' => $address_from_response['addressId'],
      ]);
      $profile->save();
    }
    $this->setProfileFields($profile, $address_from_response);
    return $profile;
  }

  /**
   * Sets the profile fields with values retrieved via API call.
   *
   * @param Drupal\profile\Entity\Profile $profile
   *   The profile.
   * @param array $address_from_response
   *   The address from the Api response.
   */
  protected function setProfileFields(Profile &$profile, array $address_from_response) {
    $profile->set('netsuite_id', $address_from_response['nsAddressId']);
    $address = [
      'country_code' => $address_from_response['country']['twoLetterISOCode'],
      'address_line1' => $address_from_response['address1'],
      'address_line2' => $address_from_response['address2'],
      'locality' => $address_from_response['city'],
      'administrative_area' => $address_from_response['stateOrProvince'],
      'postal_code' => $address_from_response['postalCode'],
      'organization' => $address_from_response['addressee'],
    ];
    $profile->set('address', $address);
    if ($address_from_response['primaryAddress'] == TRUE) {
      $profile->set('address_type', 'primary');
    }
    $profile->set('primary_address', $address_from_response['primaryAddress']);
    $profile->set('default_billing_address', $address_from_response['defaultBillingAddress']);
    $profile->set('default_shipping_address', $address_from_response['defaultShippingAddress']);
    $profile->set('home_address', $address_from_response['homeAddress']);
    $profile->set('residential_address', $address_from_response['residentialAddress']);
    $profile->set('phone_number', $address_from_response['phone']);
    $profile->set('address_attention_to', $address_from_response['attention']);
    $profile->save();
  }

  /**
   * Returns profile if profile that matches target identifiers exists.
   *
   * @param array $address_from_response
   *   The address data from the Api response.
   */
  public function getProfileIfProfileExists(array $address_from_response) {
    $storage = $this->entityTypeManager->getStorage('profile');
    $profiles = $storage->loadByProperties([
      'type' => 'customer',
      'syncdb_id' => $address_from_response['addressId'],
    ]);
    if ($profiles) {
      $profile = reset($profiles);
      return $profile;
    }
    return FALSE;
  }

  /**
   * Get mapped Drupal value for a Sales Order Terms value from the API.
   *
   * @return string
   *   The mapped Drupal value for the specified terms.
   */
  protected function getMappedSalesOrderTerms($term) {
    $mapping = [
      'Net 15' => 'net15',
      'Net 30' => 'net30',
      'Net 35' => 'net35',
      'Net 45' => 'net45',
      'Net 60' => 'net60',
    ];
    if (array_key_exists($term, $mapping)) {
      return $mapping[$term];
    }
    else {
      return 'ineligible';
    }
  }

  /**
   * Generate Quote Import Success log message.
   *
   * @param \Drupal\commerce_invoice\Entity\Invoice $quote
   *   The quote.
   * @param array $transaction
   *   Transaction data from the API.
   */
  protected function generateQuoteImportSuccessLogMessage(Invoice $quote, array $transaction) {
    $this->logger->notice(t('Order Sync Success - Imported Quote (Invoice) with Drupal ID @drupal_id for Transaction with SyncDB ID: @sync_db_id.', [
      '@drupal_id' => $quote->id(),
      '@sync_db_id' => $transaction['transactionId'],
    ]));
  }

  /**
   * Generate Quote Import Fail log message.
   *
   * @param array $transaction
   *   Transaction data from the API.
   */
  protected function generateQuoteImportFailLogMessage(array $transaction) {
    $this->logger->notice(t('Order Sync Failure - Failed to import Quote for Transaction with SyncDB ID: @sync_db_id.', [
      '@sync_db_id' => $transaction['transactionId'],
    ]));
  }

  /**
   * Checks if customer is a user that there is a value to post for customerId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function checkThatUserHasCustomerId(OrderInterface $order) {
    if ($this->getUserCompanyGroup($order) === NULL) {
      $user = $order->getCustomer();
      if ($user->get('syncdb_id')->isEmpty()) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
