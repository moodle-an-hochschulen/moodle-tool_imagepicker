@tool @tool_imagepicker @javascript @_file_upload
Feature: Cropping an image which has just been uploaded into the image picker
  In order to store exactly the part of an image which I want
  As a user of a form with an image picker
  I need to crop a freshly uploaded image before the form is saved

  Background:
    Given the following config values are set as admin:
      | preserveoriginals | 0 | tool_imagepicker |
    And I am on the "tool_imagepicker > Demo" page logged in as "admin"

  Scenario: Crop fresh: A freshly uploaded image is cropped and stored in its cropped form when the form is saved
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I click on the crop button of "imagepicker"
    Then the crop modal should be open
    And I should see "Crop image" in the ".tool-imagepicker-crop-modal .modal-title" "css_element"
    And the cropper should be ready
    # A language string which the JavaScript fetches but which does not exist is rendered as its key in double square
    # brackets, so this catches a missing string in the crop modal.
    And I should not see "[["
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    Then the crop selection should cover approximately "0 / 0 / 0.5 / 0.5"
    When I save the crop
    Then I should see "image.png" in the "#fitem_id_imagepicker .fp-content" "css_element"
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "image.png"
    And the image of "imagepicker" should have the dimensions "200 x 150"

  Scenario: Crop fresh: Leaving the crop modal with the Cancel button keeps the image as it is
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I click on "Cancel" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/image.png"

  Scenario: Crop fresh: Leaving the crop modal with the close button in its header keeps the image as it is
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    # The close button of a core modal carries nothing but an aria-label, which the "button" selector does not look at.
    And I click on ".tool-imagepicker-crop-modal button[aria-label='Close']" "css_element"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/image.png"

  Scenario: Crop fresh: Leaving the crop modal with the Escape key keeps the image as it is
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I press the escape key
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/image.png"

  Scenario: Crop fresh: A crop which is not followed by saving the form is not stored
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I am on the "tool_imagepicker > Demo" page
    Then the image of "imagepicker" should not exist
    And the crop button of "imagepicker" should be hidden

  Scenario: Crop fresh: The field with a fixed aspect ratio keeps the crop selection at 16:9
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image (16:9)" filemanager
    When I open the crop modal of "imagepickerratio"
    Then the crop selection should have the aspect ratio "16:9"
    When I set the crop selection to "0 / 0 / 0.8 / 0.6"
    Then the crop selection should cover approximately "0 / 0 / 0.8 / 0.6"
    And the crop selection should have the aspect ratio "16:9"
    When I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepickerratio" should have the dimensions "320 x 180"

  Scenario Outline: Crop fresh: Cropping keeps the file format where a browser can write it and changes it to PNG otherwise
    Given I upload "admin/tool/imagepicker/tests/fixtures/<file>" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    Then I <formatnotice> see "This image will be saved as PNG after cropping" in the ".tool-imagepicker-crop-modal" "css_element"
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then I should see "<result>" in the "#fitem_id_imagepicker .fp-content" "css_element"
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "<result>"
    And the image of "imagepicker" should have the dimensions "200 x 150"

    Examples:
      | file       | formatnotice | result     |
      | image.jpg  | should not   | image.jpg  |
      | image.jpeg | should not   | image.jpeg |
      | image.jpe  | should not   | image.jpe  |
      | image.png  | should not   | image.png  |
      | image.webp | should not   | image.webp |
      | image.gif  | should       | image.png  |
      | sized.svg  | should       | sized.png  |
      # A file extension in capital letters is kept as it is.
      | upper.JPG  | should not   | upper.JPG  |

  Scenario Outline: Crop fresh: An animated image is cropped from a still of its first frame
    Given I upload "admin/tool/imagepicker/tests/fixtures/<file>" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    Then I should see "This image is animated. Only its first frame will be kept after cropping." in the ".tool-imagepicker-crop-modal" "css_element"
    And I <formatnotice> see "This image will be saved as PNG after cropping" in the ".tool-imagepicker-crop-modal" "css_element"
    And I should not see "[["
    And the cropper image should be a still
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "<result>"
    And the image of "imagepicker" should have the dimensions "200 x 150"

    Examples:
      | file          | formatnotice | result        |
      | animated.gif  | should       | animated.png  |
      | animated.png  | should not   | animated.png  |
      | animated.webp | should not   | animated.webp |
      | animated.svg  | should       | animated.png  |

  Scenario: Crop fresh: A static image is cropped from the served file itself
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.gif" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    Then I should not see "This image is animated" in the ".tool-imagepicker-crop-modal" "css_element"
    And the cropper image should not be a still

  Scenario: Crop fresh: An image which the browser cannot load cannot be cropped
    Given I upload "admin/tool/imagepicker/tests/fixtures/broken.svg" file to "Image" filemanager
    When I click on the crop button of "imagepicker"
    Then I should see "The image could not be loaded from the server, so it cannot be cropped."
    And the crop modal should be closed
    When I click on "OK" "button" in the ".modal.show" "css_element"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/broken.svg"

  Scenario: Crop fresh: Both fields of a form are cropped independently of each other
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    And I upload "admin/tool/imagepicker/tests/fixtures/image.jpg" file to "Image (16:9)" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I open the crop modal of "imagepickerratio"
    And I set the crop selection to "0 / 0 / 0.4 / 0.3"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "image.png"
    And the image of "imagepicker" should have the dimensions "200 x 150"
    And the image of "imagepickerratio" should have the file name "image.jpg"
    And the image of "imagepickerratio" should have the dimensions "160 x 90"

  Scenario: Crop fresh: The crop selection survives a resize of the browser window
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0.25 / 0.25 / 0.5 / 0.5"
    And I change window size to "800x600"
    And I wait "1" seconds
    Then the crop selection should cover approximately "0.25 / 0.25 / 0.5 / 0.5"
    When I change window size to "large"
    And I wait "1" seconds
    Then the crop selection should cover approximately "0.25 / 0.25 / 0.5 / 0.5"
    When I save the crop
    And I press "Save changes"
    Then the image of "imagepicker" should have the dimensions "200 x 150"

  Scenario: Crop fresh: A small image is shown at its natural size in the crop modal instead of being blown up
    Given I upload "admin/tool/imagepicker/tests/fixtures/small.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    Then the cropper should show an image of "100 x 60" pixels
    And the cropper canvas should be "100" pixels wide

  Scenario Outline: Crop fresh: A crop selection which is moved beyond the image stops at the edge of the image
    Given I upload "admin/tool/imagepicker/tests/fixtures/<file>" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0.25 / 0.25 / 0.5 / 0.5"
    And I move the crop selection by "<move>" pixels
    Then the crop selection should cover approximately "<region>"
    When I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "<dimensions>"
    And the image of "imagepicker" should have no empty edge

    Examples:
      | file      | move          | region                  | dimensions |
      | large.png | -5000 / 0     | 0 / 0.25 / 0.5 / 0.5    | 300 x 225  |
      | large.png | 5000 / 0      | 0.5 / 0.25 / 0.5 / 0.5  | 300 x 225  |
      | large.png | 0 / -5000     | 0.25 / 0 / 0.5 / 0.5    | 300 x 225  |
      | large.png | 0 / 5000      | 0.25 / 0.5 / 0.5 / 0.5  | 300 x 225  |
      | large.png | -5000 / -5000 | 0 / 0 / 0.5 / 0.5       | 300 x 225  |
      # A JPEG image, in which an area beyond the image would come out black rather than transparent. It is a smaller one
      # than large.jpg, which exceeds the upload limit of 2 MB that PHP has by default on Github actions.
      | noisy.jpg | -5000 / 5000  | 0 / 0.5 / 0.5 / 0.5     | 300 x 225  |

  Scenario: Crop fresh: A crop selection which is resized beyond the image is cut off at the edge of the image
    Given I upload "admin/tool/imagepicker/tests/fixtures/large.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0.25 / 0.25 / 0.5 / 0.5"
    And I drag the "east" handle of the crop selection by "5000 / 0" pixels
    Then the crop selection should cover approximately "0.25 / 0.25 / 0.75 / 0.5"
    When I drag the "southeast" handle of the crop selection by "5000 / 5000" pixels
    Then the crop selection should cover approximately "0.25 / 0.25 / 0.75 / 0.75"
    When I drag the "northwest" handle of the crop selection by "-5000 / -5000" pixels
    Then the crop selection should cover approximately "0 / 0 / 1 / 1"
    When I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "600 x 450"
    And the image of "imagepicker" should have no empty edge

  Scenario: Crop fresh: With a fixed aspect ratio, a crop selection which is resized or moved beyond the image stops at the edge and keeps its ratio
    Given I upload "admin/tool/imagepicker/tests/fixtures/large.png" file to "Image (16:9)" filemanager
    When I open the crop modal of "imagepickerratio"
    And I set the crop selection to "0.25 / 0.25 / 0.5 / 0.375"
    # The east handle resizes the selection around its vertical centre, until its right edge reaches the edge of the image.
    And I drag the "east" handle of the crop selection by "5000 / 0" pixels
    Then the crop selection should cover approximately "0.25 / 0.156 / 0.75 / 0.563"
    And the crop selection should have the aspect ratio "16:9"
    When I move the crop selection by "5000 / 5000" pixels
    Then the crop selection should cover approximately "0.25 / 0.437 / 0.75 / 0.563"
    And the crop selection should have the aspect ratio "16:9"
    # Pushing against the edges once more neither moves nor shrinks the selection.
    When I drag the "southeast" handle of the crop selection by "5000 / 5000" pixels
    Then the crop selection should cover approximately "0.25 / 0.437 / 0.75 / 0.563"
    When I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepickerratio" should have the dimensions "450 x 253"
    And the image of "imagepickerratio" should have no empty edge

  Scenario: Crop fresh: A freshly uploaded image is cropped twice in the same form session, the second time from its cropped version
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    Then the cropper should show an image of "400 x 300" pixels
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I open the crop modal of "imagepicker"
    Then the cropper should show an image of "200 x 150" pixels
    When I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then I should see "image.png" in the "#fitem_id_imagepicker .fp-content" "css_element"
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "image.png"
    And the image of "imagepicker" should have the dimensions "100 x 75"

  Scenario: Crop fresh: Cropping is refused if the image has disappeared from the draft area in the meantime
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    # The draft area is emptied behind the back of the page, which still shows the image and offers to crop it.
    And the draft files of "admin" are cleaned up
    When I click on the crop button of "imagepicker"
    Then I should see "Please add an image before cropping it."
    And the crop modal should be closed
    When I click on "OK" "button" in the ".modal.show" "css_element"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should not exist

  Scenario: Crop fresh: A second click on the crop button while the crop modal is opening does not open a second modal
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I click on the crop button of "imagepicker" twice in quick succession
    Then there should be exactly one crop modal
    When I click on "Cancel" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then the crop modal should be closed

  Scenario: Crop fresh: The save button of the crop modal shows a saving state while the crop is stored
    Given I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    Then clicking the save button of the crop modal should show its saving state
    And the crop modal should be closed
    And I should see "image.png" in the "#fitem_id_imagepicker .fp-content" "css_element"
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "200 x 150"

  # After a crop, the plugin makes the core file manager reload its file list, so that its thumbnail and its file dialog
  # act on the cropped file (which may have another name than the file it was cropped from) rather than on a file which no
  # longer exists.
  Scenario: Crop fresh: After a crop, the thumbnail and the file dialog of the file manager show the cropped file
    Given the following "user preferences" exist:
      | user  | preference                 | value |
      | admin | filemanager_recentviewmode | 1     |
    And I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.gif" file to "Image" filemanager
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    Then I should see "image.png" in the "#fitem_id_imagepicker .fp-content" "css_element"
    And "#fitem_id_imagepicker .fp-content img[src*='image.png']" "css_element" should exist
    When I open the file dialog of "imagepicker"
    Then the file dialog should show the file name "image.png"
    When I close the file dialog
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "image.png"
