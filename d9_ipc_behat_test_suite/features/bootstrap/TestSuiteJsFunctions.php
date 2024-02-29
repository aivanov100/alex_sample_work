<?php

namespace behat\features\bootstrap;
/**
 * Global Javascript functions for the test suite.
 */
class TestSuiteJsFunctions
{

  public const JS_CLICK_MODAL_BUTTON = <<<JS
    (function() {
      let button = jQuery('.modalDialog .modalFooter button');
      if (button) {
        button.click();
      }
    }());
JS;

  public const JS_CLICK_MODAL_SAVE_BUTTON = <<<JS
    (function() {
      let button = jQuery('.modalDialog .modal-body .form-submit.btn');
      if (button) {
        button.click();
      }
    }());
JS;

  public const JS_CLICK_EDIT_SEND_BUTTON = <<<JS
    (function() {
      let button = jQuery('#edit-send');
      if (button) {
        button.click();
      }
    }());
JS;

  public const JS_CLICK_MY_IPC_EDGE = <<<JS
    (function() {
      let link = jQuery('ul.menu--account li.menu__item--my-account a');
      if (link) {
        link.click();
      }
    }());
JS;

  public const JS_CLICK_LOGOUT = <<<JS
    (function() {
      let link = jQuery('.menu__item--log-out a');
      if (link) {
        link.click();
      }
    }());
JS;

  public const JS_CLICK_MY_VOUCHERS = <<<JS
    (function() {
      let link = jQuery('.site-sidebar--left .ul.menu--dashboard-my-account li.menu__item--vouchers a');
      if (link) {
        link.click();
      }
    }());
JS;

  public const JS_WAIT_FOR_AJAX = <<<JS
    (function() {
      function isAjaxing(instance) {
        return instance && instance.ajaxing === true;
      }
      var d7_not_ajaxing = true;
      if (typeof Drupal !== 'undefined' && typeof Drupal.ajax !== 'undefined' && typeof Drupal.ajax.instances === 'undefined') {
        for(var i in Drupal.ajax) { if (isAjaxing(Drupal.ajax[i])) { d7_not_ajaxing = false; } }
      }
      var d8_not_ajaxing = (typeof Drupal === 'undefined' || typeof Drupal.ajax === 'undefined' || typeof Drupal.ajax.instances === 'undefined' || !Drupal.ajax.instances.some(isAjaxing))
      return (
        // Assert no AJAX request is running (via jQuery or Drupal) and no
        // animation is running.
        (typeof jQuery === 'undefined' || jQuery.hasOwnProperty('active') === false || (jQuery.active === 0 && jQuery(':animated').length === 0)) &&
        d7_not_ajaxing && d8_not_ajaxing
      );
    }());
JS;

  public const DISABLE_AUTO_UPLOAD = <<<JS
    (function() {
      jQuery(once.remove('auto-file-upload', 'input[type="file"]')).off('.autoFileUpload');
      jQuery(once.remove('check-file-upload', 'input[name="files[payment_information_add_payment_method_payment_details_file]"]')).off('change');
    }());
JS;

}
