@api @javascript @vouchers
Feature: Test Voucher Edit
  In order to manage enrollment for a voucher
  Users serving as Instructor or Class Manager
  Should be able to edit an assigned voucher for a user

  Scenario: Edit an existing voucher - remove user
    Given A special test user is created to be the object of the operation "DND-VREM"
    And I am on the homepage
    And I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to My Dashboard
    When I click "Enroll Students"
    Then I should see the text "My Account: Vouchers" in the "breadcrumb" region
    When I view the existing vouchers for course "TEST_PRODUCT_3"
    Then I verify that the data in View Vouchers modal is correct for course "TEST_PRODUCT_3"
    When I press the "Enroll Students" button
    Then I verify that the data in Enroll Students modal is correct for course "TEST_PRODUCT_3"
    When I assign a voucher to the user who is the object of the operation "DND-VREM"
    Then An Enrollment Success modal is displayed
    And I close the Vouchers modal
    And I run drush "advancedqueue:queue:process" "ipc_enrollment_sync"
    And I navigate to My Dashboard
    And I click "Enroll Students"
    When I view the existing vouchers for course "TEST_PRODUCT_3"
    Then I verify that the voucher is assigned to the "DND-VREM" special test user
    When I delete the voucher assignment for the "DND-VREM" special test user
    Then A success message is displayed in the SnackBar for operation "DND-VREM"
    And The "DND-VREM" special test user has been removed from course "TEST_PRODUCT_3"
