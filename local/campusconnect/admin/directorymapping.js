M.campusconnect_directorymapping = {
    Y: null,
    mappings: null,
    mapbutton: null,
    unmapbutton: null,

    init: function (Y, opts) {
        this.Y = Y;
        this.mappings = opts.mappings;

        var self = this;

        // Hide the 'show mapping' button.
        this.Y.one('#showmappingbutton').setStyle('display', 'none');
        this.mapbutton = this.Y.one('#mapdirectorybutton');
        this.unmapbutton = this.Y.one('#unmapdirectorybutton');

        // Add the treeviews
        // Treeview and radio buttons do not play nicely together - disabling.
        //var dirtree = new YAHOO.widget.TreeView('campusconnect_dirtree');
        //dirtree.render();

        // Change mapping display when a directory is selected.
        var dirs = this.Y.one('#campusconnect_dirtree');
        dirs.all('.directoryradio').each(function (radio) {
            radio.on('click', self.radioclicked, self);
            if (radio.get('checked')) {
                self.selectdir(radio);
            }
        });
    },

    radioclicked: function (e) {
        e.stopPropagation();
        e.stopImmediatePropagation();
        this.selectdir(e.currentTarget);
    },

    selectdir: function (radio) {
        radio.set('checked', true);
        // Find the currently mapped category (if any).
        var id = radio.get('id');
        var directoryid = id.split('-')[1];
        var map = this.mappings[directoryid];

        // Deselect all categories + labels.
        var cats = this.Y.one('#campusconnect_categorytree');
        cats.all('.categoryradio').set('checked', false);
        cats.all('.categorylabel').removeClass('mapped_category');

        // Select the correct category + label.
        if (map.category) {
            cats.one('#category-' + map.category).set('checked', true);
            cats.one('#labelcategory-' + map.category).addClass('mapped_category');
            this.mapbutton.set('value', M.util.get_string('remapdirectory', 'local_campusconnect'));
            if (map.canunmap) {
                this.unmapbutton.removeAttribute('disabled');
            } else {
                this.unmapbutton.set('disabled', 'disabled');
            }
        } else {
            this.mapbutton.set('value', M.util.get_string('mapdirectory', 'local_campusconnect'));
            this.unmapbutton.set('disabled', 'disabled');
        }
        if (map.canmap) {
            this.mapbutton.removeAttribute('disabled');
        } else {
            this.mapbutton.set('disabled', 'disabled');
        }
    }

};
