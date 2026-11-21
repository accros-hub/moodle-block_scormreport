@block @block_scormreport @block_scormreport_mmrt293 @javascript
Feature: Check the MMRT-293 report and reminder pages
  Background:
    Given the following "courses" exist:
      | fullname                   | shortname |
      | Audit & <em>Compliance</em> | MMRT293   |
    And the following "users" exist:
      | username | firstname | lastname |
      | learner  | Review    | Learner  |
    And the following "course enrolments" exist:
      | user    | course  | role    |
      | learner | MMRT293 | student |
    And the following "activities" exist:
      | activity | name        | course  |
      | scorm    | Review SCO  | MMRT293 |
    And the SCORM report test fixture is enabled for "MMRT293"
    And I log in as "admin"

  Scenario: View the compliance report and its course link
    When I visit "/blocks/scormreport/compliance.php?filter=all"
    Then I should see "Global tracking of other courses"
    And I should see "Audit & <em>Compliance</em>" in the "table.scorm-report-table" "css_element"
    And I follow "Audit & <em>Compliance</em>"
    And I should see "Review Learner"

  Scenario: Preview reminders without sending
    When I open the SCORM report reminder form for "MMRT293"
    And I set the field "minpct" to "75%"
    Then I should see "Review Learner"
    When I click on "#ch_email" "css_element"
    Then I should see "Review Learner"
    When I set the field "msg-body" to "Preview of the example reminder"
    Then I should see "Preview of the example reminder" in the "#preview-box" "css_element"
    When I click on ".recipient-check" "css_element"
    Then "#btn-send:disabled" "css_element" should exist
    And I should see "0" in the "#send-count" "css_element"

  Scenario: Download the authenticated PDF endpoints
    Then the SCORM report downloads all three PDFs for "MMRT293" and "learner"
