<?php

/**
 * @file
 * Page callback, form constructors and form handlers for multi-step form
 * wizard to create entities of type: gripevine.
 */

require_once 'plantagripe.edit.inc';

/**
 * Page callback for 'Plant Your Gripe' page.
 *
 * Main form constructor for 'Create Gripe' multi-step form wizard.
 *
 * @param int $biz_nid
 *   Node ID of the business the gripe is created against.
 *
 * @see plantagripe_create_gripe_wizard_next_submit
 */
function plantagripe_create_gripe_wizard($form, &$form_state, $biz_nid = NULL) {
  drupal_add_css(drupal_get_path('module', 'plantagripe') . '/plantagripe.css');

  if (empty($form_state['step'])) {
    $form_state['step'] = 1;
    $form_state['step_information'] = plantagripe_form_plant_gripe_steps();
    if (!empty($biz_nid)) {
      $step2values = array();
      plantagripe_extract_business_data($biz_nid, $step2values);
      $form_state['loaded_biz_info'] = $step2values;
      $form_state['loaded_biz_nid'] = $biz_nid;
    }
  }

  $step = &$form_state['step'];
  switch ($step) {
    case 1:
      drupal_set_title(t('Plant Your Gripe'));
      break;

    case 2:
      drupal_set_title(t('Private Information Section'));
      break;

    case 3:
      drupal_set_title(t('What is Your Gripe About?'));
      break;
  }

  $form = $form_state['step_information'][$step]['form']($form, $form_state);

  $form['progress_bar'] = array('#type' => 'actions');
  $form['progress_bar']['step1'] = array(
    '#type' => 'submit',
    '#value' => t('Step 1'),
    '#submit' => array('plantagripe_create_gripe_wizard_edit_step'),
    '#disabled' => empty($form_state['step_information'][1]['stored_values']),
    '#attributes' => ($step == 1) ? array('class' => array('form-button-current-step')) : "",
  );
  $form['progress_bar']['step2'] = array(
    '#type' => 'submit',
    '#value' => t('Step 2'),
    '#submit' => array('plantagripe_create_gripe_wizard_edit_step'),
    '#disabled' => empty($form_state['step_information'][2]['stored_values']),
    '#attributes' => ($step == 2) ? array('class' => array('form-button-current-step')) : "",
  );
  $form['progress_bar']['step3'] = array(
    '#type' => 'submit',
    '#value' => t('Step 3'),
    '#submit' => array('plantagripe_create_gripe_wizard_edit_step'),
    '#disabled' => empty($form_state['step_information'][3]['stored_values']),
    '#attributes' => ($step == 3) ? array('class' => array('form-button-current-step')) : "",
  );

  if ($step < count($form_state['step_information'])) {
    $form['next'] = array(
      '#type' => 'submit',
      '#value' => ($step == 1) ? t('Continue to Step 2') : t('Continue to final step'),
      '#name' => ($step == 2) ? 'next_step_2' : 'next',
      '#submit' => array('plantagripe_create_gripe_wizard_next_submit'),
    );
  }
  else {
    $form['finish'] = array(
      '#type' => 'submit',
      '#value' => t('Plant Your Gripe!'),
      '#validate' => array('plantagripe_create_gripe_wizard_step_3_validate'),
    );
  }

  if (function_exists($form_state['step_information'][$step]['form'] . '_validate')) {
    $form['next']['#validate'] = array($form_state['step_information'][$step]['form'] . '_validate');
  }

  return $form;
}

/**
 * Populate values for $form_state['step_information'] field.
 *
 * @return
 *   an associative array containing the form names for form wizard Step 1-3 subforms.
 */
function plantagripe_form_plant_gripe_steps() {
    return array(
        1 => array(
            'form' => 'plantagripe_create_gripe_wizard_step_1',
        ),
        2 => array(
            'form' => 'plantagripe_create_gripe_wizard_step_2',
        ),
        3 => array(
            'form' => 'plantagripe_create_gripe_wizard_step_3',
        ),
    );
}

/**
 *  Set form values for $form_state['step_information'] field.
 */
function plantagripe_create_gripe_wizard_edit_step($form, &$form_state) {
  $current_step = &$form_state['step'];
  $form_state['step_information'][$current_step]['stored_values'] = $form_state['values'];
  if ($current_step == 2 && !empty($form_state['values']['factual_id'])) {
    $form_state['step_information'][$current_step]['search_results'] = _get_factual_search_results($form_state);
  }

  switch ($form_state['values']['op']) {
    case 'Step 1':
      $current_step = 1;
      break;

    case 'Step 2':
      $current_step = 2;
      break;

    case 'Step 3':
      $current_step = 3;
      break;
  }
  $form_state['values'] = $form_state['step_information'][$current_step]['stored_values'];

  $form_state['rebuild'] = TRUE;
}

/**
 *  Submit handler for 'Create Gripe' multi-step form wizard.
 *
 * @see plantagripe_create_gripe_wizard
 */
function plantagripe_create_gripe_wizard_next_submit($form, &$form_state) {
  $current_step = &$form_state['step'];
  $form_state['step_information'][$current_step]['stored_values'] = $form_state['values'];
  if ($current_step == 2 && !empty($form_state['values']['factual_id'])) {
    $form_state['step_information'][$current_step]['search_results'] = _get_factual_search_results($form_state);
  }

  if ($current_step < count($form_state['step_information'])) {
    $current_step++;
    if (!empty($form_state['step_information'][$current_step]['stored_values'])) {
      $form_state['values'] = $form_state['step_information'][$current_step]['stored_values'];
    }
    elseif ($current_step == 2 && !empty($form_state['loaded_biz_info'])) {
      $form_state['values'] = $form_state['loaded_biz_info'];
    }
    else {
      $form_state['values'] = array();
    }
    // Force rebuild with next step.
    $form_state['rebuild'] = TRUE;
    return;
  }
}

/**
 * Form constructor for 'Create Gripe - Step 1' form.
 *
 * @see plantagripe_create_gripe_wizard_step_1_validate()
 */
function plantagripe_create_gripe_wizard_step_1($form, &$form_state) {
  drupal_add_js(drupal_get_path('module', 'plantagripe') . '/js/step1.js');

  $form = array();
  $form['gripe_title'] = array(
    '#type' => 'textfield',
    '#title' => t('Give your gripe a catchy title *'),
    '#size' => 100,
    '#maxlength' => 80,
    '#default_value' => !empty($form_state['values']['gripe_title']) ? $form_state['values']['gripe_title'] : '',
  );
  $form['gripe_description'] = array(
    '#type' => 'textarea',
    '#title' => t('So what happened? *'),
    '#default_value' => !empty($form_state['values']['gripe_description']) ? $form_state['values']['gripe_description'] : '',
  );

  $form['#theme'] = 'step1';
  return $form;
}

/**
 * Form validation handler for plantagripe_create_gripe_wizard_step_1().
 *
 * @see plantagripe_create_gripe_wizard_step_1()
 */
function plantagripe_create_gripe_wizard_step_1_validate($form, &$form_state) {
  if ($form_state['values']['gripe_title'] == '') {
    form_set_error('gripe_title', t('You need to give your gripe a title.'));
  }
  if ($form_state['values']['gripe_description'] == '') {
    form_set_error('gripe_description', t('You need to describe your gripe.'));
  }
}

/**
 * Form constructor for 'Create Gripe - Step 2' form.
 *
 * @see plantagripe_create_gripe_wizard_step_2_validate()
 */
function plantagripe_create_gripe_wizard_step_2($form, &$form_state) {
  drupal_add_js(drupal_get_path('module', 'plantagripe') . '/js/step2.js');
  drupal_add_js(drupal_get_path('module', 'plantagripe') . '/js/step2_enter_biz.js');
  drupal_add_js(drupal_get_path('module', 'plantagripe') . '/js/step2_search_biz.js');

  $form = array();
  $form['account_number'] = array(
    '#type' => 'textfield',
    '#title' => t('Your company account number'),
    '#default_value' => !empty($form_state['values']['account_number']) ? $form_state['values']['account_number'] : '',
  );
  $form['reference_number'] = array(
    '#type' => 'textfield',
    '#title' => t('Your company reference number'),
    '#default_value' => !empty($form_state['values']['reference_number']) ? $form_state['values']['reference_number'] : '',
  );
  $form['private_info'] = array(
    '#type' => 'textarea',
    '#title' => t('Any other private information'),
    '#default_value' => !empty($form_state['values']['private_info']) ? $form_state['values']['private_info'] : '',
  );

  // biz_search_div.
  $form['biz_search_name'] = array(
    '#type' => 'textfield',
    '#title' => t('Business Name'),
    '#default_value' => !empty($form_state['values']['biz_search_name']) ? $form_state['values']['biz_search_name'] : '',
  );
  $form['biz_search_address'] = array(
    '#type' => 'textfield',
    '#title' => t('Address'),
    '#default_value' => !empty($form_state['values']['biz_search_address']) ? $form_state['values']['biz_search_address'] : '',
  );

  $smart_location = plantagripe_get_location_data();
  $form['biz_search_city'] = array(
    '#type' => 'textfield',
    '#title' => t('City'),
    '#default_value' => !empty($form_state['values']['biz_search_city']) ? $form_state['values']['biz_search_city'] : $smart_location['city'],
  );

  $countryOptions = plantagripe_get_country_dropdown_options();
  if (!empty($form_state['values']['biz_search_country'])) {
    $biz_search_country_tid = $form_state['values']['biz_search_country'];
  }
  elseif (!empty($smart_location['country'])) {
    $biz_search_country_tid = array_search($smart_location['country'], $countryOptions);
  }
  else {
    $biz_search_country_tid = array_search("United States", $countryOptions);
  }
  $form['biz_search_country'] = array(
    '#type' => 'select',
    '#options' => $countryOptions,
    '#default_value' => $biz_search_country_tid,
    '#ajax' => array(
      'callback' => 'plantagripe_step2_select_country',
    ),
  );

  $stateOptions = plantagripe_get_state_dropdown_options($biz_search_country_tid);
  if (!empty($form_state['values']['biz_search_state'])) {
    $biz_search_state_tid = $form_state['values']['biz_search_state'];
  }
  elseif (!empty($smart_location['state'])) {
    $biz_search_state_tid = array_search($smart_location['state'], $stateOptions);
  }
  else {
    $biz_search_state_tid = 0;
  }
  $form['biz_search_state'] = array(
    '#type' => 'select',
    '#options' => $stateOptions,
    '#default_value' => $biz_search_state_tid,
  );

  $form['biz_search_button'] = array(
    '#type' => 'button',
    '#value' => t('Search'),
    '#ajax' => array(
      'callback' => 'plantagripe_step2_search_business',
    ),
  );

  $form['search_performed'] = array(
    '#type' => 'hidden',
    '#default_value' => !empty($form_state['values']['search_performed']) ? $form_state['values']['search_performed'] : '0',
  );

  $form['biz_search_results'] = array(
    '#type' => 'markup',
    '#title' => t('Search Results'),
    '#prefix' => '<div id="biz-search-results">',
    '#suffix' => '</div>',
    '#markup' => $form_state['values']['search_performed'] == '1' ? _populate_search_results_div($form_state) : '',
  );

  $form['factual_id'] = array(
    '#type' => 'textfield',
    '#attributes' => array('style' => array('display:none')),
    '#default_value' => !empty($form_state['values']['factual_id']) ? $form_state['values']['factual_id'] : '',
  );

  $form['manual_biz_selected'] = array(
    '#type' => 'textfield',
    '#attributes' => array('style' => array('display:none')),
    '#default_value' => !empty($form_state['values']['manual_biz_selected']) ? $form_state['values']['manual_biz_selected'] : '0',
  );

  // biz_manual_div.
  $form['biz_manual_name'] = array(
    '#type' => 'textfield',
    '#title' => t('Business Name *'),
    '#default_value' => !empty($form_state['values']['biz_manual_name']) ? $form_state['values']['biz_manual_name'] : '',
  );
  $form['biz_manual_address'] = array(
    '#type' => 'textfield',
    '#title' => t('Address *'),
    '#default_value' => !empty($form_state['values']['biz_manual_address']) ? $form_state['values']['biz_manual_address'] : '',
  );
  $form['biz_manual_city'] = array(
    '#type' => 'textfield',
    '#title' => t('City *'),
    '#default_value' => !empty($form_state['values']['biz_manual_city']) ? $form_state['values']['biz_manual_city'] : '',
  );
  $biz_manual_country_tid = (!empty($form_state['values']['biz_manual_country'])) ? $form_state['values']['biz_manual_country'] : $biz_search_country_tid;
  $form['biz_manual_country'] = array(
    '#type' => 'select',
    '#options' => $countryOptions,
    '#default_value' => $biz_manual_country_tid,
    '#ajax' => array(
      'callback' => 'plantagripe_biz_manual_change_state',
      'wrapper' => 'biz-manual-state-dropdown',
    ),
  );
  $biz_manual_state_tid = (!empty($form_state['values']['biz_manual_state'])) ? $form_state['values']['biz_manual_state'] : $biz_search_state_tid;
  $form['biz_manual_state'] = array(
    '#type' => 'select',
    '#prefix' => '<div id="biz-manual-state-dropdown">',
    '#suffix' => '</div>',
    '#options' => plantagripe_get_state_dropdown_options($biz_manual_country_tid),
    '#default_value' => $biz_manual_state_tid,
  );
  $form['biz_manual_zip'] = array(
    '#type' => 'textfield',
    '#title' => t('Postal Code'),
    '#default_value' => !empty($form_state['values']['biz_manual_zip']) ? $form_state['values']['biz_manual_zip'] : '',
  );
  $form['biz_manual_phone'] = array(
    '#type' => 'textfield',
    '#title' => t('Phone Number'),
    '#default_value' => !empty($form_state['values']['biz_manual_phone']) ? $form_state['values']['biz_manual_phone'] : '',
  );
  $form['biz_manual_site'] = array(
    '#type' => 'textfield',
    '#title' => t('Website'),
    '#default_value' => !empty($form_state['values']['biz_manual_site']) ? $form_state['values']['biz_manual_site'] : '',
  );
  $form['biz_manual_email'] = array(
    '#type' => 'textfield',
    '#title' => t('Email'),
    '#default_value' => !empty($form_state['values']['biz_manual_email']) ? $form_state['values']['biz_manual_email'] : '',
  );
  $form['biz_manual_twitter'] = array(
    '#type' => 'textfield',
    '#title' => t('Twitter Account'),
    '#default_value' => !empty($form_state['values']['biz_manual_twitter']) ? $form_state['values']['biz_manual_twitter'] : '',
  );

  $form['selected_biz_name'] = array(
    '#type' => 'textfield',
    '#attributes' => array('style' => array('display:none')),
    '#default_value' => !empty($form_state['values']['selected_biz_name']) ? $form_state['values']['selected_biz_name'] : '',
  );

  $form['selected_biz_address'] = array(
    '#type' => 'textfield',
    '#attributes' => array('style' => array('display:none')),
    '#default_value' => !empty($form_state['values']['selected_biz_address']) ? $form_state['values']['selected_biz_address'] : '',
  );

  // business_information_container.
  if (!empty($form_state['values']['factual_id']) || $form_state['values']['manual_biz_selected'] == 1) {
    $business_chosen = TRUE;
  }
  else {
    $business_chosen = FALSE;
  }

  $form['info_container_div'] = array(
    '#type' => 'markup',
    '#markup' => theme('step2_info_container', array('show' => $business_chosen)),
  );

  $form['#theme'] = 'step2';
  return $form;
}

/**
 * Form validation handler for plantagripe_create_gripe_wizard_step_2().
 *
 * @see plantagripe_create_gripe_wizard_step_2()
 */
function plantagripe_create_gripe_wizard_step_2_validate($form, &$form_state) {
  if (empty($form_state['values']['factual_id']) && $form_state['values']['manual_biz_selected'] == 0) {
    form_set_error(t('You need to select a business'));
  }
}

/**
 * Get country values for country-selection dropdown from 'countries' taxonomy.
 *
 * @return array $countryOptions
 *   contains values for country-selection dropdown for 'Create Gripe - Step 2' form.
 */
function plantagripe_get_country_dropdown_options() {
  $countryOptions = array();

  $gripeCountryObj = taxonomy_vocabulary_machine_name_load('countries');
  $gripeCountryTerms = taxonomy_get_tree($gripeCountryObj->vid, 0, 1);
  foreach ($gripeCountryTerms as $key => $value) {
    $countryOptions[$value->tid] = $value->name;
  }
  return $countryOptions;
}

/**
 * Get state values for state-selection dropdown from 'countries' taxonomy.
 *
 * @param int $country_tid
 *   Country identifier
 *
 * @return array $stateOptions
 *   contains values for state-selection dropdown for 'Create Gripe - Step 2' form.
 */
function plantagripe_get_state_dropdown_options($country_tid) {
  $stateOptions = array();
  $stateOptions[0] = "Choose your state/province";

  if ($country_tid != 0) {
    $gripeCountryObj = taxonomy_vocabulary_machine_name_load('countries');
    $gripeStatesTerms = taxonomy_get_tree($gripeCountryObj->vid, $country_tid, 2);
    foreach ($gripeStatesTerms as $key => $value) {
      $stateOptions[$value->tid] = $value->name;
    }
  }
  return $stateOptions;
}

/**
 * Ajax callback for $form['biz_manual_country'].
 *
 * @return int
 *   value corresponding to manually-chosen state for state-selection dropdown.
 */
function plantagripe_biz_manual_change_state($form, $form_state) {
  return $form['biz_manual_state'];
}

/**
 * Form constructor for 'Create Gripe - Step 3' form.
 *
 * @see plantagripe_create_gripe_wizard_step_3_validate()
 */
function plantagripe_create_gripe_wizard_step_3($form, &$form_state) {
  drupal_add_js(drupal_get_path('module', 'plantagripe') . '/js/step3.js');
  $form = array();

  $gripeAboutObj = taxonomy_vocabulary_machine_name_load('about');
  $gripeAboutTerms = taxonomy_get_tree($gripeAboutObj->vid);
  $gripeAboutCheckboxes = array();
  foreach ($gripeAboutTerms as $key => $value) {
    $gripeAboutCheckboxes[$value->tid] = $value->name;
  }
  $form['gripe_about'] = array(
    '#type' => 'checkboxes',
    '#options' => $gripeAboutCheckboxes,
    '#default_value' => !empty($form_state['values']['gripe_about']) ? $form_state['values']['gripe_about'] : '',
  );

  $gripeLookingForObj = taxonomy_vocabulary_machine_name_load('looking_for');
  $gripeLookingForTerms = taxonomy_get_tree($gripeLookingForObj->vid);
  $gripeLookingForCheckboxes = array();
  foreach ($gripeLookingForTerms as $key => $value) {
    $gripeLookingForCheckboxes[$value->tid] = $value->name;
  }
  $form['gripe_looking_for'] = array(
    '#type' => 'checkboxes',
    '#options' => $gripeLookingForCheckboxes,
    '#default_value' => !empty($form_state['values']['gripe_looking_for']) ? $form_state['values']['gripe_looking_for'] : '',
  );

  $form['youtube_url'] = array(
    '#type' => 'textfield',
    '#title' => t('YouTube URL'),
    '#default_value' => !empty($form_state['values']['youtube_url']) ? $form_state['values']['youtube_url'] : '',
  );

  $entity = new stdClass();
  $entity->type = 'gripevine';
  $entity->language = LANGUAGE_NONE;
  field_attach_form('node', $entity, $form, $form_state);
  unset($form['field_account_info_private'], $form['field_biz_auth_ref'], $form['field_biz_first_comment'], $form['field_biz_rating_ref'],
  $form['field_discuss_end_ref'], $form['field_followup_ref'], $form['field_gripe_account_number'], $form['field_gripe_account_reference'],
  $form['field_gripe_biz_ref'], $form['field_gripe_status'], $form['field_gripevine_about_tags'], $form['field_gripevine_the_gripe'],
  $form['field_lookingfor_tags'], $form['field_plant_id'], $form['field_pvtconnect_ref'], $form['field_response_rating'],
  $form['field_youtube'], $form['field_airlines_template'], $form['field_internal_status'], $form['field_consolidate_business'],
  $form['field_bitly'], $form['field_agent'], $form['field_twitter_status'], $form['field_gripe_poll_ref']);

  if (!empty($form_state['loaded_gripe_documents'])) {
    $default_gripe_documents_data = $form['field_upload_gripe_documents'][LANGUAGE_NONE];
    $default_doc_widget = $form['field_upload_gripe_documents'][LANGUAGE_NONE][0];
    $gripe_documents_data = array();
    for ($i = 0; $i < count($form_state['loaded_gripe_documents']); $i++) {
      $doc_widget = $default_doc_widget;
      $doc_widget['#default_value'] = $form_state['loaded_gripe_documents'][$i];
      $doc_widget['#weight'] = $i;
      $gripe_documents_data[] = $doc_widget;
    }
    foreach ($default_gripe_documents_data as $key => $value) {
      if ($key != '0') {
        $gripe_documents_data[$key] = $value;
      }
    }
    $gripe_documents_data['#file_upload_delta'] = count($form_state['loaded_gripe_documents']) - 1;
    $form['field_upload_gripe_documents'][LANGUAGE_NONE] = $gripe_documents_data;
    $form_state['loaded_gripe_documents'] = '';
  }
  elseif (!empty($form_state['values']['field_upload_gripe_documents'])) {
    $default_docs = $form_state['values']['field_upload_gripe_documents'][LANGUAGE_NONE];
    for ($i = 0; $i < (count($default_docs) - 1); $i++) {
      $form['field_upload_gripe_documents'][LANGUAGE_NONE][$i]['#default_value'] = $default_docs[$i];
    }
  }

  if (!empty($form_state['loaded_gripe_images'])) {
    $default_gripe_img_data = $form['field_upload_gripe_images'][LANGUAGE_NONE];
    $default_img_widget = $form['field_upload_gripe_images'][LANGUAGE_NONE][0];
    $gripe_img_data = array();
    for ($i = 0; $i < count($form_state['loaded_gripe_images']); $i++) {
      $img_widget = $default_img_widget;
      $img_widget['#default_value'] = $form_state['loaded_gripe_images'][$i];
      $img_widget['#weight'] = $i;
      $gripe_img_data[] = $img_widget;
    }
    foreach ($default_gripe_img_data as $key => $value) {
      if ($key != '0') {
        $gripe_img_data[$key] = $value;
      }
    }
    $gripe_img_data['#file_upload_delta'] = count($form_state['loaded_gripe_images']) - 1;
    $form['field_upload_gripe_images'][LANGUAGE_NONE] = $gripe_img_data;
    $form_state['loaded_gripe_images'] = '';
  }
  elseif (!empty($form_state['values']['field_upload_gripe_images'])) {
    $default_images = $form_state['values']['field_upload_gripe_images'][LANGUAGE_NONE];
    for ($i = 0; $i < (count($default_images) - 1); $i++) {
      $form['field_upload_gripe_images'][LANGUAGE_NONE][$i]['#default_value'] = $default_images[$i];
    }
  }

  $form['#theme'] = 'step3';
  return $form;
}

/**
 * Form validation handler for plantagripe_create_gripe_wizard_step_3().
 *
 * @see plantagripe_create_gripe_wizard_step_3()
 */
function plantagripe_create_gripe_wizard_step_3_validate($form, &$form_state) {

  $gripeAboutSelected = FALSE;
  foreach ($form_state['values']['gripe_about'] as $value) {
    if ($value != 0) {
      $gripeAboutSelected = TRUE;
      break;
    }
  }
  if (!($gripeAboutSelected)) {
    form_set_error('gripe_about', t('Please select what your Gripe is about.'));
  }

  $lookingForSelected = FALSE;
  foreach ($form_state['values']['gripe_looking_for'] as $value) {
    if ($value != 0) {
      $lookingForSelected = TRUE;
      break;
    }
  }
  if (!($lookingForSelected)) {
    form_set_error('gripe_looking_for', t('Please select what you are looking for.'));
  }

}

/**
 * Save gripevine node with collected field values.
 *
 * @param array $form_state
 *   Contains $form_state value from 'Create Gripe' multi-step form wizard.
 *
 * @return array
 *   Contains saved node nid, user uid, and redirectTo url.
 */
function plantagripe_save_gripevine_node($form_state) {

  global $user, $base_url;
  $step_information = $form_state['step_information'];
  $step1values = $step_information['1']['stored_values'];
  $step2values = $step_information['2']['stored_values'];
  $step3values = $step_information['3']['stored_values'];

  $gripe_entity = entity_create('node', array('type' => 'gripevine'));
  $gripe_wrapper = entity_metadata_wrapper('node', $gripe_entity);
  $gripe_entity->uid = $user->uid;

  $gripe_wrapper->title = $step1values['gripe_title'];
  $field_the_gripe_vals = array('value' => $step1values['gripe_description'], 'format' => 'filtered_html');
  $gripe_wrapper->field_gripevine_the_gripe = $field_the_gripe_vals;
  $gripe_wrapper->status = 1;
  $gripe_wrapper->comment = 2;

  $gripe_wrapper->field_account_info_private = $step2values['private_info'];
  $gripe_wrapper->field_gripe_account_number = $step2values['account_number'];
  $gripe_wrapper->field_gripe_account_reference = $step2values['reference_number'];

  if (!empty($form_state['loaded_biz_nid']) && $step2values['search_performed'] == 0) {
    $biz_nid = $form_state['loaded_biz_nid'];
  }
  elseif (empty($step2values['factual_id'])) {
    $biz_nid = plantagripe_save_business_manual($step2values);
  }
  else {
    foreach ($step_information['2']['search_results'] as $result) {
      if ($result['factual_id'] == $step2values['factual_id']) {
        $chosen_business = $result;
        break;
      }
    }
    $biz_nid = plantagripe_save_business_searched($chosen_business);
  }

  $gripe_wrapper->field_gripe_biz_ref = array($biz_nid);

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

  $gripe_wrapper->field_biz_first_comment = 'yescomment';
  $gripe_wrapper->field_gripe_status = 1;
  $gripe_wrapper->field_response_rating = 50;

  $nid = 0;
  $gripe_wrapper->save();
  $nid = $gripe_entity->nid;

  $redirectTo = '';
  if ($user->uid == 0) {
    $redirectTo = $base_url . '' . url('anonlogin');
  }
  else {
    $redirectTo = $base_url . '' . url('node/' . $nid);
  }

  return array('nid' => $nid, 'uid' => $user->uid, 'redirectTo' => $redirectTo);
}

/**
 * Save business node for manually-entered business.
 *
 * @param array $step2vals
 *   Contains form values from 'Create Gripe - Step 2' form.
 *
 * @return int
 *   Node ID of the saved business node.
 */
function plantagripe_save_business_manual($step2vals) {
  $biz_entity = entity_create('node', array('type' => 'business'));

  $biz_wrapper = entity_metadata_wrapper('node', $biz_entity);
  $biz_wrapper->title = $step2vals['biz_manual_name'];
  $biz_wrapper->field_biz_address = $step2vals['biz_manual_address'];
  $biz_wrapper->field_biz_city = $step2vals['biz_manual_city'];

  $stateBusinessObject = taxonomy_term_load($step2vals['biz_manual_state']);
  $biz_wrapper->field_biz_state = $stateBusinessObject->description;

  $countryBusinessObject = taxonomy_term_load($step2vals['biz_manual_country']);
  $biz_wrapper->field_biz_country = $countryBusinessObject->description;

  if (!empty($step2vals['biz_manual_zip'])) {
    $biz_wrapper->field_biz_zipcode = $step2vals['biz_manual_zip'];
  }
  if (!empty($step2vals['biz_manual_phone'])) {
    $biz_wrapper->field_biz_phone = $step2vals['biz_manual_phone'];
  }
  if (!empty($step2vals['biz_manual_site'])) {
    $biz_wrapper->field_biz_website = $step2vals['biz_manual_site'];
  }
  if (!empty($step2vals['biz_manual_email'])) {
    $biz_wrapper->field_biz_email = $step2vals['biz_manual_email'];
  }
  if (!empty($step2vals['biz_manual_twitter'])) {
    $biz_wrapper->field_biz_twitter = $step2vals['biz_manual_twitter'];
  }

  $biz_wrapper->save();
  return $biz_entity->nid;
}

/**
 * Save business node for searched business to cache searched businesses in DB.
 *
 * @param array $chosen_business
 *   Contains Factual data for selected business.
 *
 * @return int
 *   Node ID of the saved business node.
 */
function plantagripe_save_business_searched($chosen_business) {
  $entity_query = new EntityFieldQuery();
  $entity_query
    ->entityCondition('entity_type', 'node', '=')
    ->entityCondition('bundle', 'business')
    ->propertyCondition('status', 1)
    ->fieldCondition('field_biz_api_id_factual', 'value', $chosen_business['factual_id']);
  $result = $entity_query->execute();

  if (isset($result['node'])) {
    $bizId = key($result['node']);
    return $bizId;
  }
  else {
    $biz_entity = entity_create('node', array('type' => 'business'));
    $biz_wrapper = entity_metadata_wrapper('node', $biz_entity);

    $biz_wrapper->field_biz_api_id_factual = $chosen_business['factual_id'];

    if (!empty($chosen_business['chain_id'])) {
      $biz_wrapper->field_biz_api_id_chain = $chosen_business['chain_id'];
    }
    if (!empty($chosen_business['name'])) {
      $biz_wrapper->title = $chosen_business['name'];
    }
    if (!empty($chosen_business['address'])) {
      $biz_wrapper->field_biz_address = $chosen_business['address'];
    }
    if (!empty($chosen_business['locality'])) {
      $biz_wrapper->field_biz_city = $chosen_business['locality'];
    }
    if (!empty($chosen_business['region'])) {
      $biz_wrapper->field_biz_state = $chosen_business['region'];
    }
    if (!empty($chosen_business['country'])) {
      $biz_wrapper->field_biz_country = $chosen_business['country'];
    }
    if (!empty($chosen_business['postcode'])) {
      $biz_wrapper->field_biz_zipcode = $chosen_business['postcode'];
    }
    if (!empty($chosen_business['tel'])) {
      $biz_wrapper->field_biz_phone = $chosen_business['tel'];
    }
    if (!empty($chosen_business['website'])) {
      $biz_wrapper->field_biz_website = $chosen_business['website'];
    }
    $biz_wrapper->save();
    return $biz_entity->nid;
  }
}

/**
 * Construct Ajax commands to update search results div with business search results.
 *
 * @return array
 *   Contains multiple Drupal Ajax 'insert/replaceWith' commands.
 */
function plantagripe_step2_search_business($form, $form_state) {
  $commands = array();

  if (empty($form_state['values']['biz_search_name'])) {
    $search_div_messages = '<div class="search_div_messages error">';
    $search_div_messages .= t('Please enter a business name to complete your search.');
    $search_div_messages .= '</div>';
    $commands[] = ajax_command_replace('.search_div_messages', $search_div_messages);
  }
  else {
    if ($form_state['values']['search_performed'] == '0') {
      $search_performed = $form['search_performed'];
      $search_performed['#value'] = '1';
      $commands[] = ajax_command_replace('input[name="search_performed"]', drupal_render($search_performed));
    }

    $default_search_div_msg = '<div class="search_div_messages" style="display:none"></div>';
    $commands[] = ajax_command_replace('.search_div_messages', $default_search_div_msg);

    $biz_search_results = $form['biz_search_results'];
    $biz_search_results['#markup'] = _populate_search_results_div($form_state);
    $commands[] = ajax_command_replace('#biz-search-results', drupal_render($biz_search_results));
  }

  return array('#type' => 'ajax', '#commands' => $commands);
}

/**
 * Generate HTML markup to update search results div with business search results.
 *
 * @return markup
 *   Updated HTML markup containing business search results.
 */
function _populate_search_results_div($form_state) {

  $search_results = _get_factual_search_results($form_state);
  $processed_results = gv_search_store_business_search_results($search_results);

  $results = array();
  foreach ($search_results as $item) {
    $biz_data = array();
    $biz_data['name'] = $item['name'];
    $biz_data['category'] = $item['category'];

    $bizAddress = '';
    if (!empty($item['address'])) {
      $bizAddress .= $item['address'];
    }
    if (!empty($item['locality'])) {
      $bizAddress .= ', ';
      $bizAddress .= $item['locality'];
    }
    if (!empty($item['region'])) {
      $bizAddress .= ', ';
      $bizAddress .= $item['region'];
    }
    if (!empty($item['postcode'])) {
      $bizAddress .= ', ';
      $bizAddress .= $item['postcode'];
    }
    if (!empty($item['country']) && $item['country'] == "us") {
      $bizAddress .= ', United States';
    }
    if (!empty($item['country']) && $item['country'] == "ca") {
      $bizAddress .= ', Canada';
    }
    $biz_data['address'] = $bizAddress;

    $factual_id = $item['factual_id'];
    $bizName = addslashes($item['name']);
    $bizAddress = addslashes($bizAddress);
    $jscipt_call = "selectFactualBiz('$factual_id', '$bizName', '$bizAddress');";

    $biz_data['select_me'] = '<a href="#" onClick="' . $jscipt_call . '">' . t('Select This Business') . '</a>';

    $result = theme('step2_listing_info', array('biz_data' => $biz_data));
    $results[] = $result;
  }
  $markup = theme('step2_search_listings', array('results' => $results));

  return $markup;
}

/**
 * Perform business search through Factual API.
 *
 * @return array
 *   Contains data on businesses obtained through Factual API search.
 */
function _get_factual_search_results($form_state) {
  $business_name = trim($form_state[values][biz_search_name]);
  $address = trim($form_state[values][biz_search_address]);
  $city = trim($form_state[values][biz_search_city]);

  $state_tid = $form_state[values][biz_search_state];
  $stateTermObject = taxonomy_term_load($state_tid);
  $state = $stateTermObject->description;

  $country_tid = $form_state[values][biz_search_country];
  $countryTermObject = taxonomy_term_load($country_tid);
  $country = $countryTermObject->description;

  return gv_factual_perform_business_search($business_name, $address, $city, $state, $country);
}

/**
 * Construct Ajax commands to update state-selection dropdown based on changes
 * to country-selection dropdown.
 *
 * @return array
 *   Contains multiple Drupal Ajax 'insert/replaceWith' commands.
 */
function plantagripe_step2_select_country($form, $form_state) {
  $commands = array();

  $biz_search_state = $form[biz_search_state];
  $commands[] = ajax_command_replace("div.form-item-biz-search-state", drupal_render($biz_search_state));

  $biz_manual_country = $form[biz_manual_country];
  $biz_manual_country['#value'] = $form[biz_search_country]['#value'];
  $commands[] = ajax_command_replace("div.form-item-biz-manual-country", drupal_render($biz_manual_country));

  $biz_manual_state = $form[biz_manual_state];
  $biz_manual_state['#options'] = $form[biz_search_state]['#options'];
  $biz_manual_state['#value'] = $form[biz_search_state]['#value'];
  $commands[] = ajax_command_replace("#biz-manual-state-dropdown", drupal_render($biz_manual_state));

  return array('#type' => 'ajax', '#commands' => $commands);
}

/**
 * Read data from smart_ip cookie to populate default value of business search
 * form with city nearest to the user.
 *
 * @return array
 *   Contains city, state and country data for user based on smart_ip cookie.
 */
function plantagripe_get_location_data() {
  $location_data = $_SESSION['smart_ip']['location'];

  $results = array();
  $results['city'] = $location_data['city'];
  $results['state'] = isset($location_data['administrative_area_level_1']) ? $location_data['administrative_area_level_1'] : $location_data['region'];
  $results['country'] = $location_data['country'];

  return $results;
}
