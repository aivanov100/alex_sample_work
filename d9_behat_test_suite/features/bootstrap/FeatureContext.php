<?php

namespace behat\features\bootstrap;

use behat\features\bootstrap\TestSuiteConstants as TSC;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\ResponseTextException;
use Drupal\Driver\Exception\UnsupportedDriverActionException;
use EntityInterface;
use function count;
use function mb_substr;
use function t;
use function user_load_by_mail;

/**
 * Defines application features from the specific context.
 */
class FeatureContext extends BaseContext
{
  /**
   * Parameters passed via the BEHAT_PARAMS_CUSTOM env variable.
   *
   * @var array
   */
  protected $behatParamsCustom;

  /**
   * Internal identifier for current testing env. e.g. LOCAL, DEV, STG.
   *
   * @var string
   */
  protected $environmentId;

  /**
   * The base URL of the site.
   *
   * @var string
   */
  protected $baseUrl;

  /**
   * The Selenium driver.
   *
   * @var \Behat\Mink\Driver\Selenium2Driver
   */
  protected $seleniumDriver;

  /**
   * Internal identifier for primary test user e.g. "TEST_USER_1".
   *
   * @var string
   */
  protected $primaryTestUserId;

  /**
   * Users created that are not to be deleted at the end of the test case.
   *
   * @var array
   */
  protected $noDeleteUsers;

  /**
   * A place to store any additional data that needs to be persistent.
   *
   * @var array
   */
  protected $data;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Mink Context.
   *
   * @var \Drupal\DrupalExtension\Context\MinkContext
   */
  protected $minkContext;

  /**
   * The Drupal Context.
   *
   * @var \Drupal\DrupalExtension\Context\DrupalContext
   */
  protected $drupalContext;

  /**
   * Initializes the FeatureContext.
   *
   * Every scenario gets its own context instance.
   * You can also pass arbitrary arguments to the
   * context constructor through behat.yml.
   */
  public function __construct()
  {
    $this->entityTypeManager = \Drupal::entityTypeManager();

    $this->environmentId = getenv('TARGET_ENV');
    $bpc = getenv('BEHAT_PARAMS_CUSTOM');
    $decoded_params = json_decode($bpc, TRUE);
    $this->behatParamsCustom = $decoded_params;
    $this->seleniumDriver = new Selenium2Driver('chrome');
    $this->seleniumDriver->setTimeouts(['implicit' => TestSuiteConstants::IMPLICIT_WAIT]);

    switch ($this->environmentId) {
      case 'LOCAL':
        $this->baseUrl = TestSuiteConstants::LOCAL_NATIVE_URL;
        break;
      case 'LANDO':
        $this->baseUrl = TestSuiteConstants::LOCAL_LANDO_URL;
        break;
      case 'GITLAB':
        $this->baseUrl = TestSuiteConstants::GITLAB_URL;
        break;
      case 'DEV':
        $this->baseUrl = TestSuiteConstants::DEV_SITE_URL;
        break;
      case 'STG':
        $this->baseUrl = TestSuiteConstants::STG_SITE_URL;
        break;
      case 'STBY':
        $this->baseUrl = TestSuiteConstants::STBY_SITE_URL;
        break;
      case 'PROD':
        $this->baseUrl = TestSuiteConstants::PROD_SITE_URL;
        break;
      case 'ECOM-PROD':
        $this->baseUrl = TestSuiteConstants::ECOM_PROD_URL;
        break;
      case 'CMS-PROD':
        $this->baseUrl = TestSuiteConstants::CMS_PROD_URL;
        break;
    }
  }

  /**
   * Create a user & add to User Manager if prefix is not "ATS-DND-" (Override).
   *
   * @return object
   *   The created user.
   */
  public function userCreate($user)
  {
    $this->dispatchHooks('BeforeUserCreateScope', $user);
    $this->parseEntityFields('user', $user);
    $this->getDriver()->userCreate($user);
    $this->dispatchHooks('AfterUserCreateScope', $user);

    $saved_user = user_load_by_mail($user->mail);
    $saved_user->set('field_first_name', $user->field_first_name);
    $saved_user->set('field_last_name', $user->field_last_name);
    $saved_user->set('app_company', NULL);
    $saved_user->save();

    $user_email = $saved_user->mail->value;
    $dnd_prefix = TestSuiteConstants::GLOBAL_PREFIX . TestSuiteConstants::GLOBAL_DND_PREFIX;
    $dnd_short_prefix = TestSuiteConstants::GLOBAL_DND_PREFIX;
    if (strpos($user_email, $dnd_prefix) !== 0 && strpos($user_email, $dnd_short_prefix) !== 0) {
      $this->userManager->addUser($user);
    } else {
      $this->noDeleteUsers[] = $user;
    }
    return $saved_user;
  }

  /**
   * Log into the site; different procedures for LOCAL Env Login vs SSO Login.
   *
   * @Given I am logged-in via SSO as :arg1
   */
  public function iAmLoggedInViaSsoAs($test_user_id)
  {
    $email = $this->behatParamsCustom['test_users'][$test_user_id . '_EMAIL'];
    $password = $this->behatParamsCustom['test_users'][$test_user_id . '_PASSWORD'];
    if ($user = user_load_by_mail($email)) {
      $uid = $user->id();
    }

    if ($this->environmentId === 'LOCAL' || $this->environmentId === 'LANDO' || $this->environmentId === 'GITLAB') {
      $this->performLocalLogin($email, $password, $uid);
    } else {
      $this->performSsoLogin($email, $password);
    }

    $this->primaryTestUserId = $test_user_id;
  }

  /**
   * View the existing vouchers for a specific course.
   *
   * @Given I view the existing vouchers for course :course
   */
  public function iViewTheExistingVouchersForCourse($product_id)
  {
    $modal_appeared = FALSE;
    $target_product = $this->getProductDetails($product_id);
    for ($i = 0; $i < 10; $i++) {
      if ($this->viewVouchersForSpecificCourse($target_product['VOUCHER_TITLE'])) {
        $modal_appeared = TRUE;
        break;
      }
    }
    if (!$modal_appeared) {
      throw new \Exception(sprintf("Behat Test Suite Error - unable to open View Vouchers modal for target course."));
    }
  }

  /**
   * Verify the data in the View Vouchers modal for a specific course.
   *
   * @Given I verify that the data in View Vouchers modal is correct for course :course
   */
  public function iVerifyThatTheDataInViewVouchersModalIsCorrectForCourse($course_id)
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORT);
    $course = $this->getProductDetails($course_id);
    $page = $this->getSession()->getPage();

    $voucher_info_div = $page->find('css', TestSuiteLocators::VV_MODAL_INFO_DIV);
    $voucher_info_text = $voucher_info_div->getText();
    if (strpos($voucher_info_text, $course['VOUCHER_TITLE']) === FALSE) {
      throw new \Exception(sprintf("View Voucher modal has incorrect data for course %s", $course['VOUCHER_TITLE']));
    }

    $verified = FALSE;
    $pos = strpos($voucher_info_text, 'Available Vouchers:');
    if ($pos !== FALSE) {
      $number_vouchers_available = $this->extractAmountOfAvailableVouchers($voucher_info_text);
      if ($number_vouchers_available > 0) {
        $this->data['initial_voucher_count'] = $number_vouchers_available;
        $verified = TRUE;
      }
    }
    if (!$verified) {
      throw new \Exception(sprintf("View Voucher modal has incorrect data for course %s", $course['VOUCHER_TITLE']));
    }
  }

  /**
   * Verify the data in Enroll Students modal for a specific course.
   *
   * @Given I verify that the data in Enroll Students modal is correct for course :course
   */
  public function iVerifyThatTheDataInEnrollStudentsModalIsCorrectForCourse($course_id)
  {
    $course = $this->getProductDetails($course_id);
    $page = $this->getSession()->getPage();
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);

    $voucher_info_divs = $page->findAll('css', TestSuiteLocators::ES_MODAL_INFO_DIV);
    foreach ($voucher_info_divs as $voucher_info_div) {
      $voucher_info_text = $voucher_info_div->getText();
      // Verify that the course title is displayed.
      if (strpos($voucher_info_text, 'Training/Course:') === 0) {
        if (strpos($voucher_info_text, $course['VOUCHER_TITLE']) === FALSE) {
          throw new \Exception(sprintf("Enroll Students Error - Enroll Student(s) modal shows incorrect data for course: %s", $course['VOUCHER_TITLE']));
        }
      } // Verify that there are vouchers available.
      elseif (strpos($voucher_info_text, 'Available Vouchers:') === 0) {
        $colon_pos = strpos($voucher_info_text, ':');
        $substring = substr($voucher_info_text, $colon_pos + 2);
        $number_vouchers_available = intval($substring);
        if ($number_vouchers_available <= 0) {
          throw new \Exception(sprintf("Enroll Students Error - Enroll Student(s) modal shows incorrect data for course: %s", $course['VOUCHER_TITLE']));
        }
      } else {
        throw new \Exception(sprintf("Enroll Students Error - Enroll Student(s) modal shows incorrect data for course: %s", $course['VOUCHER_TITLE']));
      }
    }

    // Verify that the Language dropdown is set to the correct value.
    $lang_dropdown_div = $page->find('css', TestSuiteLocators::ES_MODAL_LANG);
    $lang_dropdown = $lang_dropdown_div->find('css', 'select');
    $lang_selected_option = $lang_dropdown->find('css', "option");
    if ($lang_selected_option->getText() != $course['LANG']) {
      throw new \Exception(sprintf("Enroll Students Error - Enroll Student(s) modal has an incorrect value for the Language dropdown for course: %s", $course['VOUCHER_TITLE']));
    }

    // Verify that the Modality dropdown is set to the correct value.
    $modality_dropdown_div = $page->find('css', TestSuiteLocators::ES_MODAL_MODALITY);
    $modality_dropdown = $modality_dropdown_div->find('css', 'select');
    $mod_selected_option = $modality_dropdown->find('css', "option");
    if ($mod_selected_option->getText() != $course['MODALITY']) {
      throw new \Exception(sprintf("Enroll Students Error - Enroll Student(s) modal has an incorrect value for the Modality dropdown for course: %s", $course['VOUCHER_TITLE']));
    }
  }

  /**
   * Verify that the prerequisite course is displayed correctly.
   *
   * @Then I verify that the prereq course is displayed in the Enroll Students modal for course :course_id
   */
  public function iVerifyThatThePrereqCourseIsDisplayedInTheEnrollStudentsModalForCourse($course_id)
  {
    $course = $this->getProductDetails($course_id);
    $page = $this->getSession()->getPage();
    $this->waitSecondsUntilElementAppears(TestSuiteConstants::WAIT_TIME_MED, TestSuiteLocators::ESM_COURSE_LIST);

    $course_list_div = $page->find('css', TestSuiteLocators::ESM_COURSE_LIST);
    $prereq_course_link = $course_list_div->find('css', 'ul li a');
    if ($prereq_course_link->getText() !== $course['PRE_REQ']) {
      throw new \Exception(sprintf("Error - Enroll Student(s) modal does not correctly show the prerequisite for course: %s", $course['COURSE_TITLE']));
    }
  }

  /**
   * Verify that the interface for the Enroll Students form works correctly.
   *
   * @Given I verify that the Enroll Students form works correctly using the :op_code test user
   */
  public function iVerifyThatTheEnrollStudentsFormWorksCorrectlyUsingTheTestUser($op_code)
  {
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    $page = $this->getSession()->getPage();
    $enroll_students_modal = $page->find('css', TestSuiteLocators::ES_MODAL);
    $manual_student_form = $enroll_students_modal->find('css', '.manual-student-form');

    $this->verifyNumberOfRows($enroll_students_modal, $manual_student_form, 1, 0);
    $initial_empty_rows = $manual_student_form->findAll('css', '.MuiGrid-container .MuiGrid-container');
    $initial_empty_row = $initial_empty_rows[0];
    $manual_student_form->fillField('studentFirstName', $target_user->field_first_name);
    $manual_student_form->fillField('studentLastName', $target_user->field_last_name);
    $manual_student_form->fillField('studentEmail', $target_user->mail);
    $initial_empty_row->submit();

    sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
    $this->verifyNumberOfRows($enroll_students_modal, $manual_student_form, 1, 1);
    $filled_rows = $enroll_students_modal->findAll('css', '.CreatedStudentsList .MuiGrid-container');
    $filled_row = $filled_rows[0];
    $delete_button = $filled_row->find('css', '.delete-icon');
    $delete_button->press();

    sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
    $this->verifyNumberOfRows($enroll_students_modal, $manual_student_form, 1, 0);
    $manual_student_form->fillField('studentFirstName', $target_user->field_first_name);
    $manual_student_form->fillField('studentLastName', $target_user->field_last_name);
    $manual_student_form->fillField('studentEmail', $target_user->mail);

    // @todo Modify this code so as to effectively press the tab key.
    $this->getSession()->executeScript("
      jQuery('#studentEmail').focus();
      jQuery('#studentEmail').trigger(jQuery.Event('keydown', {which: 9, keyCode: 9}));
    ");
    // sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
    // $this->verifyNumberOfRows(
    // $enroll_students_modal, $manual_student_form, 1, 1
    // );.
  }

  /**
   * Assign a voucher to a specific user.
   *
   * @When I assign a voucher to :user_id
   */
  public function iAssignAvoucherTo($user_id)
  {
    $page = $this->getSession()->getPage();
    $enroll_students_modal = $page->find('css', TestSuiteLocators::ES_MODAL);
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);

    $target_user = $this->getSpecialUserDetails($user_id);
    $enroll_students_modal->fillField('studentFirstName', $target_user['FIRST_NAME']);
    $enroll_students_modal->fillField('studentLastName', $target_user['LAST_NAME']);
    $enroll_students_modal->fillField('studentEmail', $target_user['EMAIL']);

    $submit_button = $enroll_students_modal->findButton('Enroll Now');
    $submit_button->press();
  }

  /**
   * Verify that an Enrollment Success modal is displayed.
   *
   * @Then An Enrollment Success modal is displayed
   */
  public function anEnrollmentSuccessModalIsDisplayed()
  {
    $verified = FALSE;

    $this->spin(function ($context) {
      $success_boxes = $context->getSession()->getPage()->findAll('css', TestSuiteLocators::SUCCESS_BOX);
      if (count($success_boxes) > 0) {
        return TRUE;
      }
      return FALSE;
    });

    $success_box = $this->getSession()->getPage()->find('css', TestSuiteLocators::SUCCESS_BOX);
    if ($success_box) {
      $success_box_text = $success_box->getText();
      if (strpos($success_box_text, 'Success!') !== FALSE) {
        if (strpos($success_box_text, TestSuiteDisplayText::ENRL_SUCCESS_MSG1) !== FALSE) {
          if (strpos($success_box_text, TestSuiteDisplayText::ENRL_SUCCESS_MSG2) !== FALSE) {
            $verified = TRUE;
          }
        }
      }
    }
    if (!$verified) {
      throw new \Exception("Expected success message is not displayed after attempt to enroll a student.");
    }

    // Close the Success modal.
    $success_modal = $this->getSession()->getPage()->find('css', TestSuiteLocators::SUCCESS_MODAL);
    $success_modal_close = $success_modal->find('css', TestSuiteLocators::MODAL_CLOSE_BTN);
    $success_modal_close->click();
  }

  /**
   * Navigate to My Dashboard.
   *
   * @Given I navigate to My Dashboard
   */
  public function iNavigateToMyDashboard()
  {
    $this->spin(function ($context) {
      $this->getSession()->executeScript(TestSuiteJsFunctions::JS_CLICK_MY_IPC_EDGE);
      return true;
    });

    $target_url = $this->baseUrl . '/dashboard';
    $page_url = $this->getSession()->getCurrentUrl();
    if ($page_url !== $target_url) {
      $this->getSession()->visit($target_url);
      // @todo Create log entry - Clicking on link failed, had to navigate to the page directly.
    }

    $this->acceptInstructorAgreement();
    $this->completeMissingInformationForm();
  }

  /**
   * @When I click on My Dashboard Left Menu link :menu_link
   */
  public function iClickOnMyDashboardLeftMenuLink($menu_link)
  {
    switch ($menu_link) {
      case "Vouchers":
        $this->getSession()->executeScript(TestSuiteJsFunctions::JS_CLICK_MY_VOUCHERS);
        $target_url = $this->baseUrl . '/dashboard/my-vouchers';
        $page_url = $this->getSession()->getCurrentUrl();
        if ($page_url !== $target_url) {
          $this->getSession()->visit($target_url);
          // @todo Create log entry - Clicking on link failed, had to navigate to the page directly.
        }
        break;
    }
  }

  /**
   * Navigate to the IPC Custom Logs page.
   *
   * @Given I navigate to IPC Custom Logs
   */
  public function iNavigateToIpcCustomLogs()
  {
    $this->spin(function ($context) {
      $context->getSession()->getPage()->find('css', TestSuiteLocators::TOP_MENU_IPC_LINK)->mouseOver();
      sleep(1);
      $context->getSession()->getPage()->find('css', TestSuiteLocators::TM_IPC_CUSTOM_LOGS)->click();
      return true;
    });

    $target_url = $this->baseUrl . '/admin/reports/ipc-logs';
    $page_url = $this->getSession()->getCurrentUrl();
    if ($page_url !== $target_url) {
      $this->getSession()->visit($target_url);
      // @todo Create log entry - Clicking on link failed, had to navigate to the page directly.
    }
  }

  /**
   * Navigate to the Content page.
   *
   * @Given I navigate to Content page
   */
  public function iNavigateToContentPage()
  {
    $this->spin(function ($context) {
      $context->getSession()->getPage()->find('css', TestSuiteLocators::TM_CONTENT_LINK)->click();
      return true;
    });

    $target_url = $this->baseUrl . '/admin/content';
    $page_url = $this->getSession()->getCurrentUrl();
    if ($page_url !== $target_url) {
      $this->getSession()->visit($target_url);
      // @todo Create log entry - Clicking on link failed, had to navigate to the page directly.
    }
  }

  /**
   * Verify that a success message is displayed in the "Snackbar" region.
   *
   * @Then A success message is displayed in the SnackBar
   */
  public function aSuccessMessageIsDisplayedInTheSnackBar()
  {
    $page = $this->getSession()->getPage();
    $snackbar = $page->waitFor(20, function ($page) {
      return $page->findById('notistack-snackbar');
    });
    if ($snackbar->getText() !== 'Success') {
      throw new \Exception("Expected success message is not displayed after attempt to transfer a voucher.");
    }
  }

  /**
   * Create a Special User to be the object of a specific operation.
   *
   * @Given A special test user is created to be the object of the operation :op_code
   */
  public function aSpecialTestUserIsCreatedToBeTheObjectOfTheOperation($op_code)
  {
    $user = new \stdClass();
    $random_word = ucfirst($this->getRandom()->word(7));
    $user->name = TestSuiteConstants::GLOBAL_PREFIX . $op_code . '-' . $random_word . '@test.com';
    $user->mail = TestSuiteConstants::GLOBAL_PREFIX . $op_code . '-' . $random_word . '@test.com';
    $user->field_first_name = TestSuiteConstants::GLOBAL_PREFIX . $op_code;
    $user->field_last_name = $random_word;
    // Set a password.
    if (!isset($user->pass)) {
      $user->pass = $this->getRandom()->name(16);
    }
    // Create the user.
    $this->userCreate($user);
  }

  /**
   * Transfer a voucher to the user who is the object of a specific operation.
   *
   * @When I transfer a voucher to the user who is the object of the operation :op
   */
  public function iTransferAvoucherToTheUserWhoIsTheObjectOfThisOperation($op)
  {
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op);
    $page = $this->getSession()->getPage();
    $transfer_voucher_button = $page->findButton('Transfer Vouchers');
    $transfer_voucher_button->press();
    $transfer_voucher_modal = $page->find('css', '.transfer-vouchers-modal');
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);

    $transfer_voucher_modal->fillField('firstName', $target_user->field_first_name);
    $transfer_voucher_modal->fillField('lastName', $target_user->field_last_name);
    $transfer_voucher_modal->fillField('studentEmail', $target_user->mail);
    $transfer_voucher_modal->fillField('transfervoucherCount', '1');
    $submit_button = $transfer_voucher_modal->findButton('Submit');
    $submit_button->press();
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);

    $verified = FALSE;
    $warning_div = $transfer_voucher_modal->find('css', TestSuiteLocators::WARNING_DIV);
    $warning_div_text = $warning_div->getText();
    $expected_text_1 = 'You are about to transfer 1 voucher to ' . $target_user->field_first_name . ' ' . $target_user->field_last_name;
    if (strpos($warning_div_text, $expected_text_1) !== FALSE) {
      if (strpos($warning_div_text, TestSuiteDisplayText::VOUCHER_TRANSFER_WARNING) !== FALSE) {
        $verified = TRUE;
      }
    }
    if (!$verified) {
      throw new \Exception('The Voucher Transfer modal warning message does not match the expected text.');
    }

    $confirm_button = $transfer_voucher_modal->findButton('Transfer Vouchers');
    $confirm_button->press();
  }

  /**
   * I assign a voucher to the user who is the object of the operation.
   *
   * @When I assign a voucher to the user who is the object of the operation :op_code
   */
  public function iAssignAvoucherToTheUserWhoIsTheObjectOfTheOperation($op_code)
  {
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    $page = $this->getSession()->getPage();
    $enroll_students_modal = $page->find('css', TestSuiteLocators::ES_MODAL);

    $first_name_field = $enroll_students_modal->findById('studentFirstName');
    $first_name_field->setValue($target_user->field_first_name);

    // This clause is included because when filling the studentFirstName field (and only this field)
    // for the first time, the leading character is stripped seemingly for no reason
    $value = $first_name_field->getValue();
    $first_character = mb_substr($value, 0, 1);
    if ($first_character !== 'A') {
      $first_name_field->setValue($target_user->field_first_name);
    }

    $enroll_students_modal->fillField('studentLastName', $target_user->field_last_name);
    $enroll_students_modal->fillField('studentEmail', $target_user->mail);
    $this->getSession()->executeScript(TestSuiteJsFunctions::JS_CLICK_MODAL_BUTTON);
  }

  /**
   * I log in as the user who is the object of the operation being tested.
   *
   * @When I log in as the user who is the object of the operation :op_code
   */
  public function iLoginAsTheUserWhoIsTheObjectOfTheOperation($op_code)
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);

    $user_to_login = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    if ($user = user_load_by_mail($user_to_login->mail)) {
      $email = $user_to_login->mail;
      $password = $user_to_login->pass;
      $uid = $user->id();

      if ($this->environmentId === 'LOCAL' || $this->environmentId === 'LANDO' || $this->environmentId === 'GITLAB') {
        $this->performLocalLogin($email, $password, $uid);
      } else {
        $this->performSsoLogin($email, $password);
      }

      $this->userManager->setCurrentUser($user_to_login);
      $this->acceptAccessAgreement();
      $this->completeMissingInformationForm();
    }
  }

  /**
   * Accept the Access Agreement.
   *
   * @Given I accept the Access Agreement
   */
  public function iAcceptTheAccessAgreement()
  {
    $this->acceptAccessAgreement();
  }

  /**
   * Complete the Missing Information form.
   *
   * @Given I complete the Missing Information form
   */
  public function iCompleteTheMissingInformationForm()
  {
    $this->completeMissingInformationForm();
  }

  /**
   * Close the Vouchers modal.
   *
   * @When I close the Vouchers modal
   */
  public function iCloseTheVouchersModal()
  {
    $page = $this->getSession()->getPage();
    $oops_error_modals = $page->findAll('css', TestSuiteLocators::OOPS_MODAL);
    if ($oops_error_modals) {
      $oops_error_modal = $oops_error_modals[0];
      $oops_close_button = $oops_error_modal->find('css', '.modal-close-btn');
      $oops_close_button->click();
    }
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $modal_close_button = $page->find('css', TestSuiteLocators::MODAL_DEFAULT_CLOSE);
    if ($modal_close_button) {
      $modal_close_button->press();
    }
  }

  /**
   * I see the specified course on the "My Vouchers" page.
   *
   * @Then I see a voucher for course :internal_product_id
   */
  public function iSeeAvoucherForCourse($internal_product_id)
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);

    $target_product = $this->getProductDetails($internal_product_id);
    $page = $this->getSession()->getPage();
    $table = $page->find('css', '.UserVouchersList .rdt_Table .rdt_TableBody');
    $rows = $table->findAll('css', '.rdt_TableRow');

    $verified = FALSE;
    foreach ($rows as $row) {
      $cells = $row->findAll('css', '.rdt_TableCell');
      foreach ($cells as $cell) {
        $table_cell_text = $cell->getText();
        if ($table_cell_text === $target_product['VOUCHER_TITLE']) {
          $verified = TRUE;
          break;
        }
      }
    }
    if (!$verified) {
      throw new \Exception(sprintf("The expected transferred voucher for course %s does not appear under Vouchers.", $target_product['VOUCHER_TITLE']));
    }
  }

  /**
   * Verify that a course shows in the main table on the active Dashboard page.
   *
   * @Then I see the course :internal_product_id in the main table on the My Courses page
   */
  public function iSeeTheCourseInTheMainTableOnTheActiveDashboardPage($internal_product_id)
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    //$this->waitSecondsUntilElementAppears(TestSuiteConstants::WAIT_TIME_MED, TestSuiteLocators::DP_MY_MEMBER_COURSES);

    $target_course = $this->getProductDetails($internal_product_id);
    $shown_in_my_member_courses = $this->verifyCourseShownInTable($target_course, TestSuiteLocators::DP_MY_MEMBER_COURSES_ID);

    if (!$shown_in_my_member_courses) { //&& !$shown_in_my_training_courses) {
      throw new \Exception(sprintf("The expected transferred voucher for course %s does not appear under Vouchers.", $target_course['VOUCHER_TITLE']));
    }
  }

  /**
   * Verify that a listing for a specific course exists in the specified table.
   *
   * @param array $target_course
   *   The target course for which to search in a table.
   * @param string $table_locator_id
   *   The ID of the table element on the page.
   */
  protected function verifyCourseShownInTable(array $target_course, $table_locator_id)
  {
    $page = $this->getSession()->getPage();
    $table = $page->findById($table_locator_id);
    $rows = $table->findAll('css', '.rdt_TableRow');

    $verified = FALSE;
    foreach ($rows as $row) {
      $cells = $row->findAll('css', '.rdt_TableCell');
      foreach ($cells as $cell) {
        $table_cell_text = $cell->getText();
        if ($table_cell_text === $target_course['COURSE_LIST_TITLE']) {
          $verified = TRUE;
        }
      }
    }
    return $verified;
  }

  /**
   * I am able to start the specified course.
   *
   * @Then I am able to start the course :internal_product_id
   */
  public function iAmAbleToStartTheCourse($internal_product_id)
  {
    $target_product = $this->getProductDetails($internal_product_id);
    $page = $this->getSession()->getPage();
    $tables = $page->findAll('css', TestSuiteLocators::DASH_PAGE_MAIN_TABLE);

    foreach ($tables as $table) {
      $rows = $table->findAll('css', '.rdt_TableRow');
      foreach ($rows as $row) {
        $cells = $row->findAll('css', '.rdt_TableCell');
        foreach ($cells as $cell) {
          $table_cell_text = $cell->getText();
          if ($table_cell_text === $target_product['COURSE_LIST_TITLE']) {
            $start_button = $row->find('css', '.start-class-btn');
            $start_button->press();
            // @todo confirm that the correct result is achieved after pressing Start button.
          }
        }
      }
    }
  }

  /**
   * Create temporary test users to store in the CSV file.
   *
   * @Given Special test users, :number in total, are created and saved to CSV file :filename
   */
  public function specialTestUsersInTotalAreCreatedAndSavedToCsvFile(int $number, string $filename)
  {
    $op_code = 'DND-CSV';
    $file_path = $this->getMinkParameter('files_path');
    $fp = fopen($file_path . '/' . $filename, 'w');
    $headings = ['email', 'first name', 'last name'];
    fputcsv($fp, $headings);

    for ($i = 0; $i < $number; $i++) {
      $user = new \stdClass();
      $random_word = ucfirst($this->getRandom()->word(7));
      $user->name = TestSuiteConstants::GLOBAL_PREFIX . $op_code . '_' . $random_word . '@test.com';
      $user->mail = TestSuiteConstants::GLOBAL_PREFIX . $op_code . '_' . $random_word . '@test.com';
      $user->field_first_name = TestSuiteConstants::GLOBAL_PREFIX . $op_code;
      $user->field_last_name = $random_word;
      // Set a password.
      if (!isset($user->pass)) {
        $user->pass = $this->getRandom()->name(16);
      }
      // Create the user.
      $this->userCreate($user);

      $values = [
        $user->mail,
        $user->field_first_name,
        $user->field_last_name,
      ];
      fputcsv($fp, $values);
    }
    fclose($fp);
  }

  /**
   * Click on a tab with the specified text.
   *
   * @Given /^I click on "([^"]*)" tab$/
   */
  public function iClickOnTab(string $tab_text)
  {
    $page = $this->getSession()->getPage();
    $tab = $page->findById(TestSuiteLocators::ESM_UPLOAD_CSV_ID);
    if ($tab->getText() === $tab_text) {
      $tab->click();
    }
  }

  /**
   * Verify that all the expected sections appear on the Upload CSV form.
   *
   * @Then I verify the Upload CSV form
   */
  public function iVerifyTheUploadCsvForm()
  {
    $instructions_field_verified = FALSE;
    $select_file_field_verified = FALSE;
    $enroll_button_verified = FALSE;

    $page = $this->getSession()->getPage();
    $upload_csv_form = $page->find('css', TestSuiteLocators::ESM_UPLOAD_CSV_FORM);
    $steps = $upload_csv_form->findAll('css', TestSuiteLocators::ESM_UPLOAD_CSV_STEP);
    foreach ($steps as $step) {
      $step_text = $step->getText();
      $step_header = $step->find('css', TestSuiteLocators::ESM_UPLOAD_CSV_STEP_HEADER);
      $header_text = $step_header->getText();
      if ($header_text === 'Instructions') {
        if (strpos($step_text, TestSuiteDisplayText::ESM_INSTRUCTIONS_STEP_TEXT1) !== FALSE) {
          if (strpos($step_text, TestSuiteDisplayText::ESM_INSTRUCTIONS_STEP_TEXT2) !== FALSE) {
            $instructions_field_verified = TRUE;
          }
        }
      } elseif ($header_text === 'Select File') {
        $file_field_region = $step->find('css', TestSuiteLocators::ESM_SELECT_FILE_REGION);
        $csv_input_fields = $file_field_region->findAll('named',
          ['file', TestSuiteLocators::ESM_SELECT_FILE_CSV_INPUT_ID]);
        if (count($csv_input_fields) > 0) {
          $select_file_field_verified = TRUE;
        }
      }
    }

    // @todo Uncomment the code below after fix is made (DG-1345)
    /*$enroll_now_btn_disabled = $page->find(
    'css', TestSuiteLocators::ESM_ENROLL_NOW_BTN_DIS);
    if ($enroll_now_btn_disabled) {
    $enroll_button_verified = TRUE;
    }*/

    // Delete this line once the code above is uncommented.
    $enroll_button_verified = TRUE;

    if (!$instructions_field_verified || !$select_file_field_verified || !$enroll_button_verified) {
      throw new \Exception(sprintf("Error Upon Verification of CSV Upload Form - expected form fields not displayed."));
    }
  }

  /**
   * Verify that all users from the CSV file appear under "Added Students".
   *
   * @Then I verify that the :quantity CSV file users appear under Added Students
   */
  public function iVerifyThatTheCsvFileUsersAppearUnderAddedStudents($quantity)
  {
    $csv_user_emails = [];
    $file_path = $this->getMinkParameter('files_path');
    $filename = 'ATS_created_users.csv';

    $handle = fopen($file_path . '/' . $filename, 'r');
    if ($handle) {
      while (($data = fgetcsv($handle, 1000)) !== FALSE) {
        $email = $data[0];
        if ($email != 'email') {
          $csv_user_emails[] = $email;
        }
      }
    } else {
      throw new \Exception(sprintf("Error Upon Verification of CSV Upload Form - unable to open the CSV file."));
    }
    fclose($handle);

    sleep(TestSuiteConstants::WAIT_TIME_SHORT);
    $page = $this->getSession()->getPage();
    $upload_csv_form = $page->find('css', TestSuiteLocators::ESM_UPLOAD_CSV_FORM);
    $added_students_table = $upload_csv_form->find('css', TestSuiteLocators::ESM_CSV_ADDED_STUDENTS);
    $rows = $added_students_table->findAll('css', TestSuiteLocators::REACT_TABLE_ROW);

    foreach ($rows as $row) {
      $row_text = $row->getText();
      foreach ($csv_user_emails as $csv_user_email) {
        if (strlen($row_text) && strpos($row_text, $csv_user_email) !== FALSE) {
          if (($key = array_search($csv_user_email, $csv_user_emails)) !== FALSE) {
            unset($csv_user_emails[$key]);
            break;
          }
        }
      }
    }

    if (count($csv_user_emails) !== 0) {
      throw new \Exception(sprintf("Error Upon Verification of CSV Upload Form - not all students from the CSV file appear in the Added Students table."));
    }
  }

  /**
   * Verify that the voucher is correctly assigned to the target user.
   *
   * @Then I verify that the voucher is assigned to the :op_code special test user
   */
  public function iVerifyThatTheVoucherIsAssignedToTheSpecialTestUser($op_code)
  {
    $page = $this->getSession()->getPage();

    $voucher_info_div = $page->find('css', TestSuiteLocators::VV_MODAL_INFO_DIV);
    $voucher_info_text = $voucher_info_div->getText();
    $pos = strpos($voucher_info_text, 'Available Vouchers:');
    if ($pos === FALSE) {
      throw new \Exception(sprintf("Enroll Students Error - View Voucher modal does not show Available Voucher count after enrollment."));
    }
    $number_vouchers_avail = $this->extractAmountOfAvailableVouchers($voucher_info_text);
    $target_number_vouchers_avail = $this->data['initial_voucher_count'] - 1;
    if ($number_vouchers_avail !== $target_number_vouchers_avail) {
      throw new \Exception(sprintf("Enroll Students Error - View Voucher modal does not show correct Available Voucher count after enrollment."));
    }

    $table = $page->find('css', TestSuiteLocators::VV_MODAL_STUDENTS_TABLE);
    $rows = $table->findAll('css', '.rdt_TableRow');
    $target_row_found = FALSE;
    $user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    $user_email = $user->mail;
    foreach ($rows as $row) {
      if ($row->hasLink($user_email)) {
        $target_row_found = TRUE;
        break;
      }
    }
    if (!$target_row_found) {
      throw new \Exception(sprintf("Enroll Students Error - User %s was not successfully enrolled in the target course", $user_email));
    }
  }

  /**
   * Verify that the specified user has been removed from the specified course.
   *
   * @Then The :op_code special test user has been removed from course :course_id
   */
  public function theSpecialTestUserHasBeenRemovedFromCourse($op_code, $course_id)
  {
    $page = $this->getSession()->getPage();

    $voucher_info_div = $page->find('css', TestSuiteLocators::VV_MODAL_INFO_DIV);
    $voucher_info_text = $voucher_info_div->getText();
    $pos = strpos($voucher_info_text, 'Available Vouchers:');
    if ($pos === FALSE) {
      throw new \Exception(sprintf("Enroll Students Error - View Voucher modal does not show Available Voucher count after unenrollment."));
    }
    $number_vouchers_avail = $this->extractAmountOfAvailableVouchers($voucher_info_text);
    $target_number_vouchers_avail = $this->data['initial_voucher_count'];
    if ($number_vouchers_avail !== $target_number_vouchers_avail) {
      throw new \Exception(sprintf("Enroll Students Error - View Voucher modal does not show correct Available Voucher count after unenrollment."));
    }

    $table = $page->find('css', TestSuiteLocators::VV_MODAL_STUDENTS_TABLE);
    $rows = $table->findAll('css', '.rdt_TableRow');
    $user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    $user_email = $user->mail;
    foreach ($rows as $row) {
      if ($row->hasLink($user_email)) {
        throw new \Exception(sprintf("Edit Voucher Error - User %s was not successfully unenrolled from the target course", $user_email));
      }
    }
  }

  /**
   * Delete the voucher assignment for the target user.
   *
   * @Given I delete the voucher assignment for the :op_code special test user
   */
  public function iDeleteTheVoucherAssignmentForTheSpecialTestUser($op_code)
  {
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::VV_MODAL_STUDENTS_TABLE);
    $rows = $table->findAll('css', '.rdt_TableRow');

    $target_row_found = FALSE;
    foreach ($rows as $row) {
      $user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
      $user_email = $user->mail;

      if ($row->hasLink($user_email)) {
        $target_row_found = TRUE;
        $actions_btn = $row->findButton('Actions');
        $actions_btn->press();
        sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
        $delete_link = $page->find('css', TestSuiteLocators::VV_ACTIONS_DELETE);
        $delete_link->click();

        $dialog = $page->find('css', TestSuiteLocators::VV_REMOVE_CONFIRM);
        $buttons = $dialog->findAll('css', 'button');
        foreach ($buttons as $button) {
          $button_text = $button->getText();
          if ($button_text === 'OK') {
            $button->press();
            break;
          }
        }
      }
    }
    if (!$target_row_found) {
      throw new \Exception(sprintf("Enroll Students Error - User %s was not successfully enrolled in the target course", $user_email));
    }
  }

  /**
   * Verify that the success message is correctly displayed.
   *
   * @Then A success message is displayed in the SnackBar for operation :op_code
   */
  public function aSuccessMessageIsDisplayedInTheSnackbarForOperation($op_code)
  {
    $snackbar = NULL;
    for ($i = 0; $snackbar === NULL; $i++) {
      sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
      $snackbar = $this->getSession()->getPage()->findById('notistack-snackbar');
      if ($i > 60) {
        throw new \Exception("Snackbar container that is supposed to display the success message is not displayed after attempt to delete a voucher.");
      }
    }

    $target_op_codes = ['VREM', 'DND-VREM'];
    if (in_array($op_code, $target_op_codes)) {
      $expected_msg = TestSuiteDisplayText::USER_UNENROLL_SUCCESS_MSG;
      $snack_text = $snackbar->getText();
      if ($snack_text !== $expected_msg) {
        throw new \Exception("Expected success message is not displayed after attempt to delete a voucher.");
      }
    }
  }

  /**
   * Verify that the Edit Students modal shows the correct data.
   *
   * @Then I verify the Edit Students modal for course :course_id and :op_code special test user
   */
  public function iVerifyTheEditStudentsModalForCourseAndSpecialTestUser($course_id, $op_code)
  {
    $course = $this->getProductDetails($course_id);
    $page = $this->getSession()->getPage();
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $table = $page->find('css', TestSuiteLocators::VV_MODAL_STUDENTS_TABLE);
    $rows = $table->findAll('css', '.rdt_TableRow');

    // Find the target row associated with our special test user.
    $target_row_found = FALSE;
    foreach ($rows as $row) {
      $user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
      $user_email = $user->mail;

      if ($row->hasLink($user_email)) {
        $target_row_found = TRUE;
        $actions_btn = $row->findButton('Actions');
        $actions_btn->press();
        $edit_link = $page->find('css', TestSuiteLocators::VV_ACTIONS_EDIT);
        $edit_link->click();
      }
    }
    if (!$target_row_found) {
      throw new \Exception(sprintf("Enroll Students Error - User %s was not successfully enrolled in the target course", $user_email));
    }

    $voucher_info_divs = $page->findAll('css', TestSuiteLocators::ES_MODAL_INFO_DIV);
    foreach ($voucher_info_divs as $voucher_info_div) {
      $voucher_info_text = $voucher_info_div->getText();
      // Verify that the course title is displayed.
      if (strpos($voucher_info_text, 'Training/Course:') === 0) {
        if (strpos($voucher_info_text, $course['VOUCHER_TITLE']) === FALSE) {
          throw new \Exception(sprintf("Enroll Students Error - Enroll Student(s) modal shows incorrect data for course: %s", $course['VOUCHER_TITLE']));
        }
      }
    }

    // Verify that the Language dropdown is set to the correct value.
    $lang_dropdown_div = $page->find('css', TestSuiteLocators::ES_MODAL_LANG);
    $lang_dropdown = $lang_dropdown_div->find('css', 'select');
    $lang_selected_option = $lang_dropdown->find('css', "option");
    if ($lang_selected_option->getText() != $course['LANG']) {
      throw new \Exception(sprintf("Enroll Students Error - Enroll Student(s) modal has an incorrect value for the Language dropdown for course: %s", $course['VOUCHER_TITLE']));
    }

    // Verify that the Modality dropdown is set to the correct value.
    $modality_dropdown_div = $page->find('css', TestSuiteLocators::ES_MODAL_MODALITY);
    $modality_dropdown = $modality_dropdown_div->find('css', 'select');
    $mod_selected_option = $modality_dropdown->find('css', "option");
    if ($mod_selected_option->getText() != $course['MODALITY']) {
      throw new \Exception(sprintf("Enroll Students Error - Enroll Student(s) modal has an incorrect value for the Modality dropdown for course: %s", $course['VOUCHER_TITLE']));
    }
  }

  /**
   * Navigate to the Edit User form for the specified test user.
   *
   * @Given I navigate to the Edit User form for the :op_code test user
   */
  public function iNavigateToTheUserEditFormForTheTestUser($op_code)
  {
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    if (!$this->assertCurrentlyOnEditUserPage($target_user->mail)) {
      $this->spin(function ($context) {
        $context->getSession()->getPage()->find('css', TestSuiteLocators::TM_PEOPLE_LINK)->click();
        return true;
      });
      $target_url = $this->baseUrl . '/admin/people';
      $page_url = $this->getSession()->getCurrentUrl();
      if ($page_url !== $target_url) {
        $this->getSession()->visit($target_url);
        // @todo Create log entry - Clicking on link failed, had to navigate to the page directly.
      }

      sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
      $page = $this->getSession()->getPage();
      $table = $page->find('css', TestSuiteLocators::ADMIN_PEOPLE_TABLE);
      $first_name = $target_user->field_first_name;
      $last_name = $target_user->field_last_name;
      $link_text = "$first_name $last_name";
      $this->selectPersonFromAdminPeopleTable($table, $link_text);

      $page_url = $this->getSession()->getCurrentUrl();
      $target_url = $page_url . '/edit';
      $this->getSession()->visit($target_url);
    }
  }

  /**
   * Update the name fields of the given test user.
   *
   * @When I update the name of the :op_code test user
   */
  public function iUpdateTheNameOfTheUserWhoIsTheObjectOfTheOperation($op_code)
  {
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    $orig_first_name = $target_user->field_first_name;
    $orig_last_name = $target_user->field_last_name;
    $first_name = $orig_first_name . TestSuiteConstants::MOD_SUFFIX;
    $last_name = $orig_last_name . TestSuiteConstants::MOD_SUFFIX;

    $user_edit_page = $this->getSession()->getPage();
    $user_form = $user_edit_page->findById('user-form');
    $user_form->fillField('edit-field-first-name-0-value', $first_name);
    $user_form->fillField('edit-field-last-name-0-value', $last_name);
    $this->editUserFillInPrimaryAddress($user_form, $target_user, 'TEST_COMPANY_1');

    $user_form->findButton('edit-submit')->click();
    $this->verifyStatusMessageDisplayed(TestSuiteDisplayText::CHANGES_SAVED_MSG);
  }

  /**
   * Assign the given role to the specified test user.
   *
   * @When I assign the role :desired_role to the :op_code test user
   */
  public function iAssignTheRoleToTheTestUser($desired_role, $op_code)
  {
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    if (!$this->assertCurrentlyOnEditUserPage($target_user->mail)) {
      $this->iNavigateToTheUserEditFormForTheTestUser($op_code);
    }

    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $user_edit_page = $this->getSession()->getPage();
    $user_form = $user_edit_page->findById('user-form');
    if ($desired_role === "IPC Service Agent") {
      $ipc_service_agent_cb = $user_form->findById('edit-roles-ipc-service-agent');
      if (!$ipc_service_agent_cb->isChecked()) {
        $ipc_service_agent_cb->check();
      }
    }

    $this->editUserFillInPrimaryAddress($user_form, $target_user, 'TEST_COMPANY_1');
    $user_form->findButton('edit-submit')->click();
    $this->verifyStatusMessageDisplayed(TestSuiteDisplayText::CHANGES_SAVED_MSG);
  }

  /**
   * Verify that the given field was updated for the given test user.
   *
   * @Then I verify that the field :field_name was updated for the :op_code test user
   */
  public function iVerifyThatTheFieldWasUpdatedForTheTestUser($field_name, $op_code)
  {
    $verified = FALSE;
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::ADM_IPC_LOGS_TABLE);
    $rows = $table->findAll('css', 'tbody tr');
    $old_value = $target_user->$field_name;
    $new_value = $old_value . TestSuiteConstants::MOD_SUFFIX;
    foreach ($rows as $row) {
      if ($this->verifySpecificFieldChangedInLogs($row, $field_name, $old_value, $new_value)) {
        $verified = TRUE;
        break;
      }
    }
    if (!$verified) {
      throw new \Exception(sprintf("Test Suite Error - Modify User Operation - Failed verification of IPC Logs for user with email: %s", $target_user->mail));
    }
  }

  /**
   * Verify that the given test user has the specified role.
   *
   * @Given I verify that the :op_code test user has the role :role
   */
  public function iVerifyThatTheTestUserHasTheRole($op_code, $role)
  {
    $this->spin(function ($context) {
      $context->getSession()->getPage()->find('css', TestSuiteLocators::TM_PEOPLE_LINK)->click();
      return true;
    });
    $target_url = $this->baseUrl . '/admin/people';
    $page_url = $this->getSession()->getCurrentUrl();
    if ($page_url !== $target_url) {
      $this->getSession()->visit($target_url);
      // @todo Create log entry - Clicking on link failed, had to navigate to the page directly.
    }

    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::ADMIN_PEOPLE_TABLE);
    $link_text = "$target_user->field_first_name $target_user->field_last_name";
    if (!$this->confirmUserHasRole($table, $link_text, $role)) {
      throw new \Exception(sprintf("Test Suite Error - Failed verification of modified Roles for user with email: %s", $target_user->mail));
    }
  }

  /**
   * Filter the displayed content by the given Content Type.
   *
   * @Given I filter the displayed content by Content Type :content_type
   */
  public function iFilterTheDisplayedContentByContentType($content_type)
  {
    $views_form = $this->getSession()->getPage()->findById('views-exposed-form-content-page-1');
    $views_form->selectFieldOption('edit-type', $content_type);
    $views_form->submit();
  }

  /**
   * Click Edit button for first result in the Views table with specific name.
   *
   * @Given I click to edit the result in the Views table with the name :const_name
   */
  public function iClickToEditTheFirstResultInTheViewsTableWithSpecifiedName($const_name)
  {
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::VIEWS_VIEW_TABLE);
    $rows = $table->findAll('css', TestSuiteLocators::VIEWS_VIEW_ROW);

    $row_found = FALSE;
    foreach ($rows as $row) {
      $cells = $row->findAll('css', 'td');
      foreach ($cells as $cell) {
        $table_cell_text = $cell->getText();
        $course_title = constant("TestSuiteConstants::$const_name");
        if ($table_cell_text == $course_title) {
          $row_found = TRUE;
        } elseif ($row_found && $table_cell_text === 'Edit') {
          $link = $cell->findLink('Edit');
          $link->click();
          break 2;
        }
      }
    }
  }

  /**
   * Click the Edit button for the first result in the Views table.
   *
   * @Given I click to edit the first result in the Views table
   */
  public function iClickToEditTheFirstResultInTheViewsTable()
  {
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::VIEWS_VIEW_TABLE);
    $rows = $table->findAll('css', TestSuiteLocators::VIEWS_VIEW_ROW);

    foreach ($rows as $row) {
      $cells = $row->findAll('css', 'td');
      foreach ($cells as $cell) {
        $table_cell_text = $cell->getText();
        if ($table_cell_text === 'Edit') {
          $link = $cell->findLink('Edit');
          $link->click();
          break 2;
        }
      }
    }
  }

  /**
   * Add primary test case user as an instructor for the course being viewed.
   *
   * @Given I add the primary test case user as an instructor
   */
  public function iAddThePrimaryTestCaseUserAsAnInstructor()
  {
    $page = $this->getSession()->getPage();
    $users = $this->noDeleteUsers;
    foreach ($users as $user) {
      if (strpos($user->mail, TestSuiteConstants::GLOBAL_PREFIX) !== 0) {
        $form = $page->findById('node-course-group-class-edit-form');
        $table_instructor_values = $form->findById('field-instructor-values');

        $instructor_inputs = $table_instructor_values->findAll('css', '.js-form-type-entity-autocomplete input');
        foreach ($instructor_inputs as $input_field) {
          if ($input_field->getValue() === '') {
            $input_field->setValue($user->mail);
            $input_field->focus();

            $xpath = $input_field->getXpath();
            $driver = $this->getSession()->getDriver();
            $driver->keyDown($xpath, 40);

            sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
            $available_autocompletes = $this->getSession()->getPage()->findAll('css', 'ul.ui-autocomplete[id^=ui-id]');
            if (empty($available_autocompletes)) {
              throw new \Exception(t('Could not find the autocomplete popup box'));
            }

            // It's possible for multiple autocompletes to be on the page, but
            // it shouldn't be possible for multiple to be visible/open at once.
            foreach ($available_autocompletes as $autocomplete) {
              if ($autocomplete->isVisible()) {
                $matched_element = $autocomplete->find('css', "a");
                if (!empty($matched_element)) {
                  $matched_element->click();
                  break;
                }
              }
            }
            $form->submit();
          }
        }
      }
    }
  }

  /**
   * Creates and authenticates an IPC user with the given role(s).
   *
   * @Given I am logged into the IPC site as a/an :role
   */
  public function assertIpcAuthenticatedByRole($role)
  {
    if (!$this->loggedInWithRole($role)) {
      $dnd_short_prefix = TestSuiteConstants::GLOBAL_DND_PREFIX;
      $random_word = ucfirst($this->getRandom()->word(7));
      $user = (object)[
        'pass' => $this->getRandom()->name(16),
        'role' => $role,
        'mail' => $dnd_short_prefix . $random_word . '@test.com',
        'name' => $dnd_short_prefix . $random_word . '@test.com',
        'field_first_name' => $role,
        'field_last_name' => $random_word,
      ];
      $this->userCreate($user);

      $roles = explode(',', $role);
      $roles = array_map('trim', $roles);
      foreach ($roles as $role) {
        if (!in_array(strtolower($role), ['authenticated', 'authenticated user'])) {
          // Only add roles other than 'authenticated user'.
          $this->getDriver()->userAddRole($user, $role);
        }
      }
      // Login.
      $this->login($user);
    }
  }

  /**
   * Verify that the Instructor field was set to the primary test user's email.
   *
   * @Given I verify that field instructor was set to primary test user email for course :course_id
   */
  public function iVerifyThatFieldInstructorWasSetToPrimaryTestUserEmail($course_id)
  {
    $users = $this->noDeleteUsers;

    foreach ($users as $user) {
      $dnd_short_prefix = TestSuiteConstants::GLOBAL_DND_PREFIX;
      if (strpos($user->mail, $dnd_short_prefix) === 0) {
        $page = $this->getSession()->getPage();
        $table = $page->find('css', TestSuiteLocators::ADM_IPC_LOGS_TABLE);
        $rows = $table->findAll('css', 'tbody tr');

        $verified = FALSE;
        foreach ($rows as $row) {
          $field_changed = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_CHANGED)->getText();
          if ($field_changed === 'field_instructor') {
            $new_value = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_NEW_VAL)->getText();
            $expected_new_value = $user->mail;

            // @todo verify that "CONTEXT OF UPDATED FIELD" matches our course name
            if ($new_value === $expected_new_value) {
              $verified = TRUE;
              break;
            }
          }
        }
        if (!$verified) {
          throw new \Exception("IPC Logs - Log entry does not correctly show changes for the field_instructor for target user $user->mail.");
        }
      }
    }
  }

  /**
   * Navigate to the Edit Product page for the given Product.
   *
   * @Given I navigate to the Edit Product page for :internal_product_id
   */
  public function iNavigateToTheEditProductPageFor($internal_product_id)
  {
    $page = $this->getSession()->getPage();
    $target_product = $this->getProductDetails($internal_product_id);
    $product_path = $target_product['PATH'];
    $this->getSession()->visit($this->locatePath($product_path));
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);

    $block_ipc_tabs = $page->findById('block-ipc-tabs');
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $tab_links = $block_ipc_tabs->findAll('css', 'ul li a');
    foreach ($tab_links as $tab_link) {
      $text = $tab_link->getText();
      if ($text === 'EDIT') {
        $tab_link->click();
        break;
      }
    }
  }

  /**
   * Locates url, based on provided path.
   * Overriden to provide custom routing mechanism.
   *
   * @param string $path
   *
   * @return string
   */
  public function locatePath($path)
  {
    $startUrl = rtrim($this->baseUrl, '/') . '/';
    return 0 !== strpos($path, 'http') ? $startUrl . ltrim($path, '/') : $path;
  }

  /**
   * Enable the given checkbox field for the Product being viewed.
   *
   * @Given I enable :field_label for the Product
   */
  public function iEditProductCheckboxFieldEnable($field_label)
  {
    $page = $this->getSession()->getPage();
    if ($field_label === 'Instructor Required (CIT, MIT)') {
      $this->spin(function ($context) {
        $context->getSession()->getPage()->checkField('edit-field-designation-required-value');
        return true;
      });
    }
    $this->spin(function ($context) {
      $context->getSession()->getPage()->pressButton('edit-actions-submit');
      return true;
    });
  }

  /**
   * Disable the given checkbox field for a Product.
   *
   * @Given I disable :field_label for the Product
   */
  public function iEditProductCheckboxFieldDisable($field_label)
  {
    $page = $this->getSession()->getPage();
    $edit_product_form = $page->findById('commerce-product-course-edit-form');
    if ($field_label === 'Instructor Required (CIT, MIT)') {
      $checkbox = $edit_product_form->findById('edit-field-designation-required-value');
      if ($checkbox->isChecked()) {
        $checkbox->uncheck();
      }
    }
    $edit_product_form->submit();
  }

  /**
   * Verify that the given field was updated to the new value.
   *
   * @Then I verify that the field :field_name was updated to :new_value for :product_internal_id
   */
  public function iVerifyThatTheFieldWasUpdatedFor($field_name, $new_value, $product_internal_id)
  {
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::ADM_IPC_LOGS_TABLE);
    $rows = $table->findAll('css', 'tbody tr');

    $verified = FALSE;
    foreach ($rows as $row) {
      $field_changed = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_CHANGED)->getText();
      if ($field_changed === $field_name) {
        $new_value_displayed = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_NEW_VAL)->getText();
        // @todo verify that "CONTEXT OF UPDATED FIELD" matches our course name
        if ($new_value_displayed === $new_value) {
          $verified = TRUE;
          break;
        }
      }
    }
    if (!$verified) {
      throw new \Exception("IPC Logs - Log entry does not correctly show changes for the field $field_name being updated to \"$new_value\" for $product_internal_id");
    }
  }

  /**
   * Verify that the given field was updated to reference the specified user.
   *
   * @Given I verify that the field :field_name was updated to reference the :op_code test user
   */
  public function iVerifyThatTheFieldWasUpdatedToReferenceTheTestUser($field_name, $op_code)
  {
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::ADM_IPC_LOGS_TABLE);
    $rows = $table->findAll('css', 'tbody tr');
    $object_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);

    $verified = FALSE;
    foreach ($rows as $row) {
      $field_changed = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_CHANGED)->getText();
      if ($field_changed === $field_name) {
        $new_value_displayed = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_NEW_VAL)->getText();
        if ($new_value_displayed === $object_user->mail) {
          // @todo verify that "CONTEXT OF UPDATED FIELD" matches our course name
          $verified = TRUE;
          break;
        }
      }
    }
    if (!$verified) {
      throw new \Exception("IPC Logs - Log entry does not correctly show changes for the field $field_name being updated for $op_code test user");
    }
  }

  /**
   * Navigate to the Edit Product Variation page for the specified product.
   *
   * @Given I navigate to the Edit Variation for the first variation of :product_internal_id
   */
  public function iNavigateToTheEditVariationForTheFirstVariationOf($product_internal_id)
  {
    $page = $this->getSession()->getPage();
    $block_local_tasks = $page->find('css', TestSuiteLocators::EDIT_PRODUCT_LOCAL_TASKS);
    $local_tasks_links = $block_local_tasks->findAll('css', 'ul.tabs li a');
    foreach ($local_tasks_links as $local_tasks_link) {
      if ($local_tasks_link->getText() === 'Variations') {
        $local_tasks_link->click();
      }
    }
    $table = $page->findById('edit-variations');
    $rows = $table->findAll('css', 'tbody tr');
    foreach ($rows as $row) {
      $cells = $row->findAll('css', 'td');
      foreach ($cells as $cell) {
        $table_cell_text = $cell->getText();
        if ($table_cell_text === 'Edit') {
          $link = $cell->findLink('Edit');
          $link->click();
          break 2;
        }
      }
    }
  }

  /**
   * Set the given field for a product variation and save the original value.
   *
   * @Given I set the field :field_label for the Product Variation to :new_value
   */
  public function iSetTheFieldForTheProductVariationTo($field_label, $new_value)
  {
    $page = $this->getSession()->getPage();
    $form = $page->findById('commerce-product-variation-course-edit-form');

    if ($field_label === 'field_course_level') {
      $field = $form->findById('edit-field-course-level');
      $original_value = $field->getValue();
      $this->data['original values']['edit_variation'][$field_label] = $original_value;
      $form->selectFieldOption('edit-field-course-level', $new_value);
    }
    $form->submit();
  }

  /**
   * Set the given field for a product variation to its the original value.
   *
   * @Given I set the field :field_label for the Product Variation to its original value
   */
  public function iSetTheFieldForTheProductVariationToItsOriginalValue($field_label)
  {
    $page = $this->getSession()->getPage();
    $form = $page->findById('commerce-product-variation-course-edit-form');

    if ($field_label === 'Effort') {
      $field_effort = $form->findById('edit-field-effort-0-value');
      $original_value = $this->data['original values']['edit_variation'][$field_label];
      $field_effort->setValue($original_value);
    }
    $form->submit();
  }

  /**
   * Set the specified field for a voucher and save the original value.
   *
   * @Given I set the :field_label field for the Voucher to the :test_user_op test user
   */
  public function iSetTheFieldToTheTestUser($field_label, $op_code)
  {
    $page = $this->getSession()->getPage();
    $form = $page->findById('node-voucher-edit-form');

    if ($field_label === 'Enrollee') {
      $object_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
      $first_name = $object_user->field_first_name[0];
      $last_name = $object_user->field_last_name[0];
      if ($user = user_load_by_mail($object_user->mail)) {
        $uid = $user->id();
      }
      $enrollee_str = $first_name . ' ' . $last_name . ' (' . $uid . ')';
      $enrollee_field = $form->findById('edit-field-enrollee-0-target-id');
      $original_value = $enrollee_field->getValue();
      $this->data['original values']['edit_voucher'][$field_label] = $original_value;
      $enrollee_field->setValue($enrollee_str);
    }

    $form->submit();
  }

  /**
   * Read the results from the first results page.
   *
   * @Given I read the results from the first results page
   */
  public function iReadTheResultsFromTheFirstResultsPage()
  {
    // @todo Replace explicit sleep time with a closure to check that the table is visible.
    sleep(TestSuiteConstants::WAIT_TIME_SHORT);
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::DASH_PAGE_V_TABLE);
    $entries_displayed = $this->extractRowsReact($table);
    $this->data['search results']['vouchers']['page 1'] = $entries_displayed;
  }

  /**
   * I verify that ordering works for the results on the page.
   *
   * @Given I verify that ordering works for the results on the page
   */
  public function iVerifyThatOrderingWorksForTheResultsOnThePage()
  {
    $page = $this->getSession()->getPage();
    // @todo Replace explicit sleep time with a closure to check that the table is visible.
    sleep(TestSuiteConstants::WAIT_TIME_SHORT);
    $table = $page->find('css', TestSuiteLocators::DASH_PAGE_V_TABLE);
    $table_header_links = $table->findAll('css', '.rdt_TableHeadRow .rdt_TableCol_Sortable');

    foreach ($table_header_links as $table_header_link) {
      if ($table_header_link->getText() === 'Training/Course') {
        $table_header_link->click();
        // @todo Replace explicit sleep time with a closure to check that the table has updated.
        sleep(TestSuiteConstants::WAIT_TIME_SHORT);
        $sorted_by_name_asc = $this->extractRowsReact($table);
        $verified = $this->isSortedByColumn($sorted_by_name_asc, 0, 'asc');
        if (!$verified) {
          throw new \Exception("Error - My Dashboard table ordering - Ordering By Name Ascending has failed.");
        }
        $table_header_link->click();
        // @todo Replace explicit sleep time with a closure to check that the table has updated.
        sleep(TestSuiteConstants::WAIT_TIME_SHORT);
        $sorted_by_name_desc = $this->extractRowsReact($table);
        $verified = $this->isSortedByColumn($sorted_by_name_desc, 0, 'desc');
        if (!$verified) {
          throw new \Exception("Error - My Dashboard table ordering - Ordering By Name Descending has failed.");
        }
      } elseif ($table_header_link->getText() === 'Availability') {
        $table_header_link->click();
        // @todo Replace explicit sleep time with a closure to check that the table has updated.
        sleep(TestSuiteConstants::WAIT_TIME_SHORT);
        $sorted_by_availability_desc = $this->extractRowsReact($table);
        $verified = $this->isSortedByColumn($sorted_by_availability_desc, 1, 'desc');
        if (!$verified) {
          throw new \Exception("Error - My Dashboard table ordering - Ordering By Availability Descending has failed.");
        }
        $table_header_link->click();
        // @todo Replace explicit sleep time with a closure to check that the table has updated.
        sleep(TestSuiteConstants::WAIT_TIME_SHORT);
        $sorted_by_availability_asc = $this->extractRowsReact($table);
        $verified = $this->isSortedByColumn($sorted_by_availability_asc, 1, 'asc');
        if (!$verified) {
          throw new \Exception("Error - My Dashboard table ordering - Ordering By Availability Ascending has failed.");
        }
        break;
      }
    }

  }

  /**
   * I verify that filtering works for the results on the page.
   *
   * @Given I verify that filtering works for the results on the page
   *
   * @throws Exception
   */
  public function iVerifyThatFilteringWorksForTheResultsOnThePage()
  {
    // @todo Replace explicit sleep time with a closure to check that the main table is visible.
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::DASH_PAGE_V_TABLE);
    $first_row = $table->find('css', '.rdt_TableRow');
    $first_cell = $first_row->find('css', '.rdt_TableCell');
    $table_cell_text = $first_cell->getText();
    $search_keyword = strtok($table_cell_text, ' ');

    $page->fillField('search', $search_keyword);
    // @todo Replace explicit sleep time with a closure to check that the "Loading.." modal is done.
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $table = $page->find('css', TestSuiteLocators::DASH_PAGE_V_TABLE);
    $rows = $table->findAll('css', '.rdt_TableRow');
    foreach ($rows as $row) {
      $course_title = $row->find('css', '.rdt_TableCell')->getText();
      if (strpos($course_title, $search_keyword) === FALSE) {
        throw new \Exception("Error - My Dashboard table filtering - filtering by search keyword has failed.");
      }
    }
  }

  /**
   * I verify that paging works for the results on the page.
   *
   * @Given I verify that paging works for the results on the page
   */
  public function iVerifyThatPagingWorksForTheResultsOnThePage()
  {
    $has_errors = FALSE;
    sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
    $page = $this->getSession()->getPage();
    $table = $page->find('css', TestSuiteLocators::DASH_PAGE_V_TABLE);
    $results_page_1 = $this->extractRowsReact($table);

    $pagination = $page->find('css', TestSuiteLocators::DASH_PAGE_PAGINATION);
    $current_page_grd_buttons = $pagination->findAll('css', 'button.custom-pgn-cur-pg-grd-btn');
    if (count($current_page_grd_buttons) === 0) {
      throw new \Exception("Error - Unable to test pagination in My Vouchers section - There's only one page of results.");
    }

    $current_page_grd_button = $pagination->find('css', 'button.custom-pgn-cur-pg-grd-btn');
    if ($current_page_grd_button->getText() !== "1") {
      $has_errors = TRUE;
    }
    $next_page_grd_button = $pagination->find('css', 'button.custom-pgn-next-pg-grd-btn');
    if ($next_page_grd_button->getText() !== "2") {
      $has_errors = TRUE;
    }
    $next_page_button = $pagination->find('css', 'button.custom-pgn-next-btn');
    if ($next_page_button->getText() !== "Next") {
      $has_errors = TRUE;
    }
    $last_page_button = $pagination->find('css', 'button.custom-pgn-lst-btn');
    if ($last_page_button->getText() !== "Last") {
      $has_errors = TRUE;
    }

    if (!$has_errors) {
      $next_page_button->click();

      sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
      $page = $this->getSession()->getPage();
      $table = $page->find('css', TestSuiteLocators::DASH_PAGE_V_TABLE);
      $results_page_2 = $this->extractRowsReact($table);
      if ($results_page_1 === $results_page_2) {
        $has_errors = TRUE;
      }

      $pagination = $page->find('css', TestSuiteLocators::DASH_PAGE_PAGINATION);
      $first_page_button = $pagination->find('css', '.custom-pgn-first-btn-grd button');
      if ($first_page_button->getText() !== "First") {
        $has_errors = TRUE;
      }
      $previous_page_button = $pagination->find('css', '.custom-pgn-previous-btn-grd button');
      if ($previous_page_button->getText() !== "Previous") {
        $has_errors = TRUE;
      }
      if (!$has_errors) {
        $previous_page_button->click();
        sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
        $page = $this->getSession()->getPage();
        $table = $page->find('css', TestSuiteLocators::DASH_PAGE_V_TABLE);
        $results_page_1b = $this->extractRowsReact($table);
        if ($results_page_1 !== $results_page_1b) {
          $has_errors = TRUE;
        }
      }
    }

    if ($has_errors) {
      throw new \Exception("Error - My Dashboard pagination - pagination does not match the expected structure.");
    }
  }

  /**
   * Verify that incorrect input for the Enroll Students form gets flagged.
   *
   * @Given I verify that incorrect input for the Enroll Students form gets flagged using the :op_code test user
   */
  public function iVerifyThatIncorrectInputForTheEnrollStudentsFormGetsFlaggedUsingTheTestUser($op_code)
  {
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);
    $page = $this->getSession()->getPage();
    $enroll_students_modal = $page->find('css', TestSuiteLocators::ES_MODAL);
    $manual_student_form = $enroll_students_modal->find('css', '.manual-student-form');

    $manual_student_form->fillField('studentFirstName', $target_user->field_first_name);
    $manual_student_form->fillField('studentLastName', $target_user->field_last_name);
    $pos = strpos($target_user->mail, '@');
    $invalid_email = substr($target_user->mail, 0, $pos);
    $manual_student_form->fillField('studentEmail', $invalid_email);

    if ($this->waitSecondsUntilElementAppears(TestSuiteConstants::WAIT_TIME_MED, '#studentEmail-helper-text')) {
      $error_msg = $manual_student_form->findById('studentEmail-helper-text');
      $error_msg_text = $error_msg->getText();
      if (!($error_msg_text === TestSuiteDisplayText::ESM_EMAIL_ERROR_MSG)) {
        throw new \Exception("Error - Enroll Students Form - failed verification of error message for incorrect email input.");
      }
    }

    $manual_student_form->fillField('studentEmail', $target_user->mail);
    $invalid_name = TestSuiteConstants::NON_LATIN_CHAR . $target_user->field_first_name;
    $manual_student_form->fillField('studentFirstName', $invalid_name);
    if ($this->waitSecondsUntilElementAppears(TestSuiteConstants::WAIT_TIME_MED, '#studentFirstName-helper-text')) {
      $error_msg = $manual_student_form->findById('studentFirstName-helper-text');
      $error_msg_text = $error_msg->getText();
      if (!($error_msg_text === TestSuiteDisplayText::ESM_NAME_NLC_ERROR_MSG)) {
        throw new \Exception("Error - Enroll Students Form - failed verification of error message for incorrect name input.");
      }
    }

    // @todo The routine below fails bc the error modal contains no text, it's just a red box
    /*$manual_student_form->fillField('studentFirstName', $target_user->field_first_name);
    $invalid_email2 = TestSuiteConstants::NON_LATIN_CHAR . $target_user->mail;
    $manual_student_form->fillField('studentEmail', $invalid_email2);
    $this->getSession()->executeScript(TestSuiteJsFunctions::JS_CLICK_MODAL_BUTTON);

    if ($this->waitSecondsUntilElementAppears(TestSuiteConstants::WAIT_TIME_MED, TestSuiteLocators::ESM_ERROR_MODAL)) {
      $error_modal = $page->find('css', TestSuiteLocators::ESM_ERROR_MODAL);
      $error_modal_text = $error_modal->getText();
      $pos_msg1 = strpos($error_modal_text, TestSuiteDisplayText::ESM_ERROR_MODAL_MSG1);
      $pos_msg2 = strpos($error_modal_text, TestSuiteDisplayText::ESM_ERROR_MODAL_MSG2);
      if ($pos_msg1 === FALSE || $pos_msg2 === FALSE) {
        throw new \Exception("Error - Enroll Students Form - failed verification of error message for incorrect email input.");
      }
    }*/
  }

  /**
   * Verifying that attempting to enroll a user who is already enrolled fails.
   *
   * @Given I verify that attempting to enroll the :op_code user twice results in an error
   */
  public function iVerifyThatAttemptingToEnrollTheUserTwiceResultsInAnError($op_code)
  {
    $target_user = $this->getUserWhoIsTheObjectOfTheOperation($op_code);

    // Wait until Enroll Students modal is visible.
    $this->waitSecondsUntilElementAppears(TestSuiteConstants::WAIT_TIME_MED, TestSuiteLocators::ES_MODAL);

    $enroll_students_modal = $this->getSession()->getPage()->find('css', TestSuiteLocators::ES_MODAL);
    $first_name_field = $enroll_students_modal->findById('studentFirstName');
    $first_name_field->setValue($target_user->field_first_name);

    // This clause is included because when filling the studentFirstName field (and only this field)
    // for the first time, the leading character is stripped seemingly for no reason
    $value = $first_name_field->getValue();
    $first_character = mb_substr($value, 0, 1);
    if ($first_character !== 'A') {
      $first_name_field->setValue($target_user->field_first_name);
    }

    $enroll_students_modal->fillField('studentLastName', $target_user->field_last_name);
    $enroll_students_modal->fillField('studentEmail', $target_user->mail);
    $this->getSession()->executeScript(TestSuiteJsFunctions::JS_CLICK_MODAL_BUTTON);

    // Wait until Oops modal is visible.
    $this->waitSecondsUntilElementAppears(TestSuiteConstants::WAIT_TIME_MED, TestSuiteLocators::OOPS_MODAL);

    $oops_error_modal = $this->getSession()->getPage()->find('css', TestSuiteLocators::OOPS_MODAL);
    $oops_error_modal_text = $oops_error_modal->getText();
    if (strpos($oops_error_modal_text, TestSuiteDisplayText::ESM_OOPS_MODAL) === FALSE) {
      throw new \Exception("Error - Enroll Students Form - The appropriate error message is not displayed when attempting to add a duplicate user.");
    }
  }

  /**
   * Navigate from the Dashboard Page to the My Vouchers page.
   *
   * @Given I navigate to the My Vouchers page
   */
  public function iNavigateToTheMyVouchersPage()
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
    $target_url = $this->baseUrl . '/dashboard/my-vouchers';

    $enroll_students_links = $this->getSession()->getPage()->findAll('css', TestSuiteLocators::DP_MY_VOUCHERS_LINK);
    if (count($enroll_students_links) > 0) {
      $enroll_students_link = reset($enroll_students_links);
      $enroll_students_link->click();
    }
    if (count($enroll_students_links) === 0) {
      $this->getSession()->visit($target_url);
      // @todo Logging - log that clicking the "Enroll Students" link did not work.
    }
  }

  /**
   * Click on a specific tab under "My Courses" section on Dashboard.
   *
   * @Given I click on the :tab_name tab
   */
  public function iClickOnTheSpecifiedTab($tab_name)
  {
    $tabs = $this->getSession()->getPage()->findAll('css', TestSuiteLocators::DP_COURSES_TAB);
    if (count($tabs) > 0) {
      foreach ($tabs as $tab) {
        $tab_text = $tab->getText();
        if ($tab_text === $tab_name) {
          $tab->click();
        }
      }
    }
  }

  /**
   * @Then I see the course :course_id in the My Courses table
   */
  public function iSeeTheCourseInTheMyCoursesTable($course_id)
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORT);

    /*$target_product = $this->getProductDetails($course_id);
    $page = $this->getSession()->getPage();
    $table_block = $page->findById('block-mycourseblock');
    $rows = $table_block->findAll('css', '.rdt_TableBody .rdt_TableRow');*/

    $target_product = $this->getProductDetails($course_id);
    $page = $this->getSession()->getPage();
    $table = $page->find('css', '.rdt_Table');
    $rows = $table->findAll('css', '.rdt_TableRow');

    $verified = FALSE;
    foreach ($rows as $row) {
      $cells = $row->findAll('css', '.rdt_TableCell');
      foreach ($cells as $cell) {
        $table_cell_text = $cell->getText();
        if ($table_cell_text === $target_product['VOUCHER_TITLE']) {
          $verified = TRUE;
          break;
        }
      }
    }
    if (!$verified) {
      throw new \Exception(sprintf("The expected course %s does not appear under My Courses.", $target_product['VOUCHER_TITLE']));
    }
  }

  /**
   * Runs in a loop until the closure passed in as $lambda returns TRUE.
   */
  protected function spin($lambda, $wait = 30)
  {
    for ($i = 0; $i < $wait; $i++) {
      try {
        if ($lambda($this)) {
          return TRUE;
        }
      } catch (Exception $e) {
        /* Intentionally left blank */
      }
      sleep(1);
    }

    // @todo Refactor code such that spin() function must succeed or throw an exception.
    /*$backtrace = debug_backtrace();
    throw new Exception(
      "Timeout thrown by " . $backtrace[1]['class'] . "::" . $backtrace[1]['function']
    );*/

    return FALSE;
  }

  /**
   * Runs in a loop until the target element appears on the page.
   *
   * @param int $seconds
   *   The timeout value in seconds.
   * @param string $locator
   *   The locator for the element on the page.
   * @param string $selector
   *   The type of locator that is used.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  protected function waitSecondsUntilElementAppears($seconds, $locator, $selector = 'css')
  {
    $startTime = time();
    do {
      sleep(1);
      try {
        $nodes = $this->getSession()->getPage()->findAll($selector, $locator);
        if (count($nodes) > 0) {
          return TRUE;
        }
      } catch (Exception $e) {
        // Do nothing.
      }
    } while (time() - $startTime < $seconds);

    throw new ResponseTextException(
      sprintf('Cannot find the element %s after %s seconds', $locator, $seconds),
      $this->getSession()
    );
  }

  /**
   * Runs in a loop until the target element disappears on the page.
   *
   * @param int $seconds
   *   The timeout value in seconds.
   * @param string $locator
   *   The locator for the element on the page.
   * @param string $selector
   *   The type of locator that is used.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  protected function waitSecondsUntilElementDisappears($seconds, $locator, $selector = 'css')
  {
    $startTime = time();
    do {
      sleep(1);
      try {
        $nodes = $this->getSession()->getPage()->findAll($selector, $locator);
        if (count($nodes) === 0) {
          return TRUE;
        }
      } catch (Exception $e) {
        // Do nothing.
      }
    } while (time() - $startTime < $seconds);

    throw new ResponseTextException(
      sprintf('Element %s has not disappeared after %s seconds', $locator, $seconds),
      $this->getSession()
    );
  }

  /**
   * Verify number of empty and filled rows on the Enroll Students Form.
   */
  protected function verifyNumberOfRows($enroll_students_modal, $manual_student_form, $number_empty_rows, $number_filled_rows)
  {
    $empty_rows = $manual_student_form->findAll('css', '.MuiGrid-container .MuiGrid-container');
    $filled_rows = $enroll_students_modal->findAll('css', '.CreatedStudentsList .MuiGrid-container');
    if (count($empty_rows) !== $number_empty_rows || count($filled_rows) !== $number_filled_rows) {
      throw new \Exception("Error - Enroll Students Form - the number of empty rows or filled rows does not match the expected number.");
    }
  }

  /**
   * Determine if the given array is sorted alphabetically by a specific column.
   *
   * @param array $entries
   *   The rows that need to be examined.
   * @param int $column_no
   *   The column number by which to sort.
   * @param string $asc_or_desc
   *   The sort order - "asc" (Ascending) or "desc" (Descending)
   */
  protected function isSortedByColumn(array $entries, int $column_no, string $asc_or_desc): bool
  {
    $target_column_vals = [];
    foreach ($entries as $entry) {
      if ($column_no === 0) {
        $target_column_vals[] = strtolower($entry[$column_no]);
      } elseif ($column_no == 1) {
        $target_column_value = $entry[$column_no];
        $pos = strpos($target_column_value, ' of');
        $numeric = substr($target_column_value, 0, $pos);
        $target_column_vals[] = $numeric;
      } else {
        $target_column_vals[] = $entry[$column_no];
      }
    }
    $target_column_vals_orig = $target_column_vals;

    if ($column_no === 0 && $asc_or_desc === 'asc') {
      asort($target_column_vals, SORT_STRING);
    } elseif ($column_no === 0 && $asc_or_desc === 'desc') {
      arsort($target_column_vals, SORT_STRING);
    } elseif ($column_no === 1 && $asc_or_desc === 'asc') {
      asort($target_column_vals, SORT_NUMERIC);
    } elseif ($column_no === 1 && $asc_or_desc === 'desc') {
      arsort($target_column_vals, SORT_NUMERIC);
    }

    if ($target_column_vals_orig === $target_column_vals) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Extract the values of the rows from the React table.
   *
   * @param \Behat\Mink\Element\NodeElement $table
   *   The table from which to extract.
   */
  protected function extractRowsReact(NodeElement $table): array
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $rows = $table->findAll('css', '.rdt_TableRow');

    $entries_displayed = [];
    $entry_number = 0;
    foreach ($rows as $row) {
      $row_values = [];
      $cells = $row->findAll('css', '.rdt_TableCell');
      foreach ($cells as $cell) {
        $table_cell_text = $cell->getText();
        $row_values[] = $table_cell_text;
      }
      $entries_displayed[$entry_number] = $row_values;
      $entry_number++;
    }

    return $entries_displayed;
  }

  /**
   * Fill in the Primary Address section on the Edit User page.
   */
  protected function editUserFillInPrimaryAddress($user_form, $target_user, $test_company_id)
  {
    $test_company = $this->getTestCompanyDetails($test_company_id);
    $first_name_field = $user_form->findById('edit-app-address-0-address-given-name');
    if (!$first_name_field->getText()) {
      $user_form->fillField('edit-app-address-0-address-given-name', $target_user->field_first_name . TestSuiteConstants::MOD_SUFFIX);
      $user_form->fillField('edit-app-address-0-address-family-name', $target_user->field_last_name . TestSuiteConstants::MOD_SUFFIX);
      $user_form->fillField('edit-app-address-0-address-address-line1', $test_company['ADDRESS1']);
      $user_form->fillField('edit-app-address-0-address-locality', $test_company['CITY']);
      $user_form->selectFieldOption('edit-app-address-0-address-administrative-area', $test_company['STATE']);
      $user_form->fillField('edit-app-address-0-address-postal-code', strval($test_company['ZIP']));
    }
  }

  /**
   * Verify a specific field changed in IPC Logs.
   */
  protected function verifySpecificFieldChangedInLogs($row, $field_name, $expected_old_value, $expected_new_value)
  {
    $field_changed = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_CHANGED);

    if ($field_changed->getText() === $field_name) {
      $new_value = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_NEW_VAL)->getText();
      $old_value = $row->find('css', TestSuiteLocators::IPCLOGS_FLD_OLD_VAL)->getText();
      if ($new_value === $expected_new_value && $old_value === $expected_old_value) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Go to Edit User page if not already there.
   */
  protected function verifyStatusMessageDisplayed($message_text)
  {
    $user_edit_page = $this->getSession()->getPage();
    sleep(TestSuiteConstants::WAIT_TIME_SHORTEST);
    $status_message_div = $user_edit_page->find('css', TestSuiteLocators::STATUS_MESSAGES);
    $status_message_text = $status_message_div->getText();
    if (strpos($status_message_text, $message_text) === FALSE) {
      throw new \Exception("Expected success message is not displayed after attempt to edit a user.");
    }
  }

  /**
   * Go to Edit User page if not already there.
   */
  protected function assertCurrentlyOnEditUserPage($email)
  {
    if ($user = user_load_by_mail($email)) {
      $uid = $user->id();
      $target_url = $this->baseUrl . '/user/' . $uid . '/edit';
      $page_url = $this->getSession()->getCurrentUrl();
      if ($page_url === $target_url) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Find link for the specified user in the People table and click it.
   */
  protected function selectPersonFromAdminPeopleTable($table, $expected_link_text)
  {
    $rows = $table->findAll('css', 'tbody tr');
    foreach ($rows as $row) {
      $username_link = $row->find('css', 'td.views-field-name a');
      if ($username_link !== NULL) {
        $link_text = $username_link->getText();
        if ($link_text === $expected_link_text) {
          $username_link->click();
          break;
        }
      }
    }
  }

  /**
   * Confirm via the Admin People page if the given user has a specific role.
   */
  protected function confirmUserHasRole($table, $expected_link_text, $expected_role)
  {
    $rows = $table->findAll('css', 'tbody tr');
    foreach ($rows as $row) {
      $username_link = $row->find('css', 'td.views-field-name a');
      if ($username_link !== NULL) {
        $link_text = $username_link->getText();
        if ($link_text === $expected_link_text) {
          $roles_column = $row->find('css', '.views-field-roles-target-id');
          $roles_column_text = $roles_column->getText();
          if (strpos($roles_column_text, $expected_role) !== FALSE) {
            return TRUE;
          }
          break;
        }
      }
    }
    return FALSE;
  }

  /**
   * Get the user who is the object of a test operation.
   */
  protected function getUserWhoIsTheObjectOfTheOperation($op_code)
  {
    if (strpos($op_code, "DND") === 0) {
      $users = $this->noDeleteUsers;
    } else {
      $users = $this->userManager->getUsers();
    }

    foreach ($users as $user) {
      $expected_first_name = TestSuiteConstants::GLOBAL_PREFIX . $op_code;
      if ($user->field_first_name === $expected_first_name ||
        $user->field_first_name === $expected_first_name . TestSuiteConstants::MOD_SUFFIX
      ) {
        return $user;
      }
    }
  }

  /**
   * Accept the Access Agreement that pops up until user clicks Accept.
   */
  protected function acceptAccessAgreement()
  {
    $page = $this->getSession()->getPage();
    $agreement_form = $page->findById('agreement-default-form');
    if ($agreement_form) {
      $agreement_form->pressButton('edit-submit');
    }
  }

  /**
   * Accept the Access Agreement that pops up until user clicks Accept.
   */
  protected function acceptInstructorAgreement()
  {
    $page = $this->getSession()->getPage();
    $instructor_agreement_form = $page->findById('agreement-instructor-agreement-form');
    if ($instructor_agreement_form) {
      $instructor_agreement_form->pressButton('edit-submit');
    }
  }

  /**
   * Perform a local login without using SSO.
   *
   * @param string $username
   *   The username of the user who will log in.
   * @param string $password
   *   The password of the user who will log in.
   * @param int $uid
   *   The uid of the user who will log in.
   */
  protected function performLocalLogin($username, $password, $uid)
  {
    $user = new \stdClass();
    $user->name = $username;
    $user->email = $username;
    $user->pass = $password;
    $user->uid = $uid;
    $this->login($user);
  }

  /**
   * Perform login via SSO.
   */
  protected function performSsoLogin($username, $password)
  {
    $href = $this->getSession()->getPage()->findLink("Log in")->getAttribute('href');
    $this->getSession()->visit($this->baseUrl . $href);

    $field_email = $this->getSession()->getPage()->findById('username');
    $field_email->setValue($username);

    $field_pw = $this->getSession()->getPage()->findById('password');
    $field_pw->setValue($password);

    $submit_btn = $this->getSession()->getPage()->findById('submit_button');
    $submit_btn->press();
  }

  /**
   * View vouchers for course with the specified title.
   */
  protected function viewVouchersForSpecificCourse($course_voucher_title)
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
    $page = $this->getSession()->getPage();
    $table = $page->findById('user-vouchers-component');
    $rows = $table->findAll('css', '.rdt_TableRow');

    foreach ($rows as $row) {
      $cells = $row->findAll('css', '.rdt_TableCell');
      foreach ($cells as $cell) {
        $table_cell_text = $cell->getText();
        if ($table_cell_text === $course_voucher_title || $table_cell_text === $course_voucher_title . "Expires Soon") {
          $view_button = $row->findButton('View Vouchers');
          $view_button->press();
          sleep(TestSuiteConstants::WAIT_TIME_SHORTER);
          $modals = $page->findAll('css', '.modalDialog');
          if (count($modals) > 0) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Get Test Company data from constants defined in TestSuiteConstants class.
   */
  protected function getTestCompanyDetails($company_id)
  {
    $name = constant(TSC::class . '::' . $company_id . "_NAME");
    $address1 = constant(TSC::class . '::' . $company_id . "_ADDRESS1");
    $city = constant(TSC::class . '::' . $company_id . "_CITY");
    $state = constant(TSC::class . '::' . $company_id . "_STATE");
    $zip = constant(TSC::class . '::' . $company_id . "_ZIP");

    return [
      "NAME" => $name,
      "ADDRESS1" => $address1,
      "CITY" => $city,
      "STATE" => $state,
      "ZIP" => $zip,
    ];
  }

  /**
   * Get Test User data from constants defined in TestSuiteConstants class.
   */
  protected function getTestUserDetails($user_id)
  {
    $user_email = constant(TSC::class . '::' . $user_id . "_EMAIL");
    $user_first = constant(TSC::class . '::' . $user_id . "_FIRST_NAME");
    $user_last = constant(TSC::class . '::' . $user_id . "_LAST_NAME");
    $company = constant(TSC::class . '::' . $user_id . "_COMPANY");
    $company_location = constant(TSC::class . '::' . $user_id . "_COMPANY_LOCATION");

    return [
      "EMAIL" => $user_email,
      "FIRST_NAME" => $user_first,
      "LAST_NAME" => $user_last,
      "COMPANY" => $company,
      "COMPANY_LOCATION" => $company_location,
    ];
  }

  /**
   * Get Special User data from constants defined in TestSuiteConstants class.
   */
  protected function getSpecialUserDetails($user_id)
  {
    $user_email = constant(TSC::class . '::' . $user_id . "_EMAIL");
    $user_first = constant(TSC::class . '::' . $user_id . "_FIRST_NAME");
    $user_last = constant(TSC::class . '::' . $user_id . "_LAST_NAME");
    $user_displayed_name = constant(TSC::class . '::' . $user_id . "_DISPLAYED_NAME");

    return [
      "EMAIL" => $user_email,
      "FIRST_NAME" => $user_first,
      "LAST_NAME" => $user_last,
      "DISPLAYED_NAME" => $user_displayed_name,
    ];
  }

  /**
   * Get Product data from constants defined in TestSuiteConstants class.
   */
  protected function getProductDetails($product_id)
  {
    $course_title = constant(TSC::class . '::' . $product_id . "_COURSE_TITLE");
    $course_product_number = constant(TSC::class . '::' . $product_id . "_PRODUCT_NUMBER");
    $course_path = constant(TSC::class . '::' . $product_id . "_PATH");
    $course_title_short = constant(TSC::class . '::' . $product_id . "_COURSE_TITLE_SHORT");
    $course_cart_item_title = constant(TSC::class . '::' . $product_id . "_CART_ITEM_TITLE");
    $course_lang = constant(TSC::class . '::' . $product_id . "_LANG");
    $course_modality = constant(TSC::class . '::' . $product_id . "_MODALITY");
    $course_voucher_title = constant(TSC::class . '::' . $product_id . "_VOUCHER_TITLE");

    $course_info = [
      "COURSE_TITLE" => $course_title,
      "PRODUCT_NUMBER" => $course_product_number,
      "PATH" => $course_path,
      "COURSE_TITLE_SHORT" => $course_title_short,
      "CART_ITEM_TITLE" => $course_cart_item_title,
      "LANG" => $course_lang,
      "MODALITY" => $course_modality,
      "VOUCHER_TITLE" => $course_voucher_title,
    ];

    if (defined(TSC::class . '::' . $product_id . "_COURSE_LIST_TITLE")) {
      $course_info["COURSE_LIST_TITLE"] = constant(TSC::class . '::' . $product_id . "_COURSE_LIST_TITLE");
    }
    if (defined(TSC::class . '::' . $product_id . "_PRE_REQ")) {
      $course_info["PRE_REQ"] = constant(TSC::class . '::' . $product_id . "_PRE_REQ");
    }

    return $course_info;
  }

  /**
   * Complete the Missing Information form that pops up if data is missing.
   */
  protected function completeMissingInformationForm()
  {
    sleep(TestSuiteConstants::WAIT_TIME_SHORT);

    $page = $this->getSession()->getPage();
    $user_address_form = $page->findById('user-address-form');
    if ($user_address_form) {
      $user_address_form_text = $user_address_form->getText();
      if ($user_address_form_text !== '') {
        $company_val = $page->find('css', 'input[data-drupal-selector="edit-address-user-company"]')->getValue();
        if (!$company_val) {
          $test_company = $this->getTestCompanyDetails('TEST_COMPANY_1');
          $field_company = $page->find('css', 'input[data-drupal-selector="edit-address-user-company"]');
          $field_company->setValue($test_company['NAME']);
          $this->waitSecondsUntilElementDisappears(TestSuiteConstants::WAIT_TIME_MED, '.ajax-progress-fullscreen');

          $page->find('css', 'input[data-drupal-selector="edit-address-address-line1"]')->setValue($test_company['ADDRESS1']);
          $page->find('css', 'input[data-drupal-selector="edit-address-locality"]')->setValue($test_company['CITY']);
          $user_address_form->selectFieldOption('address[administrative_area]', $test_company['STATE']);
          $page->find('css', 'input[data-drupal-selector="edit-address-postal-code"]')->setValue(strval($test_company['ZIP']));

          $this->waitSecondsUntilElementDisappears(TestSuiteConstants::WAIT_TIME_MED, '.ajax-progress-fullscreen');
        }
        $this->getSession()->executeScript(TestSuiteJsFunctions::JS_CLICK_EDIT_SEND_BUTTON);
      }
    }
  }

  /**
   * Extract the number of available vouchers from displayed text.
   */
  protected function extractAmountOfAvailableVouchers($voucher_info_text)
  {
    $pos = strpos($voucher_info_text, 'Available Vouchers:');
    $available_vouchers_text = substr($voucher_info_text, $pos);
    $colon_pos = strpos($available_vouchers_text, ':');
    $substring = substr($available_vouchers_text, $colon_pos + 2);
    $number_vouchers_available = intval($substring);
    return $number_vouchers_available;
  }

  /**
   * Checks that the form button is visible and then presses it.
   *
   * @Then Wait for the :button button to appear and press it
   */
  public function assertButtonIsVisibleAndPressIt($button): void
  {
    if ($button === "Enroll Now") {
      $this->waitSecondsUntilElementAppears(TestSuiteConstants::WAIT_TIME_MED, TestSuiteLocators::VV_ENROLL_NOW_BTN);
      $button = $this->getSession()->getPage()->find('css', TestSuiteLocators::VV_ENROLL_NOW_BTN);
      $button->press();
    }
  }

  /**
   * Visit user page.
   *
   * @Given I go to the user page :page
   */
  public function goToUserRoute(string $page): void
  {
    $user_object = $this->userManager->getCurrentUser();
    $url = '/user/' . $user_object->uid . '/' . $page;
    $this->clearCacheThenVisit($url);
  }

  /**
   * Visit latest created entity path.
   *
   * @Given I go to latest created entity with type :entity_type
   */
  public function visitLatestEntityByType(string $entity_type): void
  {
    $map = [
      'commerce_order' => '/orders/',
      'commerce_invoice' => '/invoices/',
    ];
    $user_object = $this->userManager->getCurrentUser();
    $entity = self::loadLatestByUserId($entity_type, $user_object->uid);
    if (!empty($entity)) {
      $url = '/user/' . $user_object->uid . $map[$entity_type] . $entity->id();
      $this->clearCacheThenVisit($url);
    }
  }

  /**
   * Visit latest created order.
   *
   * @Given I go to latest created order
   */
  public function visitLatestAddedOrder(): void
  {
    $entity = self::loadLatestOrder('commerce_order');
    if (!empty($entity)) {
      $url = '/admin/commerce/orders/' . $entity->id();
      $this->clearCacheThenVisit($url);
    }
  }

  /**
   * Visit entity path.
   *
   * @Given I go to entity with type :entity_type and uuid :uuid and rel :rel
   */
  public function visitEntityUrl(string $entity_type, string $uuid, string $rel): void
  {
    $entity_object = self::$entities[$entity_type][$uuid] ?? NULL;
    if ($entity_object !== NULL) {
      $entity = self::load($entity_type, $entity_object->id);
      $url = $entity->toUrl($rel);
      $this->clearCacheThenVisit($url->toString());
    }
  }

  /**
   * Visit taxonomy entity path.
   *
   * @Given I go to taxonomy with uuid :uuid
   */
  public function visitTaxonomyTermUrl(string $uuid): void
  {
    $entity_type = 'taxonomy_term';
    $entity_object = self::$entities[$entity_type][$uuid] ?? NULL;
    if ($entity_object !== NULL) {
      $entity = self::load($entity_type, $entity_object->id);
      $url = $entity->toUrl();
      $this->clearCacheThenVisit($url->toString());
    }
  }

  /**
   * Presses the element with specified css selector.
   *
   * @When I press element by css selector :selector
   */
  public function pressElementByCssSelector(string $selector)
  {
    try {
      $this->getSession()
        ->wait(1000, 'typeof(jQuery)=="undefined" || jQuery("#autocomplete").length === 0');
    } catch (UnsupportedDriverActionException $e) {
    }

    $this->getSession()->getPage()->find('css', $selector)->click();
  }

  /**
   * Removes all entities which was created during checkout by type.
   *
   * @When I should remove all created entities of :type type
   */
  public function removeAllEntitiesOfGivenTypeByUser(string $entity_type)
  {
    $user_object = $this->userManager->getCurrentUser();
    $entities = self::loadByUserId($entity_type, $user_object->uid);
    foreach ($entities as $entity) {
      $entity->delete();
    }
  }

  /**
   * Get the entity for the given type and id.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int $id
   *   The entity id.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity or NULL if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function load(string $entity_type, int $id): ?EntityInterface
  {
    $entity_type_manager = \Drupal::entityTypeManager();
    $storage = $entity_type_manager->getStorage($entity_type);
    return $storage->load($id);
  }

  /**
   * Get the latest entity for the given type by user id.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int $uid
   *   The user id.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity or NULL if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function loadLatestByUserId(string $entity_type, int $uid): ?EntityInterface
  {
    $entity_type_manager = \Drupal::entityTypeManager();
    $storage = $entity_type_manager->getStorage($entity_type);
    $id = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->sort('created', 'DESC')
      ->execute();

    return !empty($id) ? $storage->load(reset($id)) : NULL;
  }

  /**
   * Get the latest order.
   *
   * @param string $entity_type
   *   The entity type.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity or NULL if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function loadLatestOrder(string $entity_type): ?EntityInterface
  {
    $entity_type_manager = \Drupal::entityTypeManager();
    $storage = $entity_type_manager->getStorage($entity_type);
    $id = $storage->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->sort('created', 'DESC')
      ->execute();

    return !empty($id) ? $storage->load(reset($id)) : NULL;
  }

  /**
   * Get all entities for the given type by user id.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int $uid
   *   The user id.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]|null
   *   The entity or NULL if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function loadByUserId(string $entity_type, int $uid): ?array
  {
    $entity_type_manager = \Drupal::entityTypeManager();
    $storage = $entity_type_manager->getStorage($entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();

    return !empty($ids) ? $storage->loadMultiple($ids) : NULL;
  }

  /**
   * Checks that the form button is disabled.
   *
   * @Then the :button button is disabled
   */
  public function assertButtonIsDisabled($button): void
  {
    $element = $this->getSession()->getPage();
    $buttonObj = $element->findButton($button);
    if (empty($buttonObj)) {
      throw new \Exception(sprintf("The button '%s' was not found on the page %s", $button, $this->getSession()->getCurrentUrl()));
    }
    if (in_array($buttonObj->getAttribute('disabled'), [
      '',
      'disabled',
    ], TRUE)) {
      return;
    }
    throw new \Exception(sprintf("The button '%s' is enabled %s", $button, $this->getSession()->getCurrentUrl()));
  }

  /**
   * Check if the selected rate between provided values.
   *
   * @Then the value of :selector is between :minValue and :maxValue
   */
  public function assertBetweenRates(string $selector, float $minValue, float $maxValue): void
  {
    $element = $this->assertSession()->elementExists('css', $selector);
    $value = $element->getText();
    if (empty($value)) {
      throw new \Exception(sprintf("Provided selector '%s' was not found on the page %s", $selector, $this->getSession()
        ->getCurrentUrl()));
    }
    $cleared_value = floatval(str_replace(['$', ','], '', $value));
    if ($cleared_value >= $minValue && $cleared_value <= $maxValue) {
      return;
    }
    throw new \Exception(sprintf("The selected value %s is not between %s and %s", $value, $minValue, $maxValue));
  }

  /**
   * Disables file auto upload.
   *
   * @Then I disable file auto upload
   */
  public function assertFileDisableAutoUpload(): void
  {
    $this->getSession()->executeScript(TestSuiteJsFunctions::DISABLE_AUTO_UPLOAD);
  }

  /**
   * Prints the console messages.
   *
   * @Then I print the console messages
   */
  public function assertPrintConsoleMessages(): void
  {
    $this->printConsoleMessages();
  }

  /**
   * Clears the console messages.
   *
   * @Then I clear the console messages
   */
  public function assertClearConsoleMessages(): void
  {
    $this->clearConsoleMessages();
  }

  /**
   * Force iframe on ajax element.
   *
   * @Then I force iframe for ajax element :selector
   */
  public function assertForceIframeForAjaxElement(string $selector): void
  {
    $script = <<<JS
    (function() {
      let element = Drupal.ajax.instances.find(e => e && e.selector && e.selector.startsWith('$selector'));
      if (element) {
        element.options.iframe = true;
      }
    }());
JS;
    $this->getSession()->executeScript($script);
  }

  /**
   * Set a state key/value.
   *
   * @Then I set state key :key to value :value
   */
  public static function assertSetStateValue(string $key, string $value)
  {
    \Drupal::state()->set($key, $value);
  }

  /**
   * Wait for AJAX to finish.
   */
  protected function waitForAjaxToFinish($event = NULL)
  {
    $ajax_timeout = $this->getMinkParameter('ajax_timeout');
    $result = $this->getSession()->wait(1000 * $ajax_timeout, TestSuiteConstants::JS_WAIT_FOR_AJAX);
    if (!$result) {
      if ($ajax_timeout === NULL) {
        throw new \Exception('No AJAX timeout has been defined. Please verify that "Drupal\MinkExtension" is configured in behat.yml (and not "Behat\MinkExtension").');
      }
      if ($event) {
        /** @var \Behat\Behat\Hook\Scope\BeforeStepScope $event */
        $event_data = ' ' . json_encode([
            'name' => $event->getName(),
            'feature' => $event->getFeature()->getTitle(),
            'step' => $event->getStep()->getText(),
            'suite' => $event->getSuite()->getName(),
          ]);
      } else {
        $event_data = '';
      }
      throw new \RuntimeException('Unable to complete AJAX request.' . $event_data);
    }
  }

  /**
   * Bootstraps Drupal.
   *
   * We need to ensure that Drupal is properly bootstrapped before we run any
   * other hooks or execute step definitions. By calling `::getDriver()` we can
   * be sure that Drupal is ready to rock.
   *
   * This hook should be placed at the top of the first Context class listed in
   * behat.yml.
   *
   * @BeforeScenario @api
   */
  public function bootstrap(): void
  {
    $driver = $this->getDriver();
    if (!$driver->isBootstrapped()) {
      $driver->bootstrap();
    }
  }

  /**
   * Gather any contexts needed.
   *
   * @BeforeScenario
   */
  public function gatherContexts(BeforeScenarioScope $scope)
  {
    $environment = $scope->getEnvironment();
    $this->drupalContext = $environment->getContext('Drupal\DrupalExtension\Context\DrupalContext');
    $this->minkContext = $environment->getContext('Drupal\DrupalExtension\Context\MinkContext');
  }

}
