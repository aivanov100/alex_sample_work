@api @javascript @nagios
Feature: Test User Login
  In order to gain access to their Dashboard
  Users with any role
  Should be able to login to the site

  Scenario: Perform user login
    Given I am on the homepage
    When I am logged-in via SSO as "TEST_USER_1"
    Then I should see "My IPC EDGE" in the ".site-header-top" element
    And I should see "Log out" in the ".site-header-top" element
