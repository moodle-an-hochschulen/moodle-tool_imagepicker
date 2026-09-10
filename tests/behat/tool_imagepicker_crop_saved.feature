@tool @tool_imagepicker @javascript @_file_upload
Feature: Cropping an image which was saved in the image picker before, without preserved originals
  In order to adjust an image which is already in use
  As a user of a form with an image picker
  I need to crop a saved image, which replaces it as the site does not preserve originals

  Background:
    Given the following config values are set as admin:
      | preserveoriginals | 0 | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "admin"

  Scenario: Crop saved: A saved image is cropped and the cropped version replaces it
    When I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    And I should not see "The original image is preserved on the server" in the ".tool-imagepicker-crop-modal" "css_element"
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "image.png"
    And the image of "imagepicker" should have the dimensions "200 x 150"
    And "0" preserved originals should exist

  Scenario: Crop saved: An image which was cropped before is cropped again from its cropped version
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "200 x 150" pixels
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "100 x 75"
    And "0" preserved originals should exist

  Scenario: Crop saved: Cancelling the crop of a saved image keeps the image as it is
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I click on "Cancel" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/image.png"

  Scenario: Crop saved: A saved GIF becomes a PNG when it is cropped
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.gif |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    Then I should see "This image will be saved as PNG after cropping" in the ".tool-imagepicker-crop-modal" "css_element"
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then I should see "image.png" in the "#fitem_id_imagepicker .fp-content" "css_element"
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "image.png"
    And the image of "imagepicker" should have the dimensions "200 x 150"

  Scenario: Crop saved: A saved image is replaced by a new upload which is cropped in turn
    When I delete "image.png" from "Image" filemanager
    And I upload "admin/tool/imagepicker/tests/fixtures/image.jpg" file to "Image" filemanager
    And I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "image.jpg"
    And the image of "imagepicker" should have the dimensions "200 x 150"

  Scenario: Crop saved: A saved image is cropped twice in the same form session before the form is saved
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "200 x 150" pixels
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "100 x 75"
    And "0" preserved originals should exist

  Scenario: Crop saved: A crop which is not followed by saving the form leaves the saved image as it is
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I am on the "tool_imagepicker > Demo" page
    Then the image of "imagepicker" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/image.png"
    And I should see "image.png" in the "#fitem_id_imagepicker .fp-content" "css_element"

  Scenario: Crop saved: A crop marks the form as changed
    Then the demo form should not be marked as changed
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then the demo form should be marked as changed

  Scenario: Crop saved: The cropped image keeps the author and the licence of the image it was cropped from
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
      | author   | Jane Doe                                        |
      | license  | public                                          |
    And the following "user preferences" exist:
      | user  | preference                 | value |
      | admin | filemanager_recentviewmode | 1     |
    And I am on the "tool_imagepicker > Demo" page
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I open the file dialog of "imagepicker"
    Then the file dialog should show the file name "image.png"
    And the file dialog should show the author "Jane Doe"
    And the file dialog should show the licence "Public domain"
    When I close the file dialog
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "200 x 150"
    And the image of "imagepicker" should have the author "Jane Doe" and the licence "public"
