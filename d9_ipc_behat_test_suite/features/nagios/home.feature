@api @javascript @nagios
Feature: Test Home Page account menu
  In order to log in or register or manage their account
  Anonymous users and authenticated users should be able to
  view the account menu

  Scenario: Make sure that anonymous users see the account menu links
    Given I am not logged in
    And I am on the homepage
    Then I should see the link "Register" in the "header_top" region
    And I should see the link "Log in" in the "header_top" region
