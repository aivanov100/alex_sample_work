<?php

namespace Drupal\mwaa_search\Plugin\views\area;

use Drupal\views\Plugin\views\area\AreaPluginBase;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\ViewExecutable;

/**
 * Views area AlphabeticPager handler.
 *
 * Builds an alphabetical pager linking to filtered versions of the current
 * view.  Requires the current letter filtering the view to be the first
 * argument in the view.
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("mwaa_search_alphabetic_pager_area")
 */
class AlphabeticPagerArea extends AreaPluginBase {

  /**
   * {@inheritDoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);

    // This handles passing arguments to the exposed filter block build of the
    // view.  The initial build of the view page includes page arguments such
    // as /business/cargo-directory/a where 'a' is an argument to the view page
    // and exists in $view->args.  When exposing views filters in a block,
    // the build of the exposed block does not contain the arguments.  By
    // adding them here, the exposed filter form action will include the
    // alphabetical pager argument and submit the filter values to the view
    // url with arguments, eg /business/cargo-directory/a instead of
    // /business/cargo-directory.
    $args = &drupal_static(AlphabeticPagerArea::class . ':init', FALSE);
    if ($args === FALSE) {
      $args = $view->args;
    }
    elseif (is_array($args) && !empty($args) && empty($view->args)) {
      $view->args = $args;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    $build['content'] = [
      '#theme' => 'alphabetical_pager',
      '#current_letter' => $this->view->args[0] ?? 'all',
      '#view_url' => $this->view->getPath(),
    ];
    return $build;
  }

}
