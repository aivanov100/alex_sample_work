@api @javascript @logs @disabled
Feature: Verify Log Records
  In order to track changes to users and entities
  Admin users should be able to
  view the change history via IPC Custom Logs

  Scenario: Verify that Name modifications appear in the IPC Custom log
    Given A special test user is created to be the object of the operation "RENAME"
    And I am logged-in via SSO as "TEST_USER_2"
    And I accept the Access Agreement
    And I complete the Missing Information form
    And I am on the homepage
    And I navigate to the Edit User form for the "RENAME" test user
    And I update the name of the "RENAME" test user
    When I navigate to IPC Custom Logs
    # @todo Enable the verification steps below once the changes are indeed displayed correctly
    #Then I verify that the field "field_first_name" was updated for the "RENAME" test user
    #And I verify that the field "field_last_name" was updated for the "RENAME" test user

  Scenario: Verify that Content modifications appear in the IPC Custom log
    Given A special test user is created to be the object of the operation "EC"
    # @todo When the below step is active the TC fails because the specified role is not properly assigned to the new user
    ## And I am logged into the IPC site as an "ipc_service_agent"
    And I am logged-in via SSO as "TEST_USER_2"
    And I accept the Access Agreement
    And I complete the Missing Information form
    And I am on the homepage
    And I navigate to the Edit User form for the "EC" test user
    And I assign the role "IPC Service Agent" to the "EC" test user
    And I verify that the "EC" test user has the role "IPC Service Agent"
    And I am on the homepage
    And I log out from IPC Edge
    And I log in as the user who is the object of the operation "EC"
    And I am on the homepage
    # @todo Permissions needed for the "EC" TEST USER to view top menu and the pages accessed in the steps below
    ## And I navigate to Content page
    ## And I filter the displayed content by Content Type "Course Group Class"
    ## And I click to edit the result in the Views table with the name "TEST_PRODUCT_EDIT_CONTENT_2"
    ## And I add the primary test case user as an instructor
    ## When I navigate to IPC Custom Logs
    ## Then I verify that field instructor was set to primary test user email for course "TEST_PRODUCT_EDIT_CONTENT_2"

  Scenario: Verify that Product modifications appear in the IPC Custom log
    Given A special test user is created to be the object of the operation "EP"
    # @todo When the below step is active the TC fails because the specified role is not properly assigned to the new user
    # And I am logged in as an "ipc_service_agent"
    And I am logged-in via SSO as "TEST_USER_2"
    And I accept the Access Agreement
    And I complete the Missing Information form
    And I am on the homepage
    And I navigate to the Edit Product page for "TEST_PRODUCT_2"
    And I enable "Instructor Required (CIT, MIT)" for the Product
    When I navigate to IPC Custom Logs
    # @todo Uncomment the lines below when IPC Log Errors are fixed (DG-1346)
    ##Then I verify that the field "field__designation_required" was updated to "1" for "TEST_PRODUCT_2"
    ##And I navigate to the Edit Product page for "TEST_PRODUCT_2"
    ##And I disable "Instructor Required (CIT, MIT)" for the Product

  Scenario: Verify that Product Variation modifications appear in the IPC Custom log
    # @todo When the below step is active the TC fails because the specified role is not properly assigned to the new user
    # Given I am logged in as an "ipc_service_agent"
    Given I am logged-in via SSO as "TEST_USER_2"
    And I accept the Access Agreement
    And I complete the Missing Information form
    And I am on the homepage
    And I navigate to the Edit Product page for "TEST_PRODUCT_2"
    And I navigate to the Edit Variation for the first variation of "TEST_PRODUCT_2"
    And I set the field "field_course_level" for the Product Variation to "Advanced"
    When I navigate to IPC Custom Logs
    # @todo Uncomment the lines below when IPC Log Errors are fixed (DG-1346)
    ##Then I verify that the field "field_course_level" was updated to "course-level-advanced" for "TEST_PRODUCT_2"
    ##And I navigate to the Edit Product page for "TEST_PRODUCT_2"
    ##And I navigate to the Edit Variation for the first variation of "TEST_PRODUCT_2"
    ##And I set the field "field_course_level" for the Product Variation to its original value

  Scenario: Verify that Voucher modifications appear in the IPC Custom log
    Given A special test user is created to be the object of the operation "EV"
    # @todo When the below step is active the TC fails because the specified role is not properly assigned to the new user
    # And I am logged in as an "ipc_service_agent"
    And I am logged-in via SSO as "TEST_USER_2"
    And I accept the Access Agreement
    And I complete the Missing Information form
    And I am on the homepage
    And I navigate to Content page
    And I filter the displayed content by Content Type "Voucher"
    And I click to edit the first result in the Views table
    And I set the "Enrollee" field for the Voucher to the "EV" test user
    When I navigate to IPC Custom Logs
    # @todo Uncomment the lines below when IPC Log Errors are fixed
    #Then I verify that the field "field_enrollee" was updated to reference the "EV" test user
