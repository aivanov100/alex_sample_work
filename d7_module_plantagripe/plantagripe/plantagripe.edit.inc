<?php

/**
 * @file
 * Page callback, form constructors and form handlers for multi-step form
 * wizard to edit entities of type: gripevine.
 */

require_once 'plantagripe.form.inc';

/**
 * Page callback for 'Edit Gripe' page.
 *
 * Main form constructor for 'Edit Gripe' multi-step form wizard.
 *
 * @param int $gripe_node
 *   Node ID of the gripevine node being edited.
 *
 * @see plantagripe_edit_gripe_wizard_submit()
 */
function plantagripe_edit_gripe_wizard($form, &$form_state, $gripe_node) {
  drupal_add_css(drupal_get_path('module', 'plantagripe') . '/plantagripe.css');

  if (empty($form_state['step'])) {
    $form_state['step'] = 1;
    $form_state['step_information'] = plantagripe_form_plant_gripe_steps();
    $form_state['edit_gripe_id'] = $gripe_node->nid;

    $gripe_data = plantagripe_extract_gripe_entity_data($gripe_node->nid);
    $form_state['step_information'][1]['stored_values'] = $gripe_data['step1'];
    $form_state['step_information'][2]['stored_values'] = $gripe_data['step2'];
    $form_state['step_information'][3]['stored_values'] = $gripe_data['step3'];
    $form_state['edit_gripe_factual_id'] = $gripe_data['step2']['factual_id'];
    $form_state['loaded_gripe_documents'] = $gripe_data['loaded_gripe_documents'];
    $form_state['loaded_gripe_images'] = $gripe_data['loaded_gripe_images'];
  }

  $step = &$form_state['step'];
  $form_state['values'] = $form_state['step_information'][$step]['stored_values'];
  $form = $form_state['step_information'][$step]['form']($form, $form_state);

  $form['progress_bar'] = array('#type' => 'actions');
  $form['progress_bar']['step1'] = array(
    '#type' => 'submit',
    '#value' => t('Step 1'),
    '#submit' => array('plantagripe_create_gripe_wizard_edit_step'),
    '#attributes' => ($step == 1) ? array('class' => array('form-button-current-step')) : "",
  );
  $form['progress_bar']['step2'] = array(
    '#type' => 'submit',
    '#value' => t('Step 2'),
    '#submit' => array('plantagripe_create_gripe_wizard_edit_step'),
    '#attributes' => ($step == 2) ? array('class' => array('form-button-current-step')) : "",
  );
  $form['progress_bar']['step3'] = array(
    '#type' => 'submit',
    '#value' => t('Step 3'),
    '#submit' => array('plantagripe_create_gripe_wizard_edit_step'),
    '#attributes' => ($step == 3) ? array('class' => array('form-button-current-step')) : "",
  );

  $form['update'] = array(
    '#type' => 'submit',
    '#value' => t('Update Gripe'),
  );

  if ($step < count($form_state['step_information'])) {
    $nextButtonText = ($step == 1) ? t('Continue to Step 2') : t('Continue to final step');
    $form['next'] = array(
      '#type' => 'submit',
      '#value' => $nextButtonText,
      '#name' => 'next',
      '#submit' => array('plantagripe_create_gripe_wizard_next_submit'),
    );
  }

  if (function_exists($form_state['step_information'][$step]['form'] . '_validate')) {
    $form['next']['#validate'] = array($form_state['step_information'][$step]['form'] . '_validate');
    $form['update']['#validate'] = array($form_state['step_information'][$step]['form'] . '_validate');
  }

  return $form;
}

/**
 * Submit handler for 'Edit Gripe' multi-step form wizard.
 *
 * @see plantagripe_edit_gripe_wizard()
 */
function plantagripe_edit_gripe_wizard_submit($form, &$form_state) {
  $current_step = &$form_state['step'];
  $form_state['step_information'][$current_step]['stored_values'] = $form_state['values'];

  $saved_gv = plantagripe_update_gripevine_node($form_state);
  drupal_goto($saved_gv['redirectTo']);
}

/**
 * Load gripevine node and set form values for 'Edit Gripe' multi-step form wizard.
 *
 * @param int $gripe_id
 *   Node ID of the gripevine node being loaded.
 *
 * @return array $gripe_data
 *   Contains loaded gripevine node field values to be populated into 'Edit Gripe'
 *   multi-step form wizard.
 */
function plantagripe_extract_gripe_entity_data($gripe_id) {

  $loaded_gripe = entity_load('node', array($gripe_id));
  $gripe_wrapper = entity_metadata_wrapper('node', reset($loaded_gripe));

  $gripe_data = array();
  $step1values = array();
  $step2values = array();
  $step3values = array();

  // Step 1 data.
  $step1values['gripe_title'] = $gripe_wrapper->title->value();
  $field_gripevine_the_gripe = $gripe_wrapper->field_gripevine_the_gripe->value();
  $step1values['gripe_description'] = $field_gripevine_the_gripe['value'];

  $gripe_data['step1'] = $step1values;

  // Step 2 data.
  $step2values['private_info'] = $gripe_wrapper->field_account_info_private->value();
  $step2values['account_number'] = $gripe_wrapper->field_gripe_account_number->value();
  $step2values['reference_number'] = $gripe_wrapper->field_gripe_account_reference->value();

  $field_gripe_biz_ref = $gripe_wrapper->field_gripe_biz_ref->value();
  $biz_nid = $field_gripe_biz_ref[0]->nid;
  plantagripe_extract_business_data($biz_nid, $step2values);

  $gripe_data['step2'] = $step2values;

  // Step 3 data.
  $loaded_gripe_array = reset($loaded_gripe);
  $field_youtube = $loaded_gripe_array->field_youtube;
  $step3values['youtube_url'] = $field_youtube[LANGUAGE_NONE][0]['input'];

  $field_gripevine_about_tags = $loaded_gripe_array->field_gripevine_about_tags[LANGUAGE_NONE];
  $gripe_about = array();
  for ($i = 1; $i <= 15; $i++) {
    $gripe_about[$i] = 0;
  }
  foreach ($field_gripevine_about_tags as $key => $value) {
    $gripe_about[$value['tid']] = $value['tid'];
  }
  $step3values['gripe_about'] = $gripe_about;

  $field_lookingfor_tags = $loaded_gripe_array->field_lookingfor_tags[LANGUAGE_NONE];
  $gripe_looking_for = array();
  for ($i = 16; $i <= 22; $i++) {
    $gripe_looking_for[$i] = 0;
  }
  foreach ($field_lookingfor_tags as $key => $value) {
    $gripe_looking_for[$value['tid']] = $value['tid'];
  }
  $step3values['gripe_looking_for'] = $gripe_looking_for;

  $gripe_data['step3'] = $step3values;

  $uploaded_documents = $gripe_wrapper->field_upload_gripe_documents->value();
  $loaded_gripe_documents = array();
  $weight = 0;
  foreach ($uploaded_documents as $key => $document_data) {
    $uploaded_doc = array(
      'fid'  => $document_data['fid'],
      /*'description'  => $document_data['description'],*/
      'display'  => $document_data['display'],
      '_weight'  => $weight,
      /*'upload_button'  => 'Upload',
      'remove_button' => 'Remove',
      'upload' => '',*/
    );
    $loaded_gripe_documents[] = $uploaded_doc;
    $weight++;
  }
  $gripe_data['loaded_gripe_documents'] = $loaded_gripe_documents;

  $uploaded_images = $gripe_wrapper->field_upload_gripe_images->value();
  $loaded_gripe_images = array();
  $weight = 0;
  foreach ($uploaded_images as $key => $image_data) {
    $uploaded_image = array(
      /*'title' => $image_data['title'],*/
      'fid'  => $image_data['fid'],
      /*'display'  => '1',
      'width' => $image_data['width'],
      'height' => $image_data['height'],*/
      '_weight'  => $weight,
      /*'description'  => '',
      'upload_button'  => 'Upload',
      'remove_button' => 'Remove',
      'upload' => '',
      'alt' => '',*/
    );
    $loaded_gripe_images[] = $uploaded_image;
    $weight++;
  }
  $gripe_data['loaded_gripe_images'] = $loaded_gripe_images;

  return $gripe_data;
}

/**
 * Load data for related node of type: business.
 *
 * @param int $biz_nid
 *   Node ID of the business node being loaded.
 *
 * @param array $step2values
 *   Contains form values to be populated into 'Edit Gripe - Step 2' form.
 */
function plantagripe_extract_business_data($biz_nid, &$step2values) {
  $loaded_biz = entity_load('node', array($biz_nid));
  $biz_wrapper = entity_metadata_wrapper('node', reset($loaded_biz));
  $step2values['factual_id'] = $biz_wrapper->field_biz_api_id_factual->value();

  if (!empty($step2values['factual_id'])) {
    _extract_factual_business_data($biz_wrapper, $step2values);
  }
  else {
    _extract_manual_business_data($biz_wrapper, $step2values);
  }
}

/**
 * Set form values for business indexed by factual.
 *
 * @param entity_metadata_wrapper $biz_wrapper
 *   Entity wrapper for node of type: business.
 *
 * @param array $step2values
 *   Contains form values to be populated into 'Edit Gripe - Step 2' form.
 */
function _extract_factual_business_data($biz_wrapper, &$step2values) {
  $step2values['manual_biz_selected'] = 0;

  $step2values['biz_search_name'] = $biz_wrapper->title->value();

  $biz_street = $biz_wrapper->field_biz_address->value();
  $step2values['biz_search_address'] = $biz_street;

  $biz_city = $biz_wrapper->field_biz_city->value();
  $step2values['biz_search_city'] = $biz_city;

  $field_biz_country = $biz_wrapper->field_biz_country->value();
  $countryOptions = plantagripe_get_country_dropdown_options();
  $country_display_text = '';
  if ($field_biz_country == "us") {
    $field_biz_country = array_search("United States", $countryOptions);
    $country_display_text = 'United States';
  }
  if ($field_biz_country == "ca") {
    $field_biz_country = array_search("Canada", $countryOptions);
    $country_display_text = 'Canada';
  }
  $step2values['biz_search_country'] = $field_biz_country;

  $biz_state_abbr = $biz_wrapper->field_biz_state->value();
  $field_biz_state = '';
  $gripeCountryObj = taxonomy_vocabulary_machine_name_load('countries');
  $gripeStatesTerms = taxonomy_get_tree($gripeCountryObj->vid, $field_biz_country, 2);
  foreach ($gripeStatesTerms as $key => $value) {
    if ($value->description == $biz_state_abbr) {
      $field_biz_state = $value->tid;
      break;
    }
  }
  $step2values['biz_search_state'] = $field_biz_state;

  $step2values['selected_biz_name'] = $step2values['biz_search_name'];
  $step2values['selected_biz_address'] = $biz_street . ', ' . $biz_city . ', ' . $biz_state_abbr . ', ' . $country_display_text;
}

/**
 * Set form values for manually-entered business.
 *
 * @param entity_metadata_wrapper $biz_wrapper
 *   Entity wrapper for node of type: business.
 *
 * @param array $step2values
 *   Contains form values to be populated into 'Edit Gripe - Step 2' form.
 */
function _extract_manual_business_data($biz_wrapper, &$step2values) {
  $step2values['manual_biz_selected'] = 1;

  $step2values['biz_manual_name'] = $biz_wrapper->title->value();

  $biz_street = $biz_wrapper->field_biz_address->value();
  $step2values['biz_manual_address'] = $biz_street;

  $biz_city = $biz_wrapper->field_biz_city->value();
  $step2values['biz_manual_city'] = $biz_city;

  $field_biz_country = $biz_wrapper->field_biz_country->value();
  $countryOptions = plantagripe_get_country_dropdown_options();
  $country_display_text = '';
  if (strtolower($field_biz_country) == "us") {
    $field_biz_country = array_search("United States", $countryOptions);
    $country_display_text = 'United States';
  }
  if (strtolower($field_biz_country) == "ca") {
    $field_biz_country = array_search("Canada", $countryOptions);
    $country_display_text = 'Canada';
  }
  $step2values['biz_manual_country'] = $field_biz_country;

  $biz_state_abbr = $biz_wrapper->field_biz_state->value();
  $field_biz_state = '';
  $gripeCountryObj = taxonomy_vocabulary_machine_name_load('countries');
  $gripeStatesTerms = taxonomy_get_tree($gripeCountryObj->vid, strtolower($field_biz_country), 2);
  foreach ($gripeStatesTerms as $key => $value) {
    if ($value->description == $biz_state_abbr) {
      $field_biz_state = $value->tid;
      break;
    }
  }
  $step2values['biz_manual_state'] = $field_biz_state;

  $step2values['biz_manual_zip'] = $biz_wrapper->field_biz_zipcode->value();
  $step2values['biz_manual_phone'] = $biz_wrapper->field_biz_phone->value();
  $step2values['biz_manual_site'] = $biz_wrapper->field_biz_website->value();
  $step2values['biz_manual_email'] = $biz_wrapper->field_biz_email->value();
  $step2values['biz_manual_twitter'] = $biz_wrapper->field_biz_twitter->value();

  $step2values['selected_biz_name'] = $step2values['biz_manual_name'];
  $step2values['selected_biz_address'] = $biz_street . ', ' . $biz_city . ', ' . $biz_state_abbr . ', ' . $country_display_text;

  $step2values['biz_search_name'] = $step2values['biz_manual_name'];
  $step2values['biz_search_address'] = $step2values['biz_manual_address'];
  $step2values['biz_search_city'] = $step2values['biz_manual_city'];
  $step2values['biz_search_country'] = $step2values['biz_manual_country'];
  $step2values['biz_search_state'] = $step2values['biz_manual_state'];
}

/**
 * Save gripevine node with updated field values.
 *
 * @param array $form_state
 *   Contains $form_state value from 'Edit Gripe' multi-step form wizard.
 *
 * @return array
 *   Contains saved node nid, user uid, and redirectTo url.
 */
function plantagripe_update_gripevine_node($form_state) {

  global $user, $base_url;
  $step1values = $form_state['step_information']['1']['stored_values'];
  $step2values = $form_state['step_information']['2']['stored_values'];
  $step3values = $form_state['step_information']['3']['stored_values'];

  $gripe_entity = entity_load('node', array($form_state['edit_gripe_id']));
  $gripe_wrapper = entity_metadata_wrapper('node', reset($gripe_entity));

  $gripe_wrapper->title = $step1values['gripe_title'];
  $field_the_gripe_vals = array('value' => $step1values['gripe_description'], 'format' => 'filtered_html');
  $gripe_wrapper->field_gripevine_the_gripe = $field_the_gripe_vals;

  $gripe_wrapper->field_account_info_private = $step2values['private_info'];
  $gripe_wrapper->field_gripe_account_number = $step2values['account_number'];
  $gripe_wrapper->field_gripe_account_reference = $step2values['reference_number'];

  if ($step2values['factual_id'] != $form_state['edit_gripe_factual_id']) {
    foreach ($form_state['step_information']['2']['search_results'] as $result) {
      if ($result['factual_id'] == $step2values['factual_id']) {
        $chosen_business = $result;
        break;
      }
    }
    $biz_nid = plantagripe_save_business_searched($chosen_business);
    $gripe_wrapper->field_gripe_biz_ref = array($biz_nid);
  }

  $aboutTagsArray = array();
  foreach ($step3values['gripe_about'] as $key => $value) {
    if ($value != 0) {
      $aboutTagsArray[] = $key;
    }
  }
  $gripe_wrapper->field_gripevine_about_tags = $aboutTagsArray;

  $lookingForTagsArray = array();
  foreach ($step3values['gripe_looking_for'] as $key => $value) {
    if ($value != 0) {
      $lookingForTagsArray[] = $key;
    }
  }
  $gripe_wrapper->field_lookingfor_tags = $lookingForTagsArray;

  if (!empty($form_state['values']['field_upload_gripe_images'][LANGUAGE_NONE])) {
    $gripeImages = array();
    foreach ($form_state['values']['field_upload_gripe_images'][LANGUAGE_NONE] as $key => $value) {
      if (is_numeric($key) && !empty($value['fid'])) {
        if (!isset($value['title']) || empty($value['title'])) {
          $gripeImages[] = array('fid' => $value['fid'], 'display' => $value['display']);
        }
        else {
          $gripeImages[] = array(
            'alt' => $value['title'],
            'title' => $value['title'],
            'fid' => $value['fid'],
            'display' => $value['display'],
            'description' => $value['title'],
          );
        }
      }
    }
    $gripe_wrapper->field_upload_gripe_images = $gripeImages;
  }

  if (!empty($form_state['values']['field_upload_gripe_documents'][LANGUAGE_NONE])) {
    $gripeDocuments = array();
    foreach ($form_state['values']['field_upload_gripe_documents'][LANGUAGE_NONE] as $key => $value) {
      if (is_numeric($key) && !empty($value['fid'])) {
        if (!isset($value['description']) || empty($value['description'])) {
          $gripeDocuments[] = array('fid' => $value['fid'], 'display' => $value['display']);
        }
        else {
          $gripeDocuments[] = array(
            'alt' => $value['description'],
            'title' => $value['description'],
            'fid' => $value['fid'],
            'display' => $value['display'],
            'description' => $value['description'],
          );
        }
      }
    }
    $gripe_wrapper->field_upload_gripe_documents = $gripeDocuments;
  }

  if (!empty($step3values['youtube_url'])) {
    $lastEqualsPos = strrpos($step3values['youtube_url'], '=');
    if ($lastEqualsPos) {
      $youtubeInputID = substr($step3values['youtube_url'], $lastEqualsPos + 1, strlen($step3values['youtube_url']));
    }
    else {
      $lastSlashPos = strrpos($step3values['youtube_url'], '/');
      $youtubeInputID = substr($step3values['youtube_url'], $lastSlashPos + 1, strlen($step3values['youtube_url']));
    }
    $gripe_entity->field_youtube[LANGUAGE_NONE][0] = array('input' => $step3values['youtube_url'], 'video_id' => $youtubeInputID);
  }

  $gripe_wrapper->save();
  $nid = key($gripe_entity);

  if ($user->uid == 0) {
    $redirectTo = $base_url . '' . url('anonlogin');
  }
  else {
    $redirectTo = $base_url . '' . url('node/' . $nid);
  }

  return array('nid' => $nid, 'uid' => $user->uid, 'redirectTo' => $redirectTo);
}
