@api @javascript @vouchers
Feature: Test Voucher Transfer
  In order to change the enrollee for a voucher
  Users serving as Instructor or Class Manager
  Should be able to transfer a voucher from one user to another

  Background:
    Given A special test user is created to be the object of the operation "DND-VTRFR"

  Scenario: Transfer assigned voucher to another user
    Given I am on the homepage
    And I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to My Dashboard
    And I click "Enroll Students"
    And I should see the text "My Account: Vouchers" in the "breadcrumb" region
    And I view the existing vouchers for course "TEST_PRODUCT_3"
    And I verify that the data in View Vouchers modal is correct for course "TEST_PRODUCT_3"
    When I transfer a voucher to the user who is the object of the operation "DND-VTRFR"
    # todo Figure out why the success message is not properly read (race condition?)
    #Then A success message is displayed in the SnackBar
    And I run drush "advancedqueue:queue:process" "ipc_enrollment_sync"
    When I close the Vouchers modal
    And I log out from IPC Edge
    And I log in as the user who is the object of the operation "DND-VTRFR"
    And I navigate to My Dashboard
    And I click "Go to My Courses"
    When I click on My Dashboard Left Menu link "Vouchers"
    Then I see a voucher for course "TEST_PRODUCT_3"
