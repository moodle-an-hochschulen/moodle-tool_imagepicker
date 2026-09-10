@tool @tool_imagepicker @javascript
Feature: Handling a cropped image which exceeds the file size limit
  In order to store a cropped image within the file size limit of the site
  As a user of a form with an image picker who is subject to that limit
  I need to be told about the problem and to have the image reduced according to the site settings

  # The demo page is for users with the site configuration capability, but a site admin is never subject to a file size
  # limit. The scenarios therefore run as a user who holds that capability through a role of their own and nothing else.
  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | demouser | Demo      | User     | demouser@example.com |
    And the following "roles" exist:
      | shortname  | name        |
      | demoaccess | Demo access |
    And the following "role capabilities" exist:
      | role       | moodle/site:config |
      | demoaccess | allow              |
    And the following "role assigns" exist:
      | user     | role       | contextlevel | reference |
      | demouser | demoaccess | System       |           |
    And the following config values are set as admin:
      | preserveoriginals | 0 | tool_imagepicker |

  Scenario: Size limit: With the pixel size strategy, the user may let the image be scaled down until it fits
    Given the following config values are set as admin:
      | maxbytes          | 204800    |                  |
      | sizelimitstrategy | scaledown | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then "Cropped image too large" "dialogue" should exist
    And I should see "200.0 KB" in the "Cropped image too large" "dialogue"
    And I should see "only its resolution is reduced" in the "Cropped image too large" "dialogue"
    And "Scale down image" "button" should exist in the "Cropped image too large" "dialogue"
    And "Back to cropping" "button" should exist in the "Cropped image too large" "dialogue"
    And I should not see "[["
    When I click on "Scale down image" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size below "204800" bytes
    And the image of "imagepicker" should have dimensions smaller than "600 x 450"

  Scenario: Size limit: The user may go back to cropping instead and crop a smaller area
    Given the following config values are set as admin:
      | maxbytes | 204800 |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    And I click on "Back to cropping" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be open
    And the cropper should be ready
    When I set the crop selection to "0 / 0 / 0.25 / 0.25"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size below "204800" bytes
    And the image of "imagepicker" should have the dimensions "150 x 112"

  Scenario: Size limit: Closing the size limit dialogue without a decision goes back to cropping as well
    Given the following config values are set as admin:
      | maxbytes | 204800 |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then "Cropped image too large" "dialogue" should exist
    When I press the escape key
    Then "Cropped image too large" "dialogue" should not exist
    And the crop modal should be open
    And the cropper should be ready
    When I set the crop selection to "0 / 0 / 0.25 / 0.25"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size below "204800" bytes
    And the image of "imagepicker" should have the dimensions "150 x 112"

  Scenario Outline: Size limit: With the quality strategy, a lossy image is reduced in quality and keeps its pixel size
    Given the following config values are set as admin:
      | maxbytes          | <maxbytes>   |                  |
      | sizelimitstrategy | lowerquality | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                  |
      | filepath | admin/tool/imagepicker/tests/fixtures/<file> |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then "Cropped image too large" "dialogue" should exist
    And I should see "only the compression is increased" in the "Cropped image too large" "dialogue"
    And "Reduce image quality" "button" should exist in the "Cropped image too large" "dialogue"
    When I click on "Reduce image quality" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size below "<maxbytes>" bytes
    And the image of "imagepicker" should have the dimensions "<width> x <height>"

    # The limits sit between the file size which the browser produces at the default quality and the one it produces at the
    # lowest quality, with room to spare in both directions, as browsers encode differently: Chrome encodes the JPEG at 92 %
    # into about 950 KB and at 50 % into about 410 KB, Firefox into more than 1 MB and about 440 KB. WebP comes out the same
    # in both (about 1.2 MB and 700 KB).
    Examples:
      | file       | maxbytes | width | height |
      | large.jpg  | 716800   | 1200  | 900    |
      | large.webp | 921600   | 1400  | 1050   |

  Scenario: Size limit: With the quality strategy, a PNG is scaled down nevertheless as it has no quality to reduce
    Given the following config values are set as admin:
      | maxbytes          | 204800       |                  |
      | sizelimitstrategy | lowerquality | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then "Cropped image too large" "dialogue" should exist
    And I should see "only its resolution is reduced" in the "Cropped image too large" "dialogue"
    And "Scale down image" "button" should exist in the "Cropped image too large" "dialogue"
    When I click on "Scale down image" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size below "204800" bytes
    And the image of "imagepicker" should have dimensions smaller than "600 x 450"

  Scenario: Size limit: With the quality strategy, the image is scaled down as well if even the lowest quality is not enough
    Given the following config values are set as admin:
      | maxbytes          | 204800       |                  |
      | sizelimitstrategy | lowerquality | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.jpg |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    And I click on "Reduce image quality" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size below "204800" bytes
    And the image of "imagepicker" should have dimensions smaller than "1200 x 900"

  Scenario: Size limit: With the quality strategy and the quality at its minimum already, the image is scaled down right away
    Given the following config values are set as admin:
      | maxbytes          | 307200       |                  |
      | sizelimitstrategy | lowerquality | tool_imagepicker |
      | encodingquality   | 50           | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.jpg |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    And I click on "Reduce image quality" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size below "307200" bytes
    And the image of "imagepicker" should have dimensions smaller than "1200 x 900"

  Scenario: Size limit: A limit which cannot be met by scaling down is reported, and the image is left as it is
    Given the following config values are set as admin:
      | maxbytes | 50 |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    And I click on "Scale down image" "button" in the "Cropped image too large" "dialogue"
    Then I should see "The cropped image cannot be scaled down far enough to fit into the maximum file size which is allowed here." in the "Cropped image too large" "dialogue"
    And I should not see "[["
    When I click on "OK" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be open
    And the cropper should be ready
    And the image of "imagepicker" should be identical to the fixture "admin/tool/imagepicker/tests/fixtures/large.png"
    When I click on "Cancel" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then the crop modal should be closed
    # The form itself does not keep a file which exceeds the site limit either, so saving it drops the image now.
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should not exist

  # A GIF is cropped into a PNG, and the PNG of a colourful GIF is a lot larger than the GIF. So a fresh upload which is
  # well within the limit can produce a cropped image which is not - which is the very case the size limit handling is
  # there for. The limit of a fresh upload is resolved differently from the limit of a saved image as well: the image lives
  # nowhere but in the draft area yet, so the limit is taken from the user context of the uploading user.
  @_file_upload
  Scenario: Size limit: A freshly uploaded GIF whose cropped PNG exceeds the limit is reduced as well
    Given the following config values are set as admin:
      | maxbytes          | 204800    |                  |
      | sizelimitstrategy | scaledown | tool_imagepicker |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I upload "admin/tool/imagepicker/tests/fixtures/noisy.gif" file to "Image" filemanager
    And I open the crop modal of "imagepicker"
    Then I should see "This image will be saved as PNG after cropping" in the ".tool-imagepicker-crop-modal" "css_element"
    When I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    Then "Cropped image too large" "dialogue" should exist
    And I should see "200.0 KB" in the "Cropped image too large" "dialogue"
    When I click on "Scale down image" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be closed
    And I should see "noisy.png" in the "#fitem_id_imagepicker .fp-content" "css_element"
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the file name "noisy.png"
    And the image of "imagepicker" should have a file size below "204800" bytes
    And the image of "imagepicker" should have dimensions smaller than "300 x 225"

  Scenario: Size limit: With preserved originals, the original is preserved at its full size while the reduced image is stored
    Given the following config values are set as admin:
      | maxbytes          | 204800    |                  |
      | sizelimitstrategy | scaledown | tool_imagepicker |
      | preserveoriginals | 1         | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "demouser"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I click on "Save" "button" in the ".tool-imagepicker-crop-modal" "css_element"
    And I click on "Scale down image" "button" in the "Cropped image too large" "dialogue"
    Then the crop modal should be closed
    When I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size below "204800" bytes
    And the image of "imagepicker" should have dimensions smaller than "600 x 450"
    And "1" preserved original should exist
    And the preserved original should be placed in "tool_imagepicker / demo / 1"
    When I open the crop modal of "imagepicker"
    Then the cropper should show an image of "600 x 450" pixels
    And the crop selection should cover approximately "0 / 0 / 1 / 1"

  Scenario: Size limit: An admin is not subject to the limit
    Given the following config values are set as admin:
      | maxbytes | 204800 |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "admin"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size above "204800" bytes
    And the image of "imagepicker" should have the dimensions "600 x 450"

  Scenario Outline: Quality setting: The encoding quality changes the file size of a lossy image
    Given the following config values are set as admin:
      | encodingquality | <quality> | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                  |
      | filepath | admin/tool/imagepicker/tests/fixtures/<file> |
    And I am on the "tool_imagepicker > Demo" page logged in as "admin"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have a file size <relation> "<bytes>" bytes

    Examples:
      | file       | quality | relation | bytes   |
      | large.jpg  | 100     | above    | 1048576 |
      | large.jpg  | 50      | below    | 614400  |
      | large.webp | 100     | above    | 1048576 |
      | large.webp | 50      | below    | 921600  |

  Scenario: Quality setting: The encoding quality does not affect a PNG
    Given the following config values are set as admin:
      | encodingquality | 100 | tool_imagepicker |
    And the following "tool_imagepicker > image" exists:
      | field    | imagepicker                                     |
      | filepath | admin/tool/imagepicker/tests/fixtures/large.png |
    And I am on the "tool_imagepicker > Demo" page logged in as "admin"
    When I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I save the crop
    And I press "Save changes"
    And I remember the file size of the image of "imagepicker"
    And the following config values are set as admin:
      | encodingquality | 50 | tool_imagepicker |
    And I am on the "tool_imagepicker > Demo" page
    And I open the crop modal of "imagepicker"
    And I set the crop selection to "0 / 0 / 1 / 1"
    And I save the crop
    And I press "Save changes"
    Then I should see "Changes saved"
    And the image of "imagepicker" should have the dimensions "600 x 450"
    And the image of "imagepicker" should have about the remembered file size
