YUI.add('moodle-local_campusconnect-participantsettings', function (Y, NAME) {

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
 * Enable / disable participant settings
 *
 * @package   local_campusconnect
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

M.local_campusconnect = M.local_campusconnect || {};
M.local_campusconnect.participantsettings = {
    participantIdentifiers: [],
    hasChanges: false,

    init: function() {
        // Build up a list of all participant identifiers.
        Y.all('.participantidentifier').each(function(el) {
            this.participantIdentifiers.push(el.get('value'));
        }, this);

        Y.one('body').delegate('click', function() { this.updateDisabled(); }, '.participantsettings input', this);

        // Handle warnings about unsaved changes when navigating.
        Y.one('body').delegate('click', function() { this.hasChanges = true; }, '.participantsettings input[type=checkbox]', this);
        Y.one('body').delegate('change', function() { this.hasChanges = true; }, '.participantsettings select', this);

        Y.all('form').on('submit', function() { this.hasChanges = false; }, this); // No warning if form saved.

        var self = this;
        window.onbeforeunload = function(e) {
            if (self.hasChanges) {
                var warningmessage = M.util.get_string('changesmadereallygoaway', 'moodle');
                if (M.cfg.behatsiterunning) {
                    return;
                }
                if (e) {
                    e.returnValue = warningmessage;
                }
                return warningmessage;
            }
        };

        this.updateDisabled();
    },

    updateDisabled: function() {
        var i;
        for (i in this.participantIdentifiers) {
            if (this.participantIdentifiers.hasOwnProperty(i)) {
                this.updateDisabledForParticipant(this.participantIdentifiers[i]);
            }
        }
    },

    updateDisabledForParticipant: function(partid) {
        var importEnabled, exportEnabled, tokenEnabled;

        importEnabled = Y.one('#import_' + partid).get('checked');
        exportEnabled = Y.one('#export_' + partid).get('checked');

        if (importEnabled) {
            this.enableCheckbox('importenrolment_' + partid, true);
            this.enableCheckbox('importtoken_' + partid, true);
            tokenEnabled = Y.one('#importtoken_' + partid).get('checked');
            this.enableCheckbox('uselegacy_' + partid, tokenEnabled);
        } else {
            this.enableCheckbox('importenrolment_' + partid, false);
            this.enableCheckbox('importtoken_' + partid, false);
            this.enableCheckbox('uselegacy_' + partid, false);
        }

        if (exportEnabled) {
            this.enableCheckbox('exportenrolment_' + partid, true);
            this.enableCheckbox('exporttoken_' + partid, true);
        } else {
            this.enableCheckbox('exportenrolment_' + partid, false);
            this.enableCheckbox('exporttoken_' + partid, false);
        }
    },

    enableCheckbox: function(id, enable) {
        var checkbox, label;

        checkbox = Y.one('#' + id);
        if (checkbox) {
            if (enable) {
                checkbox.removeAttribute('disabled');
            } else {
                checkbox.set('disabled', true);
            }

            label = checkbox.next('label');
            if (label) {
                if (enable) {
                    label.removeClass('disabled');
                } else {
                    label.addClass('disabled');
                }
            }
        }
    }
};


}, '@VERSION@', {"requires": ["base", "node", "event", "event-valuechange", "node-event-delegate"]});
