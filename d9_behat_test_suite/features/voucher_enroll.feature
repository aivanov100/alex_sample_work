@api @javascript @vouchers
Feature: Test Voucher Enroll
  In order to enroll a user in a class
  Users serving as Instructor or Class Manager
  Should be able to assign a voucher to a user

  Scenario: Enroll a user in a course using a voucher
    Given A special test user is created to be the object of the operation "DND-VENRL"
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
    When I assign a voucher to the user who is the object of the operation "DND-VENRL"
    Then An Enrollment Success modal is displayed
    And I view the existing vouchers for course "TEST_PRODUCT_3"
    And I press the "Enroll Students" button
    And I verify that attempting to enroll the "DND-VENRL" user twice results in an error
    And I close the Vouchers modal
    And I run drush "advancedqueue:queue:process" "ipc_enrollment_sync"
    And I log in as the user who is the object of the operation "DND-VENRL"
    And I navigate to My Dashboard
    When I click "Go to My Courses"
    And I click on the "Courses" tab
    #Then I see the course "TEST_PRODUCT_3" in the My Courses table
    Then I am able to start the course "TEST_PRODUCT_3"

  Scenario: Enroll users in a course using CSV file upload
    Given Special test users, "3" in total, are created and saved to CSV file "ATS_created_users.csv"
    And I am on the homepage
    And I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to My Dashboard
    When I click "Enroll Students"
    Then I should see the text "My Account: Vouchers" in the "breadcrumb" region
    When I view the existing vouchers for course "TEST_PRODUCT_5"
    Then I verify that the data in View Vouchers modal is correct for course "TEST_PRODUCT_5"
    When I press the "Enroll Students" button
    Then I verify that the data in Enroll Students modal is correct for course "TEST_PRODUCT_5"
    When I click on "Upload CSV" tab
    # @todo The step below fails because of a typo in the text under "Instructors" (DG-1358)
    # @todo The step below also fails because "Enroll Now" button in "Enroll Students" modal is not disabled initially (DG-1345)
    ##And I verify the Upload CSV form
    And I attach the file "ATS_created_users.csv" to "csvFile"
    And I press the "Upload Now" button
    # @todo Pressing the "Upload Now" button does nothing
    ##When I verify that the "3" CSV file users appear under Added Students
    ##Then Wait for the "Enroll Now" button to appear and press it
    ##And An Enrollment Success modal is displayed
    ##And I run drush "advancedqueue:queue:process" "ipc_enrollment_sync"

  Scenario: Details and edge cases
    Given A special test user is created to be the object of the operation "DND-VENRL-EDG"
    And I am on the homepage
    And I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to My Dashboard
    And I click "Enroll Students"
    And I view the existing vouchers for course "TEST_PRODUCT_1"
    And I verify that the data in View Vouchers modal is correct for course "TEST_PRODUCT_1"
    When I press the "Enroll Students" button
    Then I verify that the data in Enroll Students modal is correct for course "TEST_PRODUCT_1"
    # @todo The step below fails bc pre-req course is not shown (DG-1359)
    ##Then I verify that the prereq course is displayed in the Enroll Students modal for course "TEST_PRODUCT_1"
    And I verify that the Enroll Students form works correctly using the "DND-VENRL-EDG" test user
    And I verify that incorrect input for the Enroll Students form gets flagged using the "DND-VENRL-EDG" test user
