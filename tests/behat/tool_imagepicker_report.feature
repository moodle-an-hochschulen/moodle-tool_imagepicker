@tool @tool_imagepicker @javascript @_file_upload
Feature: Inspecting and deleting preserved original images in the report
  In order to know which original images the site keeps and to get rid of them
  As an admin
  I need a report which lists the preserved originals and lets me delete them

  Background:
    Given the following config values are set as admin:
      | preserveoriginals | 1 | tool_imagepicker |
    And I log in as "admin"

  Scenario: Report: An empty report says so and offers nothing to delete
    Given I am on the "tool_imagepicker > Report" page
    Then I should see "The preservation of original images is currently enabled"
    And I should see "There are no preserved originals on this site at the moment"
    And "Delete all originals" "link" should not exist

  Scenario: Report: A preserved original is listed with its place, its status and the last crop region
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.gif |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0.5 / 0.5 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    And I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) | Original image status | Cropped image status | Last crop region |
      | tool_imagepicker / demo / 1                          | In place              | In use               | 200 × 150 px     |
    And the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) | Original image status | Cropped image status | Last crop region              |
      | System                                               | .gif                  | .png                 | at 200 / 150 px from top left |
    And ".action-view" "css_element" should exist in the "#tool_imagepicker_originals" "css_element"
    And ".action-view[href*='/tool_imagepicker/original/']" "css_element" should exist in the "#tool_imagepicker_originals" "css_element"
    And ".action-delete" "css_element" should exist in the "#tool_imagepicker_originals" "css_element"
    And "Delete all originals" "link" should exist

  Scenario: Report: A single original is deleted after a confirmation
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    And I am on the "tool_imagepicker > Report" page
    And I click on ".action-delete" "css_element" in the "#tool_imagepicker_originals" "css_element"
    Then I should see "Do you really want to delete the preserved original with the ID" in the "Delete original" "dialogue"
    When I click on "Cancel" "button" in the "Delete original" "dialogue"
    Then "1" preserved original should exist
    When I click on ".action-delete" "css_element" in the "#tool_imagepicker_originals" "css_element"
    And I click on "Delete" "button" in the "Delete original" "dialogue"
    Then I should see "The preserved original was deleted."
    And I should see "There are no preserved originals on this site at the moment"
    And "Delete all originals" "link" should not exist
    And "0" preserved originals should exist
    And the image of "imagepicker" should have the dimensions "200 x 150"

  Scenario: Report: All originals are deleted at once after a confirmation
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepickerratio                                |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.jpg |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I open the crop modal of "imagepickerratio"
    And I set the crop selection to "0 / 0 / 0.8 / 0.6"
    And I save the crop
    And I press "Save changes"
    And I am on the "tool_imagepicker > Report" page
    Then "2" preserved originals should exist
    When I click on "Delete all originals" "link"
    Then I should see "Do you really want to delete all preserved originals on this site?" in the "Delete all originals" "dialogue"
    When I click on "Cancel" "button" in the "Delete all originals" "dialogue"
    Then "2" preserved originals should exist
    When I click on "Delete all originals" "link"
    And I click on "Delete" "button" in the "Delete all originals" "dialogue"
    Then I should see "2 preserved originals were deleted."
    And I should see "There are no preserved originals on this site at the moment"
    And "0" preserved originals should exist
    And the image of "imagepicker" should have the dimensions "200 x 150"
    And the image of "imagepickerratio" should have the dimensions "320 x 180"

  Scenario: Report: An original whose file has gone missing is marked as such and its crop region is shown in percent
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    And I delete the preserved original file from storage
    And I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Original image status | Last crop region                  |
      | Missing               | 50 % × 50 % of the original image |
    And the following should exist in the "tool_imagepicker_originals" table:
      | Last crop region           |
      | at 0 % / 0 % from top left |
    And ".action-view" "css_element" should not exist in the "#tool_imagepicker_originals" "css_element"
    And ".action-delete" "css_element" should exist in the "#tool_imagepicker_originals" "css_element"

  Scenario: Report: An orphaned original is marked as such until the scheduled task removes it
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    And I delete "image.png" from "Image" filemanager
    And I press "Save changes"
    And the draft files of "admin" are cleaned up
    And I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) | Original image status | Cropped image status |
      | tool_imagepicker / demo / 1                          | In place              | Orphaned             |
    When I run the scheduled task "\tool_imagepicker\task\cleanup_originals"
    And I reload the page
    Then I should see "There are no preserved originals on this site at the moment"

  Scenario: Report: The report tells when the preservation is disabled but still lists what was preserved before
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    And the following config values are set as admin:
      | preserveoriginals | 0 | tool_imagepicker |
    And I am on the "tool_imagepicker > Report" page
    Then I should see "The preservation of original images is currently disabled"
    And the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) |
      | tool_imagepicker / demo / 1                          |
    And "Delete all originals" "link" should exist

  Scenario: Report: The view action serves the original image
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    And I am on the "tool_imagepicker > Report" page
    And I click on ".action-view" "css_element" in the "#tool_imagepicker_originals" "css_element"
    Then the current page should be an image
