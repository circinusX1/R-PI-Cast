/**
 * IPTV Stream Browser - Main Client Script
 */

let allChannels = [];
let currentPlayingUrl = '';
let hdmiPlayingUrl = '';
let castPlayingUrl = '';
let hlsInstance = null;
let castReady = false;
let fullPlaylistOptions = [];
let disableHdmi = false;
let disableCast = false;

$(document).ready(function() {
    // Enable tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Cache original playlist options for reset / filtering
    $('#playlist-list option').each(function() {
        fullPlaylistOptions.push({
            value: $(this).val(),
            text: $(this).text(),
            countryName: ($(this).data('country-name') || '').toString().toLowerCase(),
            file: $(this).data('file') || $(this).val()
        });
    });

    // Handle splitter resizing
    initResizer();

    // Setup Event Listeners
    setupEvents();

    // Initial load: check status and auto-load the first selected playlist from the select list box
    pollStatus();
    setInterval(pollStatus, 5000);

    const initialFile = $('#playlist-list').val();
    if (initialFile) {
        loadChannels(initialFile);
    }

    initCast();
});

function initResizer() {
    const resizer = document.getElementById('resizer');
    const paneTop = document.getElementById('pane-top');
    const appContainer = document.querySelector('.app-container');
    let isDragging = false;

    resizer.addEventListener('mousedown', function(e) {
        isDragging = true;
        resizer.classList.add('dragging');
        e.preventDefault();
    });

    $(document).on('mousemove', function(e) {
        if (!isDragging) return;
        const containerH = appContainer.clientHeight;
        const topH = e.clientY;
        const percentage = (topH / containerH) * 100;
        if (percentage > 15 && percentage < 85) {
            paneTop.style.flex = `1 1 ${percentage}%`;
        }
    });

    $(document).on('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            resizer.classList.remove('dragging');
        }
    });
}

function setupEvents() {
    // Playlist Selection Box Change Event (Single-click / selection change loads channels instantly)
    $(document).on('click change', '#playlist-list', function(e) {
        // Prevent single click or selection change from loading channels
        e.preventDefault();
    });

    $(document).on('dblclick', '#playlist-list', function() {
        const file = $(this).val();
        if (file) {
            loadChannels(file);
        }
    });

    // Reset Countries List Button
    $('#btn-reset-countries').on('click', function() {
        $('#search_cntry').val('');
        populatePlaylistSidebar(fullPlaylistOptions, false);
        showAlert('Country list reset.', 'info');
    });

    // Substring filter in search_cntry (live filter of playlist-list)
    $(document).on('input', '#search_cntry', function() {
        const query = $(this).val().toLowerCase().trim();
        if (!query) {
            populatePlaylistSidebar(fullPlaylistOptions, false);
            return;
        }
        const filteredOptions = fullPlaylistOptions.filter(opt => {
            return opt.countryName.includes(query) || opt.text.toLowerCase().includes(query);
        });
        populatePlaylistSidebar(filteredOptions, false);
    });

    // Channel Filter Input (local live filtering of loaded channels)
    $(document).on('input', '#channel-filter', function() {
        const query = $(this).val().toLowerCase();
        renderChannels(allChannels, query);
    });

    // Stop Button
    $('#btn-stop').on('click', function() {
        $.post('', { action: 'stop' }, function(res) {
            if (res.ok) {
                hdmiPlayingUrl = '';
                paintOutputButtons();
                showAlert('Playback stopped.', 'success');
                stopPlayer();
                pollStatus();
            } else {
                showAlert(res.error || 'Failed to stop', 'danger');
            }
        }).fail(function() {
            showAlert('Network error stopping stream', 'danger');
        });
    });

    // SND Toggle Change
    $('#chk-snd').on('change', function() {
        const on = $(this).is(':checked') ? '1' : '0';
        $.post('', { action: 'snd', on: on }, function(res) {
            if (!res.ok) {
                showAlert('Failed to update SND toggle', 'danger');
            }
        });
    });

    // Play Stream via Table Action Buttons (delegated)
    $(document).on('click', '.btn-play', function() {
        const url = $(this).data('url');
        const name = $(this).data('name');
        showSystemCommand(url);
        playStream(url, name);
    });

    // Open HTML stream URL in a new popup window via play.php
    $(document).on('click', '.btn-play-popup', function() {
        const url = $(this).data('url');
        const name = $(this).data('name') || 'Stream Popup';
        const popupUrl = 'play.php?url=' + encodeURIComponent(url);
        
        const width = 800;
        const height = 600;
        const left = (window.screen.width - width) / 2;
        const top = (window.screen.height - height) / 2;
        
        window.open(popupUrl, '_blank', `width=${width},height=${height},top=${top},left=${left},resizable=yes,scrollbars=yes`);
    });

    // HDMI toggle
    $(document).on('click', '.btn-hdmi', function() {
        if (disableHdmi) return;
        const btn = $(this);
        const url = btn.data('url');
        const name = btn.data('name') || 'stream';
        const sndOn = $('#chk-snd').is(':checked');

        if (hdmiPlayingUrl === url) {
            $.post('', { action: 'stop' }, function(res) {
                if (res.ok) {
                    hdmiPlayingUrl = '';
                    paintOutputButtons();
                    showAlert('HDMI mpv stopped.', 'success');
                    pollStatus();
                } else {
                    showAlert(res.error || 'Failed to stop HDMI', 'danger');
                }
            }).fail(function() {
                showAlert('Network error stopping HDMI', 'danger');
            });
            return;
        }

        $.post('', { action: 'hdmi', url: url }, function(res) {
            if (res.ok) {
                hdmiPlayingUrl = url;
                paintOutputButtons();
                showSystemCommand(url);
                const audio = sndOn ? 'headphones (SND on)' : 'HDMI audio (SND off)';
                showAlert('HDMI mpv: ' + name + ' — ' + audio, 'success');
                pollStatus();
            } else {
                showAlert(res.error || 'Failed to send to HDMI', 'danger');
            }
        }).fail(function() {
            showAlert('Network error sending to HDMI', 'danger');
        });
    });

    // CAST toggle
    $(document).on('click', '.btn-cast', function() {
        if (disableCast) return;
        const url = $(this).data('url');
        const name = $(this).data('name') || 'stream';
        if (castPlayingUrl === url) {
            stopCast();
            return;
        }
        startCast(url, name);
    });
}

function populatePlaylistSidebar(options, autoLoadFirst = false) {
    const select = $('#playlist-list');
    select.empty();
    if (options.length === 0) {
        select.append('<option value="" disabled>No countries found</option>');
        clearChannelsTable('No playlist selected.');
        return;
    }
    options.forEach((opt, idx) => {
        const selectedAttr = (idx === 0) ? ' selected' : '';
        select.append(`<option value="${escapeHtml(opt.value)}" data-file="${escapeHtml(opt.file)}" data-country-name="${escapeHtml(opt.countryName)}"${selectedAttr}>${escapeHtml(opt.text)}</option>` );
    });
    if (autoLoadFirst && options.length > 0) {
        loadChannels(options[0].value);
    }
}

function clearChannelsTable(msg) {
    $('#channels-body').html(`<tr><td colspan="3" class="text-secondary py-4 text-center">${escapeHtml(msg)}</td></tr>`);
    allChannels = [];
}

function loadChannels(fileName) {
    $('#channels-body').html('<tr><td colspan="3" class="text-secondary py-4 text-center"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading channels...</td></tr>');
    $('#channel-filter').val('');

    $.get('', { action: 'parse', file: fileName }, function(res) {
        if (res.ok) {
            allChannels = res.channels || [];
            disableHdmi = !!res.has_hdmi;
            disableCast = !!res.has_cast;

            renderChannels(allChannels, '');
            showAlert(`Loaded ${allChannels.length} channels.`, 'success');
        } else {
            $('#channels-body').html(`<tr><td colspan="3" class="text-danger py-4 text-center">${res.error || 'Failed to parse file'}</td></tr>`);
            showAlert(res.error || 'Failed to parse file', 'danger');
        }
    }).fail(function() {
        $('#channels-body').html('<tr><td colspan="3" class="text-danger py-4 text-center">Network error loading channels.</td></tr>');
        showAlert('Network error loading channels.', 'danger');
    });
}

function renderChannels(channels, filterQuery) {
    const tbody = $('#channels-body');
    tbody.empty();

    const filtered = channels.filter(ch => {
        if (!filterQuery) return true;
        const name = (ch.name || '').toLowerCase();
        const genre = (ch.genre || '').toLowerCase();
        const url = (ch.url || '').toLowerCase();
        return name.includes(filterQuery) || genre.includes(filterQuery) || url.includes(filterQuery);
    });

    if (filtered.length === 0) {
        tbody.html('<tr><td colspan="3" class="text-secondary py-4 text-center">No channels found matching filter.</td></tr>');
        return;
    }

    const htmlRows = filtered.map(ch => {
        const name = escapeHtml(ch.name || 'Unnamed Channel');
        const genre = escapeHtml(ch.genre || ch.group || 'General');
        const url = escapeHtml(ch.url || '');
        const isPlaying = url === currentPlayingUrl;
        const playlistBadge = ch.playlist_display ? `<span class="badge bg-secondary me-1" title="Country">${escapeHtml(ch.playlist_display)}</span>` : '';

        const isHtmlStream = /\.html?($|\?)/i.test(url);

        let streamColumnContent = '';
        if (isHtmlStream) {
            const hdmiAttr = disableHdmi ? 'disabled' : 'disabled';
            const castAttr = disableCast ? 'disabled' : 'disabled';

            streamColumnContent = `
                <div class="d-flex align-items-center gap-2">
                    <a href="${url}" target="_blank" rel="noopener noreferrer" class="stream-url small font-monospace text-decoration-underline" title="${url}">${url}</a>
                    <button type="button" class="btn btn-sm btn-primary btn-play-popup" data-url="${url}" data-name="${name}" title="Open stream in popup">
                        <i class="bi bi-play-fill"></i> Play
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary btn-hdmi" ${hdmiAttr} title="Not available for HTML streams">
                        HDMI
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary btn-cast" ${castAttr} title="Not available for HTML streams">
                        CAST
                    </button>
                </div>
            `;
        } else {
            const hdmiBtnClass = disableHdmi ? 'btn-secondary' : (hdmiPlayingUrl === ch.url ? 'btn-success' : 'btn-primary');
            const hdmiAttr = disableHdmi ? 'disabled' : '';
            
            const castBtnClass = disableCast ? 'btn-secondary' : (castPlayingUrl === ch.url ? 'btn-success' : 'btn-primary');
            const castAttr = disableCast ? 'disabled' : '';

            streamColumnContent = `
                <div class="d-flex align-items-center gap-2">
                    <span class="stream-url small font-monospace" title="${url}">${url}</span>
                    <button type="button" class="btn btn-sm btn-primary btn-play" data-url="${url}" data-name="${name}">
                        <i class="bi bi-play-fill"></i> Play
                    </button>
                    <button type="button" class="btn btn-sm ${hdmiBtnClass} btn-hdmi" data-url="${url}" data-name="${name}" ${hdmiAttr} title="${disableHdmi ? 'Disabled by _HDMI file' : 'Toggle HDMI mpv'}">
                        HDMI
                    </button>
                    <button type="button" class="btn btn-sm ${castBtnClass} btn-cast" data-url="${url}" data-name="${name}" ${castAttr} title="${disableCast ? 'Disabled by _CAST file' : 'Toggle Chromecast'}">
                        CAST
                    </button>
                </div>
            `;
        }

        return `
            <tr class="${isPlaying ? 'playing' : ''}">
                <td>
                    <div class="d-flex align-items-center">
                        ${playlistBadge}
                        <div class="fw-semibold text-truncate" style="max-width: 230px;" title="${name}">${name}</div>
                    </div>
                </td>
                <td>
                    <span class="badge genre-badge">${genre}</span>
                </td>
                <td>
                    ${streamColumnContent}
                </td>
            </tr>
        `;
    });

    tbody.html(htmlRows.join(''));
}

function playStream(url, name) {
    currentPlayingUrl = url;
    showSystemCommand(url);
    $('#player-title').html(`<i class="bi bi-tv me-1"></i> ${name}`);
    $('#player-status').text('Connecting...').removeClass('bg-secondary bg-success bg-danger').addClass('bg-warning text-dark');

    $.post('', { action: 'play', url: url }, function(res) {
        if (res.ok) {
            pollStatus();
        }
    });

    const video = document.getElementById('video-player');

    if (hlsInstance) {
        hlsInstance.destroy();
        hlsInstance = null;
    }

    if (url.includes('.m3u8')) {
        if (Hls.isSupported()) {
            hlsInstance = new Hls();
            hlsInstance.loadSource(url);
            hlsInstance.attachMedia(video);
            hlsInstance.on(Hls.Events.MANIFEST_PARSED, function() {
                video.play().catch(() => {});
                $('#player-status').text('Playing').removeClass('bg-warning bg-secondary bg-danger').addClass('bg-success');
            });
            hlsInstance.on(Hls.Events.ERROR, function(event, data) {
                if (data.fatal) {
                    $('#player-status').text('Stream Error').removeClass('bg-warning bg-success').addClass('bg-danger');
                }
            });
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = url;
            video.addEventListener('loadedmetadata', function() {
                video.play().catch(() => {});
                $('#player-status').text('Playing').removeClass('bg-warning bg-secondary bg-danger').addClass('bg-success');
            });
        }
    } else {
        video.src = url;
        video.play().catch(() => {});
        $('#player-status').text('Playing').removeClass('bg-warning bg-secondary bg-danger').addClass('bg-success');
    }

    renderChannels(allChannels, $('#channel-filter').val().toLowerCase());
}

function showSystemCommand(url) {
    $('#player-cmd').val(systemCommand(url));
}

function shellQuote(value) {
    return "'" + String(value).replace(/'/g, "'\\''") + "'";
}

function systemCommand(url) {
    const sndOn = $('#chk-snd').is(':checked');
    const adev = sndOn ? 'alsa/plughw:Headphones,0' : 'alsa/plughw:vc4hdmi0,0';
    const args = [
        'mpv',
        '--ao=alsa',
        '--audio-device=' + adev,
        '--hwdec=v4l2m2m-copy',
        '--vo=gpu',
        '--gpu-context=drm',
        '--fs',
        '--cache=yes'
    ];
    if (/youtube\.com|youtu\.be|googlevideo\.com/i.test(url)) {
        args.push("--ytdl-format='bv*[height<=720][fps<=30][vcodec^=avc1]+ba/18'");
    } else {
        args.push('--hls-bitrate=3100000');
    }
    args.push('--', shellQuote(url));
    return args.join(' ');
}

function stopPlayer() {
    currentPlayingUrl = '';
    const video = document.getElementById('video-player');
    video.pause();
    video.src = '';
    if (hlsInstance) {
        hlsInstance.destroy();
        hlsInstance = null;
    }
    $('#player-title').html('<i class="bi bi-tv me-1"></i> Local Embedded Player');
    $('#player-status').text('Idle').removeClass('bg-success bg-warning bg-danger').addClass('bg-secondary');
    $('#player-cmd').val('');
    renderChannels(allChannels, $('#channel-filter').val().toLowerCase());
}

function paintOutputButtons() {
    $('.btn-hdmi').each(function() {
        const on = $(this).data('url') === hdmiPlayingUrl && hdmiPlayingUrl !== '';
        $(this).toggleClass('btn-success', on).toggleClass('btn-primary', !on);
    });
    $('.btn-cast').each(function() {
        const on = $(this).data('url') === castPlayingUrl && castPlayingUrl !== '';
        $(this).toggleClass('btn-success', on).toggleClass('btn-primary', !on);
    });
}

function castMime(url) {
    if (/\.m3u8(\?|$)/i.test(url)) return 'application/x-mpegurl';
    if (/\.mpd(\?|$)/i.test(url)) return 'application/dash+xml';
    return 'video/mp4';
}

function initCast() {
    window.__onGCastApiAvailable = function(isAvailable) {
        if (!isAvailable || !window.cast || !cast.framework) {
            $('#cast-discovery').val('Chromecast sender not available in this browser');
            return;
        }
        const ctx = cast.framework.CastContext.getInstance();
        ctx.setOptions({
            receiverApplicationId: chrome.cast.media.DEFAULT_MEDIA_RECEIVER_APP_ID,
            autoJoinPolicy: chrome.cast.AutoJoinPolicy.ORIGIN_SCOPED
        });
        castReady = true;
        $('#cast-discovery').val('Ready — click Cast');
        ctx.addEventListener(cast.framework.CastContextEventType.SESSION_STATE_CHANGED, function(ev) {
            const state = ev.sessionState;
            if (state === cast.framework.SessionState.SESSION_ENDED || state === cast.framework.SessionState.SESSION_START_FAILED) {
                castPlayingUrl = '';
                paintOutputButtons();
                $('#cast-discovery').val('Disconnected');
            }
        });
    };
    $('#btn-find-cast').on('click', function() {
        if (!castReady) {
            showAlert('Chromecast sender is not ready. Use Chrome on HTTPS or localhost.', 'warning');
            return;
        }
        cast.framework.CastContext.getInstance().requestSession().then(function() {
            const session = cast.framework.CastContext.getInstance().getCurrentSession();
            const dev = session && session.getCastDevice ? session.getCastDevice() : null;
            $('#cast-discovery').val(dev && dev.friendlyName ? dev.friendlyName : 'Connected');
        }).catch(function() {
            $('#cast-discovery').val('No Chromecast selected');
        });
    });
}

function startCast(url, name) {
    if (disableCast) return;
    if (!castReady || !window.cast || !cast.framework) {
        showAlert('Chromecast needs Chrome on HTTPS or localhost.', 'warning');
        return;
    }
    const ctx = cast.framework.CastContext.getInstance();
    const load = function(session) {
        const mediaInfo = new chrome.cast.media.MediaInfo(url, castMime(url));
        mediaInfo.streamType = chrome.cast.media.StreamType.LIVE;
        mediaInfo.metadata = new chrome.cast.media.GenericMediaMetadata();
        mediaInfo.metadata.title = name;
        return session.loadMedia(new chrome.cast.media.LoadRequest(mediaInfo));
    };
    const after = function() {
        const session = ctx.getCurrentSession();
        if (!session) {
            showAlert('No Chromecast session.', 'danger');
            return;
        }
        load(session).then(function() {
            castPlayingUrl = url;
            paintOutputButtons();
            const dev = session.getCastDevice ? session.getCastDevice() : null;
            $('#cast-discovery').val(dev && dev.friendlyName ? ('Casting to ' + dev.friendlyName) : 'Casting');
            showAlert('Casting ' + name, 'success');
        }).catch(function(err) {
            showAlert('Cast failed: ' + (err && err.description ? err.description : 'load error'), 'danger');
        });
    };
    if (ctx.getCurrentSession()) {
        after();
        return;
    }
    ctx.requestSession().then(after).catch(function() {
        showAlert('Chromecast picker cancelled.', 'warning');
    });
}

function stopCast() {
    if (!castReady || !window.cast || !cast.framework) {
        castPlayingUrl = '';
        paintOutputButtons();
        return;
    }
    const session = cast.framework.CastContext.getInstance().getCurrentSession();
    if (!session) {
        castPlayingUrl = '';
        paintOutputButtons();
        return;
    }
    session.endSession(true).then(function() {
        castPlayingUrl = '';
        paintOutputButtons();
        $('#cast-discovery').val('Disconnected');
        showAlert('Cast stopped.', 'success');
    }).catch(function() {
        castPlayingUrl = '';
        paintOutputButtons();
    });
}

function pollStatus() {
    $.get('', { action: 'status' }, function(res) {
        if (res.ok) {
            if (res.play && res.play !== 'STOP') {
                $('#status-line').html(`Last in <code>play/play.txt</code>: <code>${escapeHtml(res.play)}</code>`);
            } else {
                $('#status-line').html('Ready. Select a playlist file from the list box.');
            }
        }
    });
}

function showAlert(message, type = 'info') {
    const alertBox = $('#alert-box');
    alertBox.removeClass('d-none alert-success alert-danger alert-info alert-warning').addClass(`alert-${type}`).text(message);
    setTimeout(() => {
        alertBox.addClass('d-none');
    }, 4000);
}

function escapeHtml(str) {
    return $('<div>').text(str).html();
}

