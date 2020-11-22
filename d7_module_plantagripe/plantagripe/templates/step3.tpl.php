<?php print drupal_render($form['progress_bar']); ?>

<div class="messages" style="display:none"></div>

<!-- <?php print t('at least one selection is required'); ?> -->

<div class="row clearfix">
    <h4><?php print t('At least one selection is required'); ?></h4>
    <?php print drupal_render($form['gripe_about']); ?>
</div>


<div class="row clearfix">
    <h3><?php print t('What are you looking for?'); ?><span><?php print t('Select your desired resolution'); ?></span></h3>
    <h4><?php print t('At least one selection is required'); ?></h4>
    
    <!-- <?php print t('at least one selection is required'); ?> -->
    
    <?php print drupal_render($form['gripe_looking_for']); ?>
</div>


<div class="row clearfix">
    <h3><?php print t('Do you have pictures, videos or other documents?'); ?><span><?php print t('Add proof to your claim'); ?></span></h3>
    
    <div class="box">
        <h3>Add Images</h3>
        <?php print drupal_render($form['field_upload_gripe_images']); ?>
    </div>        

    <div class="box">
        <h3>Add Documents</h3>
        <?php print drupal_render($form['field_upload_gripe_documents']); ?>
    </div>
</div>

<div class="row clearfix">
    <?php print drupal_render($form['youtube_url']); ?>
</div>

<?php print drupal_render_children($form); ?>
