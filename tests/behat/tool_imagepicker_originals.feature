@tool @tool_imagepicker @javascript @_file_upload
Feature: Cropping with preserved original images
  In order to adjust a crop later on without losing anything
  As a user of a form with an image picker
  I need the site to preserve the original image and to crop from it again

  Background:
    Given the following config values are set as admin:
      | preserveoriginals | 1 | tool_imagepicker |
    And I log in as "admin"

  Scenario: Originals: The first crop of a fresh upload preserves the original with the draft area until the form is saved
    Given I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    Then I should see "The original image is preserved on the server" in the ".tool-imagepicker-crop-modal" "css_element"
    And I should not see "[["
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then "1" preserved original should exist
    And the preserved original should be placed in "user / draft / *"
    And the preserved original should be stored in the user context
    When I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) | Original image status | Cropped image status |
      | user / draft /                                       | In place              | In use               |
    And the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) |
      | Draft, not saved yet                                 |

  Scenario: Originals: Saving the form settles the original into the place of the image, and the next crop starts from the original
    Given I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "200 x 150"
    And "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"
    And the preserved original should be stored in the system context
    When I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) | Last crop region |
      | tool_imagepicker / demo / 1                          | 200 × 150 px     |
    And the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) |
      | System                                               |
    And the following should not exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) |
      | Draft, not saved yet                                 |
    When I am on the "tool_imagepicker > Demo" page
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    And the crop selection should cover approximately "0 / 0 / 0.5 / 0.5"
    And I should see "The original image is preserved on the server" in the ".tool-imagepicker-crop-modal" "css_element"
    When I set the crop selection to "0.5 / 0.5 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "200 x 150"
    And "1" preserved original should exist
    When I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Last crop region              |
      | at 200 / 150 px from top left |

  Scenario: Originals: The first crop of a saved image preserves the original at the place of the image, not with the draft
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then "1" preserved original should exist
    And the preserved original should not be placed in "user / draft / *"
    And the preserved original should not be stored in the user context
    And the preserved original should be placed in "tool_imagepicker / demo / 1"
    And the preserved original should be stored in the system context
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "200 x 150"
    And "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"

  Scenario: Originals: Disabling the preservation afterwards makes the next crop start from the cropped image
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
      | Cropped image status |
      | In use               |
    When I am on the "tool_imagepicker > Demo" page
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "200 x 150" pixels
    And I should not see "The original image is preserved on the server" in the ".tool-imagepicker-crop-modal" "css_element"
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then the image of "imagepicker" should have the dimensions "100 x 75"
    And "1" preserved original should exist
    When the draft files of "admin" are cleaned up
    And I run the scheduled task "\tool_imagepicker\task\cleanup_originals"
    Then "0" preserved originals should exist

  Scenario: Originals: Removing the image from the field orphans its original, which the scheduled task removes
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
    Then I should see "Changes saved"
    And the image of "imagepicker" should not exist
    When I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Original image status | Cropped image status |
      | In place              | In use               |
    When the draft files of "admin" are cleaned up
    And I reload the page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Original image status | Cropped image status |
      | In place              | Orphaned             |
    When I run the scheduled task "\tool_imagepicker\task\cleanup_originals"
    And I reload the page
    Then I should see "There are no preserved originals on this site at the moment"
    And "0" preserved originals should exist

  Scenario: Originals: A GIF which was cropped into a PNG is cropped from its GIF original again
    Given I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.gif" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    Then I should see "This image will be saved as PNG after cropping" in the ".tool-imagepicker-crop-modal" "css_element"
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then the image of "imagepicker" should have the file name "image.png"
    When I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    And I should see "This image will be saved as PNG after cropping" in the ".tool-imagepicker-crop-modal" "css_element"
    When I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Original image status | Cropped image status |
      | .gif                  | .png                 |

  Scenario: Originals: Replacing the image by another upload preserves a second original and orphans the first one
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    And I delete "image.png" from "Image" filemanager
    And I upload "admin/tool/imagepicker/tests/fixtures/image.jpg" file to "Image" filemanager
    And I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And "2" preserved originals should exist
    When the draft files of "admin" are cleaned up
    And I run the scheduled task "\tool_imagepicker\task\cleanup_originals"
    Then "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"

  Scenario: Originals: The same image in both fields gets an original per field
    Given I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image (16:9)" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I open the crop modal of "imagepickerratio"
    And I set the crop selection to "0 / 0 / 0.8 / 0.6"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And "2" preserved originals should exist
    When I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) |
      | tool_imagepicker / demo / 1                          |
      | tool_imagepicker / demo / 2                          |

  Scenario: Originals: Deleting the original in the report makes the next crop start from the cropped image and preserve it anew
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
    And I click on "Delete" "button" in the "Delete original" "dialogue"
    Then I should see "The preserved original was deleted."
    And "0" preserved originals should exist
    When I am on the "tool_imagepicker > Demo" page
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "200 x 150" pixels
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"

  Scenario: Originals: Another user who may edit the image crops it from the original which the first user has preserved
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | usera    | User      | A        | usera@example.com    |
      | userb    | User      | B        | userb@example.com    |
    And the following "roles" exist:
      | shortname  | name        |
      | demoaccess | Demo access |
    And the following "role capabilities" exist:
      | role       | moodle/site:config |
      | demoaccess | allow              |
    And the following "role assigns" exist:
      | user  | role       | contextlevel | reference |
      | usera | demoaccess | System       |           |
      | userb | demoaccess | System       |           |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I log out
    And I am on the "tool_imagepicker > Demo" page logged in as "usera"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "200 x 150"
    When I log out
    And I am on the "tool_imagepicker > Demo" page logged in as "userb"
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    And the crop selection should cover approximately "0 / 0 / 0.5 / 0.5"
    When I set the crop selection to "0 / 0 / 0.25 / 0.25"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "100 x 75"
    And "1" preserved original should exist

  Scenario: Originals: A fresh upload is cropped twice in the same form session, both times from the original
    Given I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then "1" preserved original should exist
    And the preserved original should be placed in "user / draft / *"
    When I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    And the crop selection should cover approximately "0 / 0 / 0.5 / 0.5"
    When I set the crop selection to "0.5 / 0.5 / 0.5 / 0.5"
    And I save the crop
    Then "1" preserved original should exist
    And the preserved original should be placed in "user / draft / *"
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "200 x 150"
    And "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"
    When I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Last crop region              |
      | at 200 / 150 px from top left |

  Scenario: Originals: A saved image is cropped twice in the same form session, both times from the original
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    And the crop selection should cover approximately "0 / 0 / 0.5 / 0.5"
    When I set the crop selection to "0 / 0 / 0.25 / 0.25"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "100 x 75"
    And "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"

  Scenario: Originals: A crop of a fresh upload which is never saved leaves a draft-placed original which goes with the draft
    Given I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) | Original image status | Cropped image status |
      | Draft, not saved yet                                 | In place              | In use               |
    And the image of "imagepicker" should not exist
    When the draft files of "admin" are cleaned up
    And I reload the page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Original image status | Cropped image status |
      | In place              | Orphaned             |
    When I run the scheduled task "\tool_imagepicker\task\cleanup_originals"
    And I reload the page
    Then I should see "There are no preserved originals on this site at the moment"
    And "0" preserved originals should exist

  Scenario: Originals: A crop of a saved image which is never saved leaves an original which goes with the draft
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I am on the "tool_imagepicker > Report" page
    Then the image of "imagepicker" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/image.png"
    And "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"
    And the following should exist in the "tool_imagepicker_originals" table:
      | Original image status | Cropped image status |
      | In place              | In use               |
    When the draft files of "admin" are cleaned up
    And I reload the page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Original image status | Cropped image status |
      | In place              | Orphaned             |
    When I run the scheduled task "\tool_imagepicker\task\cleanup_originals"
    Then "0" preserved originals should exist
    # The saved image is untouched, so the next crop starts from it and preserves it anew.
    When I am on the "tool_imagepicker > Demo" page
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"

  Scenario: Originals: The field with a fixed aspect ratio restores the last crop region from the original
    Given I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image (16:9)" filemanager
    When I open the crop modal of "imagepickerratio"
    And I set the crop selection to "0 / 0 / 0.8 / 0.6"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepickerratio" should have the dimensions "320 x 180"
    When I open the crop modal of "imagepickerratio"
    Then the cropper should show an image of "400 x 300" pixels
    And the crop selection should cover approximately "0 / 0 / 0.8 / 0.6"
    And the crop selection should have the aspect ratio "16:9"
    When I set the crop selection to "0.2 / 0.4 / 0.8 / 0.6"
    Then the crop selection should cover approximately "0.2 / 0.4 / 0.8 / 0.6"
    And the crop selection should have the aspect ratio "16:9"
    When I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepickerratio" should have the dimensions "320 x 180"
    And "1" preserved original should exist
    When I am on the "tool_imagepicker > Report" page
    Then the following should exist in the "tool_imagepicker_originals" table:
      | Place of the image (component / file area / item id) | Last crop region             |
      | tool_imagepicker / demo / 2                          | at 80 / 120 px from top left |

  Scenario: Originals: Re-enabling the preservation makes an original which is still in place usable again
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
    And I am on the "tool_imagepicker > Demo" page
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "200 x 150" pixels
    When I click on "Cancel" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    And the following config values are set as admin:
      | preserveoriginals | 1 | tool_imagepicker |
    And I am on the "tool_imagepicker > Demo" page
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    And the crop selection should cover approximately "0 / 0 / 0.5 / 0.5"
    And "1" preserved original should exist

  # A preserved original is served to admins and to users who are editing the image which it backs, which they prove by
  # holding a draft copy of the image which was prepared from its place. Everybody else is refused, even if they are logged
  # in.
  Scenario: Originals: A preserved original is only served to admins and to users who are editing its image
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | usera    | User      | A        | usera@example.com    |
      | userc    | User      | C        | userc@example.com    |
    And the following "roles" exist:
      | shortname  | name        |
      | demoaccess | Demo access |
    And the following "role capabilities" exist:
      | role       | moodle/site:config |
      | demoaccess | allow              |
    And the following "role assigns" exist:
      | user  | role       | contextlevel | reference |
      | usera | demoaccess | System       |           |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I log out
    And I am on the "tool_imagepicker > Demo" page logged in as "usera"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And "1" preserved original should exist
    # The user who is editing the image (and still holds their draft copy) may read the original.
    And the preserved original should be served to me
    # A user who is not editing the image may not, even though they are logged in.
    When I log out
    And I log in as "userc"
    Then the preserved original should not be served to me
    # An admin may read every original.
    When I log out
    And I log in as "admin"
    Then the preserved original should be served to me
