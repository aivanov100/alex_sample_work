@api @javascript
Feature: Test Data Tables (Filters, Paging, Ordering)
  In order to properly interact with Dashboard data tables
  Authenticated users
  Should be able to use Filters, Paging, and Ordering

  Scenario: Test Data Tables
    # @todo Given that TEST_USER_1 has purchased more than 25 vouchers
    Given I am on the homepage
    And I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to My Dashboard
    And I navigate to the My Vouchers page
    And I read the results from the first results page
    And I verify that filtering works for the results on the page
    And I navigate to My Dashboard
    And I navigate to the My Vouchers page
    And I verify that ordering works for the results on the page
    # @todo Uncomment the lines below once we have a setup function to buy 26 vouchers
    #And I navigate to My Dashboard
    #And I navigate to the My Vouchers page
    #And I verify that paging works for the results on the page
