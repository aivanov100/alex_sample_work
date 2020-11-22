/**
 * @file
 * MWAA cargo directory search behaviors.
 */

(function ($, Drupal) {

  'use strict';

  /**
   * Behavior description.
   */
  Drupal.behaviors.cargoDirectory = {
    attach: function (context, settings) {
      this.bindExportAction();
    },

    bindExportAction: function() {
      $('.js-export-results').off('click').on('click', function(e) {
        e.preventDefault();
        window.location.href = $('.views-data-export-feed a').attr('href');
        return false;
      })
    }
  };

} (jQuery, Drupal));
