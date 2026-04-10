/**
 * KO-Picker – Wiederverwendbarer Kommunikationsobjekte-Auswahldialog
 *
 * Einbinden:
 *   <link rel="stylesheet" href="ko_picker.css">
 *   <script src="ko_picker.js"></script>
 *
 * Verwendung:
 *   koPickerOpen({
 *       currentKoId : 123,          // optional – vorauswählen
 *       ajaxUrl     : '?',          // optional – Standard '?'
 *       onConfirm   : function(koId, koName, koGa, koGatyp) { ... },
 *       onReset     : function()    { ... },   // optional
 *   });
 */

(function () {
    'use strict';

    // ------------------------------------------------------------------ State
    var _state = {
        opts:            {},
        selectedId:      null,
        selectedName:    null,
        selectedGa:      null,
        selectedGatyp:   null,
        folderStack:     [],   // [{id, name}, ...]
        currentFolderId: 30,
        isSearchMode:    false,
        searchTimer:     null,
    };

    // ------------------------------------------------------------------ Init
    document.addEventListener('DOMContentLoaded', function () {
        _injectHTML();
        _bindEvents();
    });

    function _injectHTML() {
        var el = document.createElement('div');
        el.innerHTML = [
            '<div id="koPicker-overlay">',
            '  <div id="koPicker-win">',
            '    <div id="koPicker-title">',
            '      <span id="koPicker-title-text">Kommunikationsobjekte</span>',
            '      <span id="koPicker-close" title="Schließen"></span>',
            '    </div>',
            '    <div id="koPicker-menu">',
            '      <div class="koPicker-btn koPicker-btn-l"  id="koPicker-btn-cancel">Abbrechen</div>',
            '      <div class="koPicker-btn koPicker-btn-m koPicker-btn-accept" id="koPicker-btn-ok"><b>Übernehmen</b></div>',
            '      <div class="koPicker-btn koPicker-btn-r"  id="koPicker-btn-reset">Zurücksetzen</div>',
            '    </div>',
            '    <div id="koPicker-content">',
            '      <div id="koPicker-search-row">',
            '        <input type="text" id="koPicker-search" placeholder="Suchen (Name oder GA)…">',
            '        <span id="koPicker-search-hint"></span>',
            '      </div>',
            '      <div id="koPicker-path"></div>',
            '      <div id="koPicker-columns">',
            '        <div id="koPicker-left"></div>',
            '        <div id="koPicker-right"></div>',
            '      </div>',
            '    </div>',
            '  </div>',
            '</div>',
        ].join('');
        document.body.appendChild(el.firstChild);
    }

    function _bindEvents() {
        _q('#koPicker-close').addEventListener('click', close);
        _q('#koPicker-btn-cancel').addEventListener('click', close);
        _q('#koPicker-btn-ok').addEventListener('click', confirm);
        _q('#koPicker-btn-reset').addEventListener('click', reset);

        _q('#koPicker-search').addEventListener('input', _onSearchInput);
        _q('#koPicker-search').addEventListener('keydown', _onSearchKey);

        // Klick auf Overlay-Hintergrund schließt
        _q('#koPicker-overlay').addEventListener('click', function (e) {
            if (e.target === this) close();
        });

        // ESC schließt
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && _q('#koPicker-overlay').classList.contains('visible')) {
                close();
            }
        });

        // Drag
        _initDrag();
    }

    // ---------------------------------------------------------------- Public API

    window.koPickerOpen = function (opts) {
        _state.opts            = opts || {};
        _state.selectedId      = opts.currentKoId || null;
        _state.selectedName    = null;
        _state.selectedGa      = null;
        _state.selectedGatyp   = null;
        _state.isSearchMode    = false;

        _q('#koPicker-search').value = '';
        _q('#koPicker-search-hint').textContent = '';

        if (opts.currentKoId) {
            _navigateToKo(opts.currentKoId);
        } else {
            _state.folderStack     = [];
            _state.currentFolderId = 30;
            _loadFolder(30, true);
        }
        _q('#koPicker-overlay').classList.add('visible');
        setTimeout(function () { _q('#koPicker-search').focus(); }, 120);
    };

    // ----------------------------------------------------------------- Actions

    function _navigateToKo(koId) {
        var url = (_state.opts.ajaxUrl || '?') + 'action=koGetFolder&koId=' + koId;
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _state.folderStack     = data.ancestors || [];
                _state.currentFolderId = data.folderId  || 30;
                _loadFolder(_state.currentFolderId, false);
            })
            .catch(function () {
                _state.folderStack     = [];
                _state.currentFolderId = 30;
                _loadFolder(30, true);
            });
    }

    function close() {
        _q('#koPicker-overlay').classList.remove('visible');
    }

    function confirm() {
        if (_state.selectedId === null) {
            _shake();
            return;
        }
        close();
        if (_state.opts.onConfirm) {
            _state.opts.onConfirm(
                _state.selectedId,
                _state.selectedName,
                _state.selectedGa,
                _state.selectedGatyp
            );
        }
    }

    function reset() {
        close();
        if (_state.opts.onReset) {
            _state.opts.onReset();
        }
    }

    // ---------------------------------------------------------------- Folder

    function _loadFolder(folderId, resetStack) {
        if (resetStack) {
            _state.folderStack     = [];
            _state.currentFolderId = 30;
        }
        _state.isSearchMode = false;
        var url = (_state.opts.ajaxUrl || '?') + 'action=koTree&folderId=' + folderId;
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _state.currentFolderId = folderId;
                _renderFolder(data);
            });
    }

    function _renderFolder(data) {
        var left  = _q('#koPicker-left');
        var right = _q('#koPicker-right');
        var path  = _q('#koPicker-path');
        var stack = _state.folderStack;

        // Linkes Menü: Navigationshistorie
        left.innerHTML = '';
        stack.forEach(function (entry, idx) {
            var div = document.createElement('div');
            div.className = 'koPicker-menuItem';
            div.style.paddingLeft = (idx * 10 + 6) + 'px';
            div.textContent = entry.name;
            div.addEventListener('click', (function (i) {
                return function () {
                    var tid = stack[i].id;
                    _state.folderStack = stack.slice(0, i);
                    _loadFolder(tid, false);
                };
            })(idx));
            left.appendChild(div);
        });
        if (data.folder) {
            var cur = document.createElement('div');
            cur.className = 'koPicker-menuItem active';
            cur.style.paddingLeft = (stack.length * 10 + 6) + 'px';
            cur.textContent = data.folder.name;
            left.appendChild(cur);
        }

        // Breadcrumb
        var crumbs = stack.map(function (entry, idx) {
            return '<span class="koPicker-crumb" data-idx="' + idx + '">' + _esc(entry.name) + '</span> &rsaquo; ';
        }).join('');
        if (data.folder) crumbs += _esc(data.folder.name);
        path.innerHTML = crumbs;
        path.querySelectorAll('.koPicker-crumb').forEach(function (el) {
            el.addEventListener('click', function () {
                var i   = parseInt(el.dataset.idx);
                var tid = stack[i].id;
                _state.folderStack = stack.slice(0, i);
                _loadFolder(tid, false);
            });
        });

        // Rechte Spalte
        right.innerHTML = '';

        // Zurück-Link
        if (stack.length > 0) {
            var back = document.createElement('div');
            back.className = 'koPicker-folderItem';
            back.innerHTML = '&#8593; ..';
            back.addEventListener('click', function () {
                var prev = _state.folderStack.pop();
                _loadFolder(prev.id, false);
            });
            right.appendChild(back);
        }

        // Unterordner
        data.folders.forEach(function (f) {
            var div = document.createElement('div');
            div.className = 'koPicker-folderItem';
            div.innerHTML = '&#128193; ' + _esc(f.name);
            div.addEventListener('click', function () {
                _state.folderStack.push({
                    id:   _state.currentFolderId,
                    name: data.folder ? data.folder.name : '',
                });
                _loadFolder(f.id, false);
            });
            right.appendChild(div);
        });

        if (data.folders.length > 0 && data.items.length > 0) {
            var hr = document.createElement('hr');
            hr.className = 'koPicker-sep';
            right.appendChild(hr);
        }

        data.items.forEach(function (item) {
            _createItem(right, item, null);
        });

        if (data.folders.length === 0 && data.items.length === 0) {
            var empty = document.createElement('div');
            empty.style.cssText = 'color:#505050; padding:6px 5px; font-style:italic;';
            empty.textContent = '(leer)';
            right.appendChild(empty);
        }
    }

    // ---------------------------------------------------------------- Items

    function _createItem(container, item, pathLabel) {
        var div = document.createElement('div');
        div.className = 'koPicker-listItem';

        if (_state.selectedId !== null && item.id == _state.selectedId) {
            div.classList.add('selected');
        }

        // Erste Zeile: Name + GA-Badge
        var line1 = document.createElement('span');
        line1.className = 'koPicker-listItem-name';
        line1.innerHTML = _esc(item.name)
            + (item.ga ? ' <span class="koPicker-idGa' + (item.gatyp || 1) + '">' + _esc(item.ga) + '</span>' : '');
        div.appendChild(line1);

        // Zweite Zeile: Pfad (nur in Suche)
        if (pathLabel) {
            var line2 = document.createElement('span');
            line2.className = 'koPicker-listItem-path';
            line2.innerHTML = '&#128193; ' + _esc(pathLabel);
            div.appendChild(line2);
        }

        div.addEventListener('click', function () {
            container.querySelectorAll('.koPicker-listItem.selected')
                .forEach(function (el) { el.classList.remove('selected'); });
            div.classList.add('selected');
            _state.selectedId    = item.id;
            _state.selectedName  = item.name;
            _state.selectedGa    = item.ga   || null;
            _state.selectedGatyp = item.gatyp || 1;
        });

        div.addEventListener('dblclick', function () {
            div.click();
            confirm();
        });

        container.appendChild(div);
    }

    // ---------------------------------------------------------------- Search

    function _onSearchInput() {
        clearTimeout(_state.searchTimer);
        var q = _q('#koPicker-search').value.trim();
        _q('#koPicker-search-hint').textContent = '';
        if (q.length < 2) {
            if (_state.isSearchMode) {
                _loadFolder(_state.currentFolderId, false);
            }
            return;
        }
        _state.searchTimer = setTimeout(function () { _doSearch(q); }, 300);
    }

    function _onSearchKey(e) {
        if (e.key === 'Escape') {
            _q('#koPicker-search').value = '';
            _onSearchInput();
        }
    }

    function _doSearch(q) {
        _state.isSearchMode = true;
        var url = (_state.opts.ajaxUrl || '?') + 'action=koSearch&q=' + encodeURIComponent(q);
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _q('#koPicker-search-hint').textContent = data.items.length + ' Treffer';
                _q('#koPicker-left').innerHTML  = '';
                _q('#koPicker-path').innerHTML  = '<i style="color:#505050">Suchergebnisse</i>';
                var right = _q('#koPicker-right');
                right.innerHTML = '';
                data.items.forEach(function (item) {
                    _createItem(right, item, item.path || null);
                });
                if (data.items.length === 0) {
                    var empty = document.createElement('div');
                    empty.style.cssText = 'color:#505050; padding:6px 5px; font-style:italic;';
                    empty.textContent = 'Keine Treffer.';
                    right.appendChild(empty);
                }
            });
    }

    // ---------------------------------------------------------------- Helpers

    function _q(sel) { return document.querySelector(sel); }

    function _esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function _shake() {
        var win = _q('#koPicker-win');
        var moves = [6, -6, 5, -5, 3, -3, 0];
        var i = 0;
        (function step() {
            if (i >= moves.length) { win.style.transform = ''; return; }
            win.style.transform = 'translateX(' + moves[i++] + 'px)';
            setTimeout(step, 60);
        })();
    }

    function _initDrag() {
        var win   = _q('#koPicker-win');
        var title = _q('#koPicker-title');
        var ox, oy, sl, st, dragging = false;

        title.addEventListener('mousedown', function (e) {
            if (e.target.id === 'koPicker-close') return;
            dragging = true;
            ox = e.clientX; oy = e.clientY;
            var r = win.getBoundingClientRect();
            sl = r.left; st = r.top;
            win.style.position = 'fixed';
            win.style.margin   = '0';
            win.style.left     = sl + 'px';
            win.style.top      = st + 'px';
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            win.style.left = (sl + e.clientX - ox) + 'px';
            win.style.top  = (st + e.clientY - oy) + 'px';
        });
        document.addEventListener('mouseup', function () { dragging = false; });
    }

})();
