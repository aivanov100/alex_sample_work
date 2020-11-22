<?php print drupal_render($form['progress_bar']); ?>

<div class="warming">
  <?php print t('Security Reminder: Do not include private information (address, phone number) in this section. Please wait until step 2.'); ?>
</div>

<div class="col-one">
  <?php print drupal_render($form['gripe_title']); ?> <a href="#" class="tooltip" id="gripe_title_tooltip">Help?</a>
  <?php print drupal_render($form['gripe_description']); ?> <a href="#" class="tooltip" id="gripe_description_tooltip">Help?</a>
  <?php print drupal_render_children($form); ?>
</div>

<div class="col-two">
  <div id="tooltip-box">
    <div id="tooltip_box_text_0">
      <p>
        <?php print t(
            'We know you may be feeling frustrated. We encourage you to think about what you\'re going to say.
         Focus on the facts. If you present your case in a polite manner, you\'re more likely to get a response from the company.'
        ); ?>
      </p>
    </div>
    <div id="tooltip_box_text_1" style="display:none">
      <p>
        <?php print t(
            'Make the gripe title interesting & inviting to other visitors so they will want to read your gripe.
         It\'s ok to have the company name in the title, but be sure to elaborate and avoid titles like Company X sucks.'
        ); ?>
      </p>
    </div>
    <div id="tooltip_box_text_2" style="display:none">
      <p>
        <?php print t(
            'Here\'s the part where you can share the details about your bad customer service experience and
         explain the situation. It\'s good to include any information that will be helpful in assisting the company you
         have a gripe with address the situation. But, please don\'t put any sensitive or private information in this field.'
        ); ?>
      </p>
    </div>
    <p><a href="/etiquette"><?php print t('Click here for our gripe etiquette tips'); ?></a></p>
  </div>
</div>

<!-- <hr />
<?php print t('* Required Information'); ?>
<hr /> -->
