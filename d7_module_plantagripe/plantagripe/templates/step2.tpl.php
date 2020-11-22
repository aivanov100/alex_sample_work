<?php print drupal_render($form['progress_bar']); ?>

<!-- <hr />
<?php print t('* Required Information'); ?>
<hr /> -->

<div class="warming">
<?php print t('note: any information you enter in this area is secure and will only be shared with the company your gripe is about.'); ?>
</div>


<div class="col-one">
<?php print drupal_render($form['account_number']); ?> <a href="#" class="tooltip" id="account_number_tooltip">Help?</a>
<?php print drupal_render($form['reference_number']); ?> <a href="#" class="tooltip" id="reference_number_tooltip">Help?</a>
<?php print drupal_render($form['private_info']); ?> <a href="#" class="tooltip" id="private_info_tooltip">Help?</a>
</div>

<div class="col-two">
  <div id="tooltip-box">
    <div id="tooltip_box_text_0">
      <p>
        <?php print t(
            'To help the company resolve your gripe, you can enter any account or reference number(s) here,
         if applicable. If you don\'t have such information or it doesn\'t pertain to your gripe, don\'t worry - you can just
         leave this area empty.'
        ); ?>
      </p>
    </div>
    <div id="tooltip_box_text_1" style="display:none">
      <p>
        <?php print t(
            'You can also include a member number or frequent flyer number. This will help the company track
         down your account faster and assist you in the best way they can.'
        ); ?>
      </p>
    </div>
    <div id="tooltip_box_text_2" style="display:none">
      <p>
        <?php print t('Include your transaction or receipt number. Do not put any credit card information in this field.'); ?>
      </p>
    </div>
    <div id="tooltip_box_text_3" style="display:none">
      <p>
        <?php print t(
            'Add your personal contact information here as well as anything that is personal about your situation
         that you may not want to share publicly.'
        ); ?>
      </p>
    </div>
  </div>
</div>


<div class="col-lower clearfix">
  <div class="step_2_messages" style="display:none"></div>

  <h2><?php print t('What business is your gripe with? *'); ?></h2>

  <div id="biz_search_div">
    <h3><?php print t('Search for the business below'); ?></h3>
    <div class="search_div_messages" style="display:none"></div>

    <?php print drupal_render($form['biz_search_name']); ?>
    <?php print drupal_render($form['biz_search_address']); ?>
    <?php print drupal_render($form['biz_search_city']); ?>

    <div class="contain-select">
      <label id="label_edit-biz-search-state" for="edit-biz-search-state">State </label>
      <?php print drupal_render($form['biz_search_state']); ?>
    </div>

    <div class="contain-select">
      <label id="label_edit-biz-search-country" for="edit-biz-search-country">Country </label>
      <?php print drupal_render($form['biz_search_country']); ?>
    </div>

    <?php print drupal_render($form['biz_search_button']); ?>
    <?php print drupal_render($form['biz_search_results']); ?>
  </div>

  <div id="biz_manual_div" style="display:none" class="clearfix">
    <h3><?php print t('Please fill out this form to add the business you are griping about'); ?></h3>
    <div class="reqired"><span>*</span><?php print t(' Required Fields'); ?></div>

    <div class="biz-manual-messages" style="display:none"></div>

    <div class="clearfix">
    <?php print drupal_render($form['biz_manual_name']); ?>
    <?php print drupal_render($form['biz_manual_address']); ?>
    </div>

    <div class="clearfix">
    <?php print drupal_render($form['biz_manual_city']); ?>

    <div class="contain-select">
      <label id="label_edit-biz-search-state" for="biz_manual_state">State/Province *</label>
      <?php print drupal_render($form['biz_manual_state']); ?>
    </div>

    <div class="contain-select">
      <label id="label_edit-biz-search-state" for="biz_manual_country">Country *</label>
      <?php print drupal_render($form['biz_manual_country']); ?>
    </div>

    <?php print drupal_render($form['biz_manual_zip']); ?>
    </div>

    <div class="clearfix">
    <?php print drupal_render($form['biz_manual_phone']); ?>
    <?php print drupal_render($form['biz_manual_site']); ?>
    </div>

    <div class="clearfix">
    <?php print drupal_render($form['biz_manual_email']); ?>
    <?php print drupal_render($form['biz_manual_twitter']); ?>
    </div>

    <input type="button" id="biz_save_btn" value="Save" >
    <input type="button" id="click_to_search_btn" value="Cancel" >
  </div>

</div>


<?php print drupal_render_children($form); ?>
