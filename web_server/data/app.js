/* global bootstrap */
(function ($) {
    'use strict';

    var $fileSelect = $('#file-select');
    var $filter = $('#channel-filter');
    var $tbody = $('#channels-body');
    var $status = $('#status-line');
    var $alert = $('#alert-box');
    var $btnStop = $('#btn-stop');
    var $btnSync = $('#btn-sync');
    var $playEdit = $('#play-edit');
    var $btnSaveEdit = $('#btn-save-edit');
    var channels = [];
    var playingUrl = '';
    var syncModal = null;

    function showAlert(kind, text) {
        $alert
            .removeClass('d-none alert-success alert-danger alert-info alert-warning')
            .addClass('alert-' + kind)
            .text(text);
    }

    function hideAlert() {
        $alert.addClass('d-none').text('');
    }

    function setStatus(html) {
        $status.html(html);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function refillFiles(files, selected) {
        var current = selected || $fileSelect.val();
        $fileSelect.empty();
        $fileSelect.append($('<option>').val('').text('Select a file in ./streams'));
        (files || []).forEach(function (name) {
            $fileSelect.append($('<option>').val(name).text(name));
        });
        if (current && files && files.indexOf(current) !== -1) {
            $fileSelect.val(current);
        }
    }

    function genreBadges(genre) {
        if (!genre) {
            return '<span class="text-secondary">—</span>';
        }
        return String(genre)
            .split(';')
            .map(function (g) {
                g = $.trim(g);
                if (!g) {
                    return '';
                }
                return '<span class="badge genre-badge me-1">' + escapeHtml(g) + '</span>';
            })
            .join('');
    }

    function genreKey(ch) {
        return String((ch && ch.genre) || '').toLowerCase();
    }

    function sortByGenre(list) {
        return list.slice().sort(function (a, b) {
            var ga = genreKey(a);
            var gb = genreKey(b);
            if (!ga && gb) {
                return 1;
            }
            if (!gb && ga) {
                return -1;
            }
            if (ga < gb) {
                return -1;
            }
            if (ga > gb) {
                return 1;
            }
            var na = String((a && a.name) || '').toLowerCase();
            var nb = String((b && b.name) || '').toLowerCase();
            if (na < nb) {
                return -1;
            }
            if (na > nb) {
                return 1;
            }
            return 0;
        });
    }

    function renderRows(list) {
        if (!list.length) {
            $tbody.html(
                '<tr><td colspan="3" class="text-secondary py-4 text-center">No channels to show.</td></tr>'
            );
            return;
        }
        var html = list
            .map(function (ch) {
                var playing = ch.url && ch.url === playingUrl ? ' playing' : '';
                return (
                    '<tr class="' +
                    playing +
                    '" data-url="' +
                    escapeHtml(ch.url) +
                    '">' +
                    '<td>' +
                    escapeHtml(ch.name) +
                    '</td>' +
                    '<td>' +
                    genreBadges(ch.genre) +
                    '</td>' +
                    '<td><div class="d-flex align-items-center gap-2">' +
                    '<span class="stream-url">' +
                    escapeHtml(ch.url) +
                    '</span>' +
                    '<button type="button" class="btn btn-sm btn-success btn-play" data-url="' +
                    escapeHtml(ch.url) +
                    '">Play</button>' +
                    '</div></td>' +
                    '</tr>'
                );
            })
            .join('');
        $tbody.html(html);
    }

    function applyFilter() {
        var q = $.trim($filter.val()).toLowerCase();
        if (!q) {
            renderRows(channels);
            return;
        }
        var filtered = sortByGenre(
            channels.filter(function (ch) {
                return (
                    (ch.name && ch.name.toLowerCase().indexOf(q) !== -1) ||
                    (ch.genre && ch.genre.toLowerCase().indexOf(q) !== -1) ||
                    (ch.url && ch.url.toLowerCase().indexOf(q) !== -1) ||
                    (ch.channel_id && ch.channel_id.toLowerCase().indexOf(q) !== -1)
                );
            })
        );
        renderRows(filtered);
    }

    function loadFile(filename) {
        if (!filename) {
            channels = [];
            $filter.prop('disabled', true).val('');
            $tbody.html(
                '<tr><td colspan="3" class="text-secondary py-4 text-center">Choose a playlist file to load channels.</td></tr>'
            );
            return;
        }
        $tbody.html(
            '<tr><td colspan="3" class="text-secondary py-4 text-center">Loading…</td></tr>'
        );
        $.ajax({
            url: 'index.php',
            method: 'GET',
            dataType: 'json',
            data: { action: 'parse', file: filename },
        })
            .done(function (res) {
                if (!res || !res.ok) {
                    showAlert('danger', (res && res.error) || 'Failed to parse playlist');
                    return;
                }
                hideAlert();
                channels = sortByGenre(res.channels || []);
                $filter.prop('disabled', false);
                setStatus(
                    escapeHtml(String(res.count || 0)) +
                        ' channels in <code>' +
                        escapeHtml(filename) +
                        '</code>' +
                        (res.genres_loaded
                            ? ' · genre index: ' + escapeHtml(String(res.genres_loaded)) + ' channels'
                            : ' · no ./data/channels.csv found (Sync to fetch it)')
                );
                applyFilter();
            })
            .fail(function (xhr) {
                var msg = 'Failed to parse playlist';
                try {
                    msg = JSON.parse(xhr.responseText).error || msg;
                } catch (e) {}
                showAlert('danger', msg);
            });
    }

    function writePlay(url) {
        return $.ajax({
            url: 'index.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'play', url: url },
        });
    }

    function writeStop() {
        return $.ajax({
            url: 'index.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'stop' },
        });
    }

    function writeEditText(text) {
        return $.ajax({
            url: 'index.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'save', text: text },
        });
    }

    function applyWritten(text, notice) {
        playingUrl = text;
        $playEdit.val(text);
        $tbody.find('tr').removeClass('playing');
        $tbody.find('tr').each(function () {
            if ($(this).attr('data-url') === text) {
                $(this).addClass('playing');
            }
        });
        setStatus(
            (notice || 'Wrote') +
                ' <code>' +
                escapeHtml(text) +
                '</code> → <code>play/play.txt</code>'
        );
    }

    $fileSelect.on('change', function () {
        loadFile($fileSelect.val());
    });

    $filter.on('input', function () {
        applyFilter();
    });

    $tbody.on('click', 'button.btn-play', function (e) {
        e.preventDefault();
        var url = $(this).data('url');
        var $row = $(this).closest('tr');
        writePlay(url)
            .done(function (res) {
                if (!res || !res.ok) {
                    showAlert('danger', (res && res.error) || 'Could not write play/play.txt');
                    return;
                }
                applyWritten(url, 'Playing');
                $row.addClass('playing');
                showAlert('success', 'Wrote stream to play/play.txt');
            })
            .fail(function (xhr) {
                var msg = 'Could not write play/play.txt';
                try {
                    msg = JSON.parse(xhr.responseText).error || msg;
                } catch (e) {}
                showAlert('danger', msg);
            });
    });

    $btnStop.on('click', function () {
        $btnStop.prop('disabled', true);
        writeStop()
            .done(function (res) {
                if (!res || !res.ok) {
                    showAlert('danger', (res && res.error) || 'Could not write STOP');
                    return;
                }
                applyWritten('STOP', 'Wrote');
                playingUrl = '';
                $tbody.find('tr').removeClass('playing');
                showAlert('warning', 'Wrote STOP to play/play.txt');
            })
            .fail(function (xhr) {
                var msg = 'Could not write STOP';
                try {
                    msg = JSON.parse(xhr.responseText).error || msg;
                } catch (e) {}
                showAlert('danger', msg);
            })
            .always(function () {
                $btnStop.prop('disabled', false);
            });
    });

    $btnSaveEdit.on('click', function () {
        var text = $.trim($playEdit.val());
        if (!text) {
            showAlert('danger', 'Enter text or a stream URL first');
            $playEdit.focus();
            return;
        }
        $btnSaveEdit.prop('disabled', true);
        writeEditText(text)
            .done(function (res) {
                if (!res || !res.ok) {
                    showAlert('danger', (res && res.error) || 'Could not write play/play.txt');
                    return;
                }
                applyWritten(res.written || text, 'Saved');
                showAlert('success', 'Saved edited text to play/play.txt');
            })
            .fail(function (xhr) {
                var msg = 'Could not write play/play.txt';
                try {
                    msg = JSON.parse(xhr.responseText).error || msg;
                } catch (e) {}
                showAlert('danger', msg);
            })
            .always(function () {
                $btnSaveEdit.prop('disabled', false);
            });
    });

    $playEdit.on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $btnSaveEdit.trigger('click');
        }
    });

    $btnSync.on('click', function () {
        if (!syncModal) {
            var el = document.getElementById('sync-modal');
            if (el && window.bootstrap) {
                syncModal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
            }
        }
        if (syncModal) {
            syncModal.show();
        }
        $btnSync.prop('disabled', true);
        $.ajax({
            url: 'index.php',
            method: 'POST',
            dataType: 'json',
            timeout: 300000,
            data: { action: 'sync' },
        })
            .done(function (res) {
                refillFiles(res && res.files);
                if (res && res.ok) {
                    var extra = res.data && res.data.message ? ' · ' + res.data.message : '';
                    showAlert('success', (res.message || 'Sync complete') + extra);
                    setStatus(escapeHtml(res.message || 'Sync complete') + extra);
                    if ($fileSelect.val()) {
                        loadFile($fileSelect.val());
                    }
                } else {
                    showAlert('danger', (res && res.message) || 'Sync failed');
                }
            })
            .fail(function (xhr) {
                var msg = 'Sync failed';
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    msg = parsed.message || parsed.error || msg;
                } catch (e) {}
                showAlert('danger', msg);
            })
            .always(function () {
                $btnSync.prop('disabled', false);
                if (syncModal) {
                    syncModal.hide();
                }
            });
    });
})(jQuery);
