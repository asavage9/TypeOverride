=== TypeOverride ===
Contributors: asavagecreative
Tags: elementor, typography, fonts, design system, maintenance
Requires at least: 6.8
Tested up to: 7.1
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and selectively reset local Elementor typography overrides so global typography can inherit correctly.

== Description ==

TypeOverride helps Elementor administrators find explicit local typography values that can override a site's broader typography settings. It provides a focused audit of Elementor document data and lets administrators selectively remove only the typography property groups they choose.

Reset values are cleared so Elementor can return those properties to its available Default or inherited behavior. TypeOverride protects Elementor Global Typography relationships, the active Elementor Kit, and typography properties that were not selected. Original Elementor document data is backed up before the first mutation for each document.

TypeOverride is an independent plugin. It is not affiliated with or endorsed by Elementor Ltd.

== Features ==

* Audit explicit local typography overrides in Elementor documents.
* Group audit results by document and show the current value.
* Identify registered responsive typography contexts such as Desktop, Tablet, and Mobile.
* Selectively reset nine local typography property groups.
* Protect Elementor Global Typography relationships.
* Protect the active Elementor Kit.
* Preserve unselected typography properties.
* Back up original Elementor document data before modification.
* Skip malformed Elementor data safely instead of overwriting it.

== Supported typography groups ==

* Font Family
* Font Size
* Font Weight
* Text Transform
* Font Style
* Text Decoration
* Line Height
* Letter Spacing
* Word Spacing

== Installation ==

1. Install and activate Elementor.
2. Install TypeOverride from the WordPress Plugins screen.
3. Activate TypeOverride.
4. Open TypeOverride from its top-level admin menu.
5. Run Audit before using Reset Overrides.

TypeOverride requires Elementor. WordPress will use the plugin dependency information where supported by the installed WordPress version.

== Usage ==

Use this workflow:

1. Run Audit.
2. Review the explicit local overrides grouped by document.
3. Open Reset Overrides and select one or more typography property groups.
4. Choose Review reset.
5. Confirm Reset selected overrides after reviewing the protected data boundaries.

Reset Overrides modifies Elementor document data. The first mutation creates an original-data backup for that document. Version 0.2.0 does not provide an automated Restore screen.

== Frequently Asked Questions ==

= Does TypeOverride change my Elementor Global Fonts? =

No. TypeOverride removes selected explicit local property values while protecting global typography relationships and the active Elementor Kit.

= Does TypeOverride replace one font with another? =

No. It clears selected local values so Elementor can use its available default or inherited value.

= Can I reset only Font Family? =

Yes. Select only Font Family. Unselected typography groups remain unchanged.

= Does TypeOverride support responsive typography? =

Yes. Registered Elementor responsive variants are recognized and reset as separate control values.

= Does TypeOverride back up Elementor data? =

Yes. Before the first successful mutation of a document, TypeOverride stores the original Elementor document data. Version 0.2.0 does not provide an automated Restore screen.

= Does TypeOverride require Elementor Pro? =

No. Core Audit and Reset Overrides operation is designed to work with Elementor. Do not treat this plugin as a replacement for Elementor Pro features.

== Screenshots ==

1. Audit summary and grouped document results.
2. Expanded typography override details with responsive context.
3. Reset Overrides category selection.
4. Review reset confirmation state.
5. Reset completion summary.

== Changelog ==

= 0.2.0 =
* Initial WordPress.org release candidate.
* Audit local Elementor typography overrides.
* Selectively reset nine typography property groups.
* Support responsive Elementor typography values.
* Protect global typography relationships and the active Elementor Kit.
* Back up original Elementor document data before modification.
