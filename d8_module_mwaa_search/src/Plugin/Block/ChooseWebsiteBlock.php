<?php

namespace Drupal\mwaa_search\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'Choose Website' block for the Search Page.
 *
 * @Block(
 *   id = "choose_website_block",
 *   admin_label = @Translation("Choose Website Block"),
 * )
 */
class ChooseWebsiteBlock extends BlockBase implements ContainerFactoryPluginInterface, BlockPluginInterface {

  /**
   * The request stack object.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new ChooseWebsiteBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $default_configuration = [
      'default_mappings' => [
        'mwaa' => 'MWAA',
        'flydulles' => 'Fly Dulles',
        'flyreagan' => 'Fly Reagan',
        'dullestollroad' => 'Dulles Toll Road',
      ],
    ];
    $default_configuration += parent::defaultConfiguration();
    return $default_configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get host name for current request.
    $host = $this->requestStack->getCurrentRequest()->getHost();
    $url = $this->requestStack->getCurrentRequest()->getUri();
    $query_string = $this->requestStack->getCurrentRequest()->getQueryString();

    // Do not display for search page with no search query.
    if (!$query_string) {
      return;
    }

    $id_mappings = $this->getConfiguration()['default_mappings'];
    // Find a mapping matching current host name.
    foreach (array_keys($id_mappings) as $identifier) {
      if (strpos($host, $identifier) !== FALSE) {
        $current_site_identifier = $identifier;
        break;
      }
    }
    // Construct urls for all 4 sites.
    foreach ($id_mappings as $identifier => $label) {
      $site_specific_host = str_replace($current_site_identifier, $identifier, $host);
      $site_specific_url = str_replace($host, $site_specific_host, $url);
      $link = [
        'title' => $label,
        'url' => Url::fromUri($site_specific_url, ['attributes' => ['target' => '_blank']]),
      ];
      $link = ($identifier == $current_site_identifier) ? ($link += ['attributes' => ['class' => 'active-link']]) : $link;
      $links[] = $link;
    }
    return [
      '#theme' => 'links',
      '#links' => $links,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
