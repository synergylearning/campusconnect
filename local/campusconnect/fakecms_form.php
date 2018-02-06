<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Supports the creation of resources via fakecms.php
 *
 * @package   local_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/formslib.php');

/** Form for entering CMS data */
class fakecms_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        $participants = $this->_customdata['participants'];
        $cmsparticipant = $this->_customdata['cmsparticipant'];
        $thisparticipant = $this->_customdata['thisparticipant'];
        $dirresources = $this->_customdata['dirresources'];
        if (!empty($dirresources)) {
            $dirresources = array_combine($dirresources, $dirresources);
        }
        $crsresources = $this->_customdata['crsresources'];
        if (!empty($crsresources)) {
            $crsresources = array_combine($crsresources, $crsresources);
        }
        $mbrresources = $this->_customdata['mbrresources'];
        if (!empty($mbrresources)) {
            $mbrresources = array_combine($mbrresources, $mbrresources);
        }

        $actions = array(
            'create' => 'create',
            'retrieve' => 'retrieve',
            'update' => 'update',
            'delete' => 'delete',
        );

        // General settings.
        $mform->addElement('header', 'general', 'General');
        $mform->addElement('select', 'srcpart', 'Send from', $participants);
        $mform->setDefault('srcpart', $cmsparticipant);
        $mform->addElement('select', 'dstpart', 'Send to', $participants);
        $mform->setDefault('dstpart', $thisparticipant);

        // Directory trees.
        $mform->addElement('header', 'dirtree', 'Directory tree');
        $mform->addElement('select', 'diraction', 'Action', $actions);
        $mform->addElement('select', 'dirresourceid', 'Existing resource', $dirresources);
        $mform->disabledIf('dirresourceid', 'diraction', 'eq', 'create');

        $mform->addElement('text', 'dirtreetitle', 'Directory tree name');
        $mform->setType('dirtreetitle', PARAM_TEXT);
        $mform->setDefault('dirtreetitle', 'Directory tree');
        $mform->addElement('text', 'dirrootid', 'Root directory id', array('size' => 10));
        $mform->setType('dirrootid', PARAM_TEXT);
        $mform->addElement('text', 'dirid', 'Directory id', array('size' => 10));
        $mform->setType('dirid', PARAM_TEXT);
        $mform->addElement('text', 'dirtitle', 'Directory title');
        $mform->setType('dirtitle', PARAM_TEXT);
        $mform->addElement('text', 'dirparentid', 'Parent directory id', array('size' => 10));
        $mform->setType('dirparentid', PARAM_TEXT);
        $mform->addElement('text', 'dirorder', 'Directory order (within parent)', array('size' => 10));
        $mform->setType('dirorder', PARAM_TEXT);

        $mform->addElement('submit', 'dirsubmit', 'Send directory request');

        // Courses.
        $mform->addElement('header', 'course', 'Course');
        $mform->addElement('select', 'crsaction', 'Action', $actions);
        $mform->addElement('select', 'crsresourceid', 'Existing resource', $crsresources);
        $mform->disabledIf('crsresourceid', 'crsaction', 'eq', 'create');

        $mform->addElement('text', 'crsorganisation', 'Course organisation');
        $mform->setType('crsorganisation', PARAM_TEXT);
        $mform->addElement('text', 'crsid', 'Course id', array('size' => 10));
        $mform->setType('crsid', PARAM_TEXT);
        $mform->addElement('text', 'crsterm', 'Term', array('size' => 10));
        $mform->setType('crsterm', PARAM_TEXT);
        $mform->addElement('text', 'crstitle', 'Title');
        $mform->setType('crstitle', PARAM_TEXT);
        $mform->addElement('text', 'crstype', 'Course type');
        $mform->setType('crstype', PARAM_TEXT);
        $mform->addElement('text', 'crsmaxpart', 'Max participants', array('size' => 10));
        $mform->setType('crsmaxpart', PARAM_TEXT);

        /*
        for ($i=1; $i<=2; $i++) {
            $grp = array(
                $mform->createElement('text', "crslecturerfirst[$i]", ''),
                $mform->createElement('text', "crslecturerlast[$i]", ''),
            );
            $mform->setType("crslecturerfirst[$i]", PARAM_TEXT);
            $mform->setType("crslecturerlast[$i]", PARAM_TEXT);
            $mform->addGroup($grp, "crslecturer[$i]", "Lecturer $i (first, last)", ' ', false);
        }
        */

        for ($i = 1; $i <= 3; $i++) {
            $grp = array(
                $mform->createElement('text', "crsallparent[$i]", '', array('size' => 10)),
                $mform->createElement('text', "crsallorder[$i]", '', array('size' => 10)),
            );
            $mform->setType("crsallparent[$i]", PARAM_TEXT);
            $mform->setType("crsallorder[$i]", PARAM_TEXT);
            $mform->addGroup($grp, "crsallocation[$i]", "Allocation $i (parentdir, order)", ' ', false);
        }

        $mform->addElement('static', 'crsp', '', 'Parallel groups');
        $mform->setAdvanced('crsp');
        $mform->addElement('select', 'crsparallel', 'Parallel group scenario', array(
            -1 => 'none', 1 => 'One course', 2 => 'Separate groups', 3 => 'Separate courses', 4 => 'Separate lecturers'
        ));
        $mform->setAdvanced("crsparallel");
        for ($i = 1; $i <= 3; $i++) {
            $mform->addElement('text', "crsptitle[$i]", "PGroup$i title");
            $mform->setAdvanced("crsptitle[$i]");
            $mform->setType("crsptitle[$i]", PARAM_TEXT);
            $mform->addElement('text', "crspid[$i]", "PGroup$i id", array('size' => 10));
            $mform->setType("crspid[$i]", PARAM_TEXT);
            $mform->setAdvanced("crspid[$i]");
            $mform->addElement('text', "crspcomment[$i]", "PGroup$i comment", array('size' => 40));
            $mform->setType("crspcomment[$i]", PARAM_TEXT);
            $mform->setAdvanced("crspcomment[$i]");
            for ($j = 1; $j <= 3; $j++) {
                $grp = array(
                    $mform->createElement('text', "crsplecturerfirst[$i][$j]", ''),
                    $mform->createElement('text', "crsplecturerlast[$i][$j]", ''),
                );
                $mform->setType("crsplecturerfirst[$i][$j]", PARAM_TEXT);
                $mform->setType("crsplecturerlast[$i][$j]", PARAM_TEXT);
                $mform->addGroup($grp, "crsplecturer[$i][$j]", "Lecturer $j (first, last)", ' ', false);
                $mform->setAdvanced("crsplecturer[$i][$j]");
            }
            $mform->addElement('static', "crsp$i", '', '');
            $mform->setAdvanced("crsp$i");
        }

        $mform->addElement('submit', 'crssubmit', 'Send course request');

        // Membership.
        $mform->addElement('header', 'membership', 'Course membership');
        $mform->addElement('select', 'mbraction', 'Action', $actions);
        $mform->addElement('select', 'mbrresourceid', 'Existing resource', $mbrresources);
        $mform->disabledIf('mbrresourceid', 'mbraction', 'eq', 'create');
        $mform->addElement('text', 'mbrcourseid', 'Course id', array('size' => 10));
        $mform->setType('mbrcourseid', PARAM_TEXT);
        for ($i = 1; $i <= 5; $i++) {
            $mform->addElement('static', "mbr$i", '', '');
            $mform->addElement('text', "mbrid[$i]", "Person ID $i (username)");
            $mform->setType("mbrid[$i]", PARAM_TEXT);
            $mform->addElement('text', "mbrrole[$i]", "Role $i");
            $mform->setType("mbrrole[$i]", PARAM_TEXT);
            for ($j = 1; $j <= 3; $j++) {
                $mform->addElement('text', "mbrpgid[$i][$j]", "PGroup $i.$j ID", array('size' => 10));
                $mform->setType("mbrpgid[$i][$j]", PARAM_TEXT);
                $mform->setAdvanced("mbrpgid[$i][$j]");
                $mform->addElement('text', "mbrpgrole[$i][$j]", "PGroup $i.$j role");
                $mform->setType("mbrpgrole[$i][$j]", PARAM_TEXT);
                $mform->setAdvanced("mbrpgrole[$i][$j]");
            }
        }

        $mform->addElement('submit', 'mbrsubmit', 'Send membership request');

        $js = <<<END
<script type="text/javascript">
var elnames = ['dir', 'crs', 'mbr'], i, prefix, sel, fnchange;

fnchange = function (e) {
        var name = e.currentTarget.id;
        var prefix = name.substr(3, 3);
        var action = document.getElementById('id_' + prefix + 'action');
        action.options[1].selected = true;
};

for (i = 0; i < elnames.length; i += 1) {
    prefix = elnames[i];
    sel = document.getElementById('id_' + prefix + 'resourceid');
    sel.onchange = fnchange;
}
</script>
END;

        $mform->addElement('static', 'javascript', '', $js);
    }
}
