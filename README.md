# Participation Forum - Moodle Plugin

![Participation Forum Logo](http://pf.bushgrapher.org/images/logo.jpg)

[http://participationforum.org](http://participationforum.org)

This module is a new type of forum that assesses student performance and assigns grades automatically.

## About

The Participation Forum is a Moodle module developed in 2011 by [Thomas Lextrait](http://tlextrait.com) / [Lychee Apps](http://lycheeapps.com), and [Brant Knutzen](http://brant.knutzen.se/).
Testing and further development plans are underway at the University of Hong Kong.

This project is open-source and maintained by the community. Your contributions are welcome. To contribute, fork this repository.

## License

[GNU General Public License v3](LICENSE)

## Requirements
**Support for Moodle 3.1 and higher is coming soon.**

* Moodle 2.4 - 3.0

## Legacy Moodle Support

*For Moodle 2.0 to 2.3 support, please download [version 1.3 here, which is no longer being supported or worked on](http://pf.bushgrapher.org/downloads/PartForum_1.3.0.zip)*.

## Features

1. The Participation Forum module is a customized forum designed to engage students in a collaborative online discussion as a formative learning activity. It creates a required activity worth up to 10 points, which the students can earn by participating in the discussion. It has three primary features:
The “Add a new group post” button creates “semi-private” areas for discussions by small groups of students (size 3 to 8 is recommended)
2. It appends detailed task design instructions (below the forum topic questions) which describe the types of posts expected:
   * Group post
   * First post by each student
   * Questions
   * Answers
   * Reflective post
3. It automatically generates a rating of participation based on the level of activity by each student (up to 10 points available) and stores it in the course Gradebook

## Installation

### Installing via uploaded ZIP file

1. Login to your Moodle site as an admin and go to Administration > Site administration > Plugins > Install plugins.
2. Upload the ZIP file, select the Activity module(mod) plugin type, tick the acknowledgement checkbox, then click the button 'Install plugin from the ZIP file'.
3. Check that you obtain a 'Validation passed!' message, then click the button 'Install plugin'.

### Installing manually at the server

1. First, establish the correct place in the Moodle code tree for the plugin type. Common locations are:
    /path/to/moodle/mod/ - activity modules and resources
2. See dev:Plugins for the full list of all plugin types and their locations within the Moodle tree.  
3. Upload or copy it to your Moodle instance.
4. Unzip it in the right place for the plugin type (or follow the plugin instructions).
5. In your Moodle site (as admin) go to Settings > Site administration > Notifications (you should, for most plugin types, get a message saying the plugin is installed).
    
Note: The plugin may contain language files. They'll be found by your Moodle automatically. These language strings can be customized using the standard Settings > Site administration > Language editing interface. If you get a "Database error" when you try to edit your language files, there is a strong chance that the language files included within the downloaded ZIP file of this plugin have a coding problem. If you delete the plugin_name/lang/other_language_different_to_English/ folder with the new language strings and the database error disappears, this is indeed the case. Please notify the plugin maintainer, so that it can be fixed in future releases.

## Tutorials

[YouTube Tutorial Videos](https://www.youtube.com/playlist?list=PLU9j5H0P1sx9YoXgfZiNamrLvL8HfuIfy) by Brant Knutzen.
