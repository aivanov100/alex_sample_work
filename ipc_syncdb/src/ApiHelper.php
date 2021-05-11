<?php

namespace Drupal\ipc_syncdb;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Contains helper methods for Sync DB Api operations.
 */
class ApiHelper {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The target api, e.g. 'IPCTransactionApi', 'IPCEntitiesApi'.
   *
   * @var string
   */
  protected $targetApi;

  /**
   * Constructs a new ApiHelper object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, LoggerInterface $logger, StateInterface $state, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->state = $state;
    $this->configFactory = $config_factory;
  }

  /**
   * Sets the value for target api.
   *
   * @param string $target_api
   *   The target api.
   */
  public function setTargetApi(string $target_api) {
    $this->targetApi = $target_api;
  }

  /**
   * Gets the value of target api.
   *
   * @return string
   *   The target api.
   */
  public function getTargetApi() {
    return $this->targetApi;
  }

  /**
   * Checks if response was successful, creates log messages accordingly.
   *
   * @param array $response
   *   The response from the api call.
   * @param string $api_call_name
   *   The name of the executed api call.
   * @param string $param_name
   *   The name of the parameter passed to the api call.
   * @param string $param_value
   *   The value of the parameter passed to the api call.
   */
  public function processApiResponse(array $response, string $api_call_name, string $param_name = NULL, string $param_value = NULL) {
    $param_message = '';
    if ($param_name && $param_value) {
      $param_message = ':: (' . $param_name . ') ' . $param_value;
    }
    if ($response['responseInfo']['responseMessage'] == 'Success') {
      $this->logger->notice(t('@apiCallName @param_message - Successfully completed.', [
        '@apiCallName' => $api_call_name,
        '@param_message' => $param_message,
      ]));
    }
    else {
      $account = \Drupal::currentUser();
      if ($account->hasPermission('administer ipc_syncdb')) {
        $this->messenger->addMessage(t('There was an error when attempting @apiCallName @param_message.', [
          '@apiCallName' => $api_call_name,
          '@param_message' => $param_message,
        ]), 'error');
        $this->messenger->addMessage(t('The status code is @responseCode @responseMessage.', [
          '@responseCode' => $response["responseInfo"]["responseCode"],
          '@responseMessage' => $response["responseInfo"]["responseMessage"],
        ]), 'error');
      }
      $this->logger->error(t('@apiCallName @param_message - Unsuccessful, Error Encountered.', [
        '@apiCallName' => $api_call_name,
        '@param_message' => $param_message,
      ]));
    }
  }

  /**
   * Generates detailed log message for api call.
   *
   * @param string $api_call_name
   *   The name of the api call.
   * @param object $requestParams
   *   The parameters passed to the api call.
   * @param array $response
   *   The response from the api call.
   * @param string $json
   *   The JSON from the body of the api call.
   */
  public function generateDetailedLogMessage(string $api_call_name, \stdClass $requestParams, array $response, string $json = NULL) {
    if ($json) {
      $this->logger->debug(t('API CALL DETAILS: @api_call_name. <br><br><b>Request params:</b> <pre><code>@request_params</code></pre> <b>Body:</b> <pre><code>@body</code></pre> <b>Response:</b> <pre><code>@response</code></pre>', [
        '@api_call_name' => $api_call_name,
        '@request_params' => print_r($requestParams, TRUE),
        '@body' => print_r(Json::decode($json), TRUE),
        '@response' => print_r($response, TRUE),
      ]));
    }
    else {
      $this->logger->debug(t('API CALL DETAILS: @api_call_name. <br><b>Request params:</b> <pre><code>@request_params</code></pre> <b>Response:</b> <pre><code>@response</code></pre>', [
        '@api_call_name' => $api_call_name,
        '@request_params' => print_r($requestParams, TRUE),
        '@response' => print_r($response, TRUE),
      ]));
    }
  }

}
