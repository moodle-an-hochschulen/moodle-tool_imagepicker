@tool @tool_imagepicker @javascript @_file_upload
Feature: The crop button of the image picker appears only once a croppable image has been added
  In order to crop an image
  As a user of a form with an image picker
  I need the crop button to be offered exactly when the field holds an image which can be cropped

  Background:
    Given I log in as "admin"

  Scenario: Crop button: It appears after an image has been uploaded and disappears again when the image is removed
    Given I am on the "tool_imagepicker > Demo" page
    Then the crop button of "imagepicker" should be hidden
    When I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    Then the crop button of "imagepicker" should be visible
    And "#fitem_id_imagepicker .fp-btn-add + .tool-imagepicker-btn-crop" "css_element" should exist
    And "#fitem_id_imagepicker .tool-imagepicker-btn-crop a[title='Crop image']" "css_element" should exist
    When I delete "image.png" from "Image" filemanager
    Then the crop button of "imagepicker" should be hidden
    When I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    Then the crop button of "imagepicker" should be visible

  Scenario Outline: Crop button: It appears for every image type which the field accepts
    Given I am on the "tool_imagepicker > Demo" page
    When I upload "admin/tool/imagepicker/tests/fixtures/<file>" file to "Image" filemanager
    Then the crop button of "imagepicker" should be visible

    Examples:
      | file         |
      | image.jpg    |
      | image.jpeg   |
      | image.jpe    |
      | image.png    |
      | image.gif    |
      | image.webp   |
      | sized.svg    |
      | viewbox.svg  |
      | animated.gif |
      | upper.JPG    |

  # The first demo field was created with all file types allowed, so this shows that the element narrows the accepted file
  # types down to web images on its own. The file picker reports the refused file in an alert whose title and wording differ
  # between Moodle versions, so the alert is addressed by its class and only the word which every wording shares is checked.
  Scenario: Crop button: A file which is not a web image is refused by the field, even if the field was created with all file types allowed
    Given I am on the "tool_imagepicker > Demo" page
    When I upload "admin/tool/imagepicker/tests/fixtures/notanimage.txt" file to "Image" filemanager
    Then I should see "accepted" in the ".moodle-dialogue-confirm" "css_element"
    When I click on "OK" "button" in the ".moodle-dialogue-confirm" "css_element"
    Then "#fitem_id_imagepicker .filemanager.fm-nofiles" "css_element" should exist
    And the crop button of "imagepicker" should be hidden

  Scenario: Crop button: It is visible right away for an image which was saved before
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.png |
    When I am on the "tool_imagepicker > Demo" page
    Then I should see "image.png" in the "#fitem_id_imagepicker" "css_element"
    And the crop button of "imagepicker" should be visible

  Scenario: Crop button: It stays hidden for an image which a browser cannot display
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                      |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.svgz |
    When I am on the "tool_imagepicker > Demo" page
    Then I should see "image.svgz" in the "#fitem_id_imagepicker" "css_element"
    And the crop button of "imagepicker" should be hidden
    When I delete "image.svgz" from "Image" filemanager
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    Then the crop button of "imagepicker" should be visible

  Scenario: Crop button: The field holds a single file and offers no folders, whatever it was created with
    Given I am on the "tool_imagepicker > Demo" page
    Then "#fitem_id_imagepicker .fp-btn-mkdir" "css_element" should not be visible
    And "#fitem_id_imagepicker .fp-btn-add" "css_element" should be visible
    When I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    Then "#fitem_id_imagepicker .fp-btn-add" "css_element" should not be visible
    And "#fitem_id_imagepicker .fp-btn-mkdir" "css_element" should not be visible

  Scenario Outline: Crop button: It works in every view mode of the file manager
    Given the following "user preferences" exist:
      | user  | preference                 | value      |
      | admin | filemanager_recentviewmode | <viewmode> |
    And I am on the "tool_imagepicker > Demo" page
    When I upload "admin/tool/imagepicker/tests/fixtures/image.gif" file to "Image" filemanager
    Then the crop button of "imagepicker" should be visible
    When I open the crop modal of "imagepicker"
    And I save the crop
    Then I should see "image.png" in the "#fitem_id_imagepicker .fp-content" "css_element"

    Examples:
      | viewmode |
      | 1        |
      | 2        |
      | 3        |

  Scenario: Crop button: A field which was created with some web image types refuses the other web image types
    Given I am on the "tool_imagepicker > Demo" page
    When I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image (JPEG only)" filemanager
    Then I should see "accepted" in the ".moodle-dialogue-confirm" "css_element"
    When I click on "OK" "button" in the ".moodle-dialogue-confirm" "css_element"
    Then "#fitem_id_imagepickerjpeg .filemanager.fm-nofiles" "css_element" should exist
    And the crop button of "imagepickerjpeg" should be hidden

  Scenario: Crop button: A field which was created with some web image types accepts and crops those
    Given I am on the "tool_imagepicker > Demo" page
    When I upload "admin/tool/imagepicker/tests/fixtures/image.jpg" file to "Image (JPEG only)" filemanager
    Then I should see "image.jpg" in the "#fitem_id_imagepickerjpeg .fp-content" "css_element"
    And the crop button of "imagepickerjpeg" should be visible
    When I open the crop modal of "imagepickerjpeg"
    And I set the crop selection to "0 / 0 / 0.5 / 0.5"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepickerjpeg" should have the file name "image.jpg"
    And the image of "imagepickerjpeg" should have the dimensions "200 x 150"

  # A GIF is cropped into a PNG, so cropping a GIF in a field which does not accept PNG images would yield an image which
  # the field cannot store. Such an image can only sit in the field if it was put there before the field was handled by the
  # element, which is what the generator does here.
  Scenario: Crop button: It stays hidden for an image whose cropped result the field would not accept
    Given the following "tool_imagepicker > image" exists:
      | field    | imagepickerjpeg                                 |
      | filepath | admin/tool/imagepicker/tests/fixtures/image.gif |
    When I am on the "tool_imagepicker > Demo" page
    Then I should see "image.gif" in the "#fitem_id_imagepickerjpeg .fp-content" "css_element"
    And the crop button of "imagepickerjpeg" should be hidden
    When I delete "image.gif" from "Image (JPEG only)" filemanager
    And I upload "admin/tool/imagepicker/tests/fixtures/image.jpg" file to "Image (JPEG only)" filemanager
    Then the crop button of "imagepickerjpeg" should be visible

  Scenario: Crop button: A field which was created without cropping has no crop button at all
    # Moodle re-encodes a JPEG image on its own when it is stored, where its EXIF remover is in charge: in Moodle 5.0 and 5.1
    # that is every JPEG image, later on only those which carry EXIF data. The stored image could not be compared with the
    # uploaded one then, so the EXIF remover is switched off here.
    Given the following config values are set as admin:
      | file_redactor_exifremoverenabled | 0 |
    And I am on the "tool_imagepicker > Demo" page
    When I upload "admin/tool/imagepicker/tests/fixtures/image.jpg" file to "Image (without cropping)" filemanager
    Then I should see "image.jpg" in the "#fitem_id_imagepickernocrop .fp-content" "css_element"
    And the crop button of "imagepickernocrop" should be missing
    # It is still an image picker field, though: it holds a single file and offers no folders.
    And "#fitem_id_imagepickernocrop .fp-btn-add" "css_element" should not be visible
    And "#fitem_id_imagepickernocrop .fp-btn-mkdir" "css_element" should not be visible
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepickernocrop" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/image.jpg"
