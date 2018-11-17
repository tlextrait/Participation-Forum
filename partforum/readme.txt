This file is part of Moodle - http://moodle.org/

Moodle is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Moodle is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

copyright 2009 Petr Skoda (http://skodak.org)
license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later


Participation Forum module
=============

This module is a new type of forum that assesses student performence and assigns grades automatically

Installation
=============
1.Installing via uploaded ZIP file

    a.Login to your Moodle site as an admin and go to Administration > Site administration > Plugins > Install plugins.
    b.Upload the ZIP file, select the Activity module(mod) plugin type, tick the acknowledgement checkbox, then click the button 'Install plugin from the ZIP file'.
    c.Check that you obtain a 'Validation passed!' message, then click the button 'Install plugin'.

2.Installing manually at the server

    First, establish the correct place in the Moodle code tree for the plugin type. Common locations are:
    /path/to/moodle/mod/ - activity modules and resources
    
    See dev:Plugins for the full list of all plugin types and their locations within the Moodle tree.
  
    a.Upload or copy it to your Moodle instance.
    b.Unzip it in the right place for the plugin type (or follow the plugin instructions).
    c.In your Moodle site (as admin) go to Settings > Site administration > Notifications (you should, for most plugin types, get a message saying the plugin is installed).
    Note: The plugin may contain language files. They'll be found by your Moodle automatically. These language strings can be customized using the standard Settings > Site administration > Language editing interface. If you get a "Database error" when you try to edit your language files, there is a strong chance that the language files included within the downloaded ZIP file of this plugin have a coding problem. If you delete the plugin_name/lang/other_language_different_to_English/ folder with the new language strings and the database error disappears, this is indeed the case. Please notify the plugin maintainer, so that it can be fixed in future releases.

Features
=============
1.The Participation Forum module is a customized forum designed to engage students in a collaborative online discussion as a formative learning activity. It creates a required activity worth up to 10 points, which the students can earn by participating in the discussion. It has three primary features:
The “Add a new group post” button creates “semi-private” areas for discussions by small groups of students (size 3 to 8 is recommended)
2.It appends detailed task design instructions (below the forum topic questions) which describe the types of posts expected:
   a.Group post
   b.First post by each student
   c.Questions
   d.Answers
   e.Reflective post
3.It automatically generates a rating of participation based on the level of activity by each student (up to 10 points available) and stores it in the course Gradebook

TODO:
 * new backup/restore
