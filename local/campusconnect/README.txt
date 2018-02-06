CampusConnect Moodle plugin
===========================

Installation
============

Required components:
local_campusconnect.zip => local/campusconnect/ - this is the core components of the plugin
auth_campusconnect.zip => auth/campusconnect/ - allows students arriving via course links to authenticate on this site
enrol_campusconnect.zip => enrol/campusconnect/ - allows students to be enrolled via 'course_membership' resources from the CMS
block_campusconnect.zip => blocks/campusconnect/ - allows courses to be exported, as course links, to other sites
course.zip => course/ - a single file 'externservercourse.php' handles course link functionality (both internal and external)

Once all of the above components have been placed in the relevant folders, log into the site as an admin and visit the home page to install the latest database updates.

Enable the auth and enrol plugins by visiting the following pages and clicking on the 'unhide' icon beside 'CampusConnect':
Site admin > Plugins > Enrolment > Manage enrolment plugins
Site admin > Plugins > Authentications > Manage authentication plugins

Add the 'export course' block to a course then turn on editing to export see the export options (alternatively, follow the instructions here: http://docs.moodle.org/23/en/Block_settings#.27Sticky_blocks.27 to add the front page, then make available on all 'course' pages).

Usage
=====

Log in as site admin, then visit 'Site admin > CampusConnect' to access all of the CampusConnect settings.

Add a new ECS - 'Site admin > CampusConnect > ECS > All ECS', then 'Add New ECS'.

Manage sharing with other participants - 'Site admin > CampusConnect > Participants'.

Control the mapping of course/course link metadata to and from ECS and Moodle - 'Site admin > CampusConnect > ECS data mapping'.
Note, in each text field, you can insert a value from the metadata by typing the name of metadata in { }; you can also add anything you want outside of the { } and it will appear exactly as typed.
e.g. setting 'fullname' to '{title} - {id}' and importing a course with 'title' = 'Test title' and 'id' = '1234', would end up, in Moodle with the 'fullname' set to 'Test title - 1234'.

List course links imported from other VLEs - 'Site admin > CampusConnect > Imported courses'.

List course links exported to other VLEs - 'Site admin > CampusConnect > Released courses'.

Map directory trees from the CMS onto Moodle categories - 'Site admin > CampusConnect > Directory mapping'.

Map courses onto Moodle categories via filtering rules - 'Site admin > CampusConnect > Course filtering'. Note this will override any allocation via directory tree > category mappings.

Map roles specified in the CMS 'course membership' resources onto Moodle roles - 'Site admin > CampusConnect > Role mapping'. As a definitive list of CMS roles has not been produced, you will need to type in each of the roles to map.

Export a course link to other VLEs - add the 'export course' block to a course (or to the front page and set to appear on all 'course' pages), turn on editing for the course, then click on the link in the block to choose which participants to export the course to.

Features supported in this release
==================================

Course link import + export
Authentication of users arriving via exported course link
Import of directory trees, courses and course membership resources from the CMS participant