@tool @tool_imagepicker
Feature: Configuring the image picker and reaching its demo and report pages
  In order to configure the image picker, try it out and inspect what it preserves
  As an admin
  I need to use the settings page of the plugin and the pages which it links to

  Scenario: Settings: The settings page offers the settings and the buttons which lead to the demo page and the report
    Given I am on the "tool_imagepicker > Settings" page logged in as "admin"
    Then I should see "Preserve original images"
    And I should see "Image quality after cropping"
    And I should see "Strategy when the file size limit is exceeded"
    And "View preserved originals" "link" should exist
    And "View demo page" "link" should exist

  Scenario: Settings: The image quality offers the defined steps down to 50 percent and defaults to 92 percent
    Given I am on the "tool_imagepicker > Settings" page logged in as "admin"
    Then the field "Image quality after cropping" matches value "92 %"
    And the "Image quality after cropping" select box should contain "100 %"
    And the "Image quality after cropping" select box should contain "95 %"
    And the "Image quality after cropping" select box should contain "50 %"
    And the "Image quality after cropping" select box should not contain "45 %"
    And the "Image quality after cropping" select box should not contain "30 %"
    When I set the field "Image quality after cropping" to "50 %"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the field "Image quality after cropping" matches value "50 %"

  Scenario: Settings: The size limit strategy defaults to reducing the pixel size and explains the quality strategy
    Given I am on the "tool_imagepicker > Settings" page logged in as "admin"
    Then the field "Strategy when the file size limit is exceeded" matches value "Reduce pixel size"
    And the "Strategy when the file size limit is exceeded" select box should contain "Reduce quality first"
    And I should see "down to 50 %"
    And I should see "a PNG image is always scaled down"
    When I set the field "Strategy when the file size limit is exceeded" to "Reduce quality first"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the field "Strategy when the file size limit is exceeded" matches value "Reduce quality first"

  Scenario: Settings: The demo page is reached from the settings page and its breadcrumb leads back to the settings page
    Given I am on the "tool_imagepicker > Settings" page logged in as "admin"
    When I click on "View demo page" "link"
    Then I should see "Image picker demo"
    And I should see "This page shows the ways in which the image picker performs in a form"
    And "Admin tools" "link" should exist in the ".breadcrumb" "css_element"
    And "Image picker" "link" should exist in the ".breadcrumb" "css_element"
    And "Image picker demo" "text" should exist in the ".breadcrumb" "css_element"
    When I click on "Image picker" "link" in the ".breadcrumb" "css_element"
    Then I should see "Preserve original images"
    And "View demo page" "link" should exist

  Scenario: Settings: The report is reached from the settings page and its breadcrumb leads back to the settings page
    Given I am on the "tool_imagepicker > Settings" page logged in as "admin"
    When I click on "View preserved originals" "link"
    Then I should see "Preserved originals"
    And I should see "This report lists the original (uncropped) images which the image picker preserves"
    And "Admin tools" "link" should exist in the ".breadcrumb" "css_element"
    And "Image picker" "link" should exist in the ".breadcrumb" "css_element"
    And "Preserved originals" "text" should exist in the ".breadcrumb" "css_element"
    When I click on "Image picker" "link" in the ".breadcrumb" "css_element"
    Then I should see "Preserve original images"
    And "View preserved originals" "link" should exist

  Scenario: Settings: The demo page and the report are hidden from the administration tree
    Given I log in as "admin"
    When I navigate to "Plugins" in site administration
    Then I should see "Image picker"
    And I should not see "Image picker demo"
    And I should not see "Preserved originals"

  # The pages answer with an error page, which the Behat hooks of Moodle would report as a failure if the browser was
  # sent there, so the pages are fetched from within the browser instead.
  @javascript
  Scenario: Settings: The demo page and the report are for site administrators only
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | user1    | User      | One      | user1@example.com |
    When I log in as "user1"
    Then I should be refused access to the "tool_imagepicker > Demo" page
    And I should be refused access to the "tool_imagepicker > Report" page

  @javascript @_file_upload
  Scenario: Settings: The preservation of originals is switched on through the settings page
    Given I am on the "tool_imagepicker > Settings" page logged in as "admin"
    And the field "Preserve original images" matches value "0"
    When I set the field "Preserve original images" to "1"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the field "Preserve original images" matches value "1"
    When I am on the "tool_imagepicker > Demo" page
    And I upload "admin/tool/imagepicker/tests/fixtures/image.png" file to "Image" filemanager
    And I open the crop modal of "imagepicker"
    Then I should see "The original image is preserved on the server" in the ".tool-imagepicker-crop-modal" "css_element"
