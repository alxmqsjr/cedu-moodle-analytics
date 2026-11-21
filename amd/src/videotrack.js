// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tracks the playback of YouTube videos embedded in pages and books.
 *
 * Uses the postMessage protocol of the YouTube player (no external script is loaded): the iframe gets
 * enablejsapi=1, and the player then reports its state and current time. Each 5-second part played is
 * marked in a bitmap, sent every 30 seconds while playing, on pause or end, and when the page is left.
 * Links to YouTube videos count as "opened outside Moodle".
 *
 * Plain AMD module (no ES module syntax), so it runs without a Grunt build.
 *
 * @module     local_ceduanalytics/videotrack
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    'use strict';

    const BUCKET = 5;
    const FLUSH_MS = 30000;
    const METHOD = 'local_ceduanalytics_video_progress';
    const EMBED = /^https?:\/\/(?:www\.)?(?:youtube\.com|youtube-nocookie\.com)\/embed\/([A-Za-z0-9_-]{6,20})/;
    const LINK = /(?:youtube\.com\/watch\?(?:.*&)?v=|youtu\.be\/)([A-Za-z0-9_-]{6,20})/;
    const ORIGINS = /^https?:\/\/(?:www\.)?(?:youtube\.com|youtube-nocookie\.com)$/;

    let cmid = 0;
    const videos = {};
    let counter = 0;

    /**
     * State of one video.
     *
     * @param {String} videoid
     * @returns {Object}
     */
    const video = function(videoid) {
        if (!videos[videoid]) {
            videos[videoid] = {id: videoid, parts: {}, duration: 0, plays: 0, linkclicks: 0, state: -1, dirty: false};
        }
        return videos[videoid];
    };

    /**
     * Hex bitmap of the parts played: bit (part mod 8) of byte (part div 8), the format the server joins.
     *
     * @param {Object} parts part index => true
     * @returns {String}
     */
    const hex = function(parts) {
        const bytes = [];
        Object.keys(parts).forEach(function(key) {
            const part = parseInt(key, 10);
            const byte = Math.floor(part / 8);
            bytes[byte] = (bytes[byte] || 0) + Math.pow(2, part % 8);
        });
        let out = '';
        for (let i = 0; i < bytes.length; i++) {
            out += ((bytes[i] || 0) + 256).toString(16).slice(1);
        }
        return out;
    };

    /**
     * Arguments of the web service call, and reset of the counters already sent.
     *
     * @param {Object} v
     * @returns {Object}
     */
    const payload = function(v) {
        const args = {cmid: cmid, videoid: v.id, duration: Math.round(v.duration), segments: hex(v.parts),
            plays: v.plays, linkclicks: v.linkclicks};
        v.plays = 0;
        v.linkclicks = 0;
        v.dirty = false;
        return args;
    };

    /**
     * Send the progress of the videos with changes.
     *
     * @param {Boolean} leaving Use sendBeacon (the page is being left)
     */
    const flush = function(leaving) {
        Object.keys(videos).forEach(function(id) {
            const v = videos[id];
            if (!v.dirty) {
                return;
            }
            const args = payload(v);
            if (leaving && navigator.sendBeacon && window.M && M.cfg) {
                const url = M.cfg.wwwroot + '/lib/ajax/service.php?sesskey=' + M.cfg.sesskey + '&info=' + METHOD;
                navigator.sendBeacon(url, new Blob([JSON.stringify([{index: 0, methodname: METHOD, args: args}])],
                    {type: 'application/json'}));
            } else {
                Ajax.call([{methodname: METHOD, args: args}])[0].catch(function() {
                    // Tracking must never disturb the student: errors are ignored.
                });
            }
        });
    };

    /**
     * Handle a message from a YouTube player.
     *
     * @param {Object} v Video state
     * @param {Object} info Player info (playerState, currentTime, duration)
     */
    const update = function(v, info) {
        if (typeof info.duration === 'number' && info.duration > 0) {
            v.duration = info.duration;
        }
        if (typeof info.playerState === 'number') {
            if (info.playerState === 1 && v.state !== 1) {
                v.plays++;
                v.dirty = true;
            }
            const stopped = v.state === 1 && (info.playerState === 0 || info.playerState === 2);
            v.state = info.playerState;
            if (stopped) {
                flush(false);
            }
        }
        if (v.state === 1 && typeof info.currentTime === 'number') {
            const b = Math.floor(info.currentTime / BUCKET);
            if (b >= 0 && b < 2880 && !v.parts[b]) {
                v.parts[b] = true;
                v.dirty = true;
            }
        }
    };

    /**
     * Prepare an embedded player: enable the JS API and start listening.
     *
     * @param {HTMLIFrameElement} iframe
     * @param {String} videoid
     */
    const watch = function(iframe, videoid) {
        const v = video(videoid);
        const id = 'cea-yt-' + (++counter);
        iframe.dataset.ceaVideo = id;
        let src = iframe.getAttribute('src');
        if (!/[?&]enablejsapi=1/.test(src)) {
            src += (src.indexOf('?') === -1 ? '?' : '&') + 'enablejsapi=1&origin=' + encodeURIComponent(window.location.origin);
            iframe.setAttribute('src', src);
        }
        let answered = false;
        let tries = 0;
        const listen = function() {
            if (answered || tries++ > 20 || !iframe.contentWindow) {
                return;
            }
            iframe.contentWindow.postMessage(JSON.stringify({event: 'listening', id: id, channel: 'widget'}), '*');
            setTimeout(listen, 1000);
        };
        iframe.addEventListener('load', function() {
            tries = 0;
            listen();
        });
        window.addEventListener('message', function(e) {
            if (!ORIGINS.test(e.origin) || e.source !== iframe.contentWindow) {
                return;
            }
            let data = e.data;
            try {
                data = typeof data === 'string' ? JSON.parse(data) : data;
            } catch (err) {
                return;
            }
            if (!data || !data.info) {
                return;
            }
            answered = true;
            update(v, data.info);
        });
        // The player may already be loaded (src unchanged).
        listen();
    };

    return {
        /**
         * Start tracking in the current page.
         *
         * @param {Number} id Course module id
         */
        init: function(id) {
            cmid = id;
            document.querySelectorAll('iframe[src]').forEach(function(iframe) {
                const m = EMBED.exec(iframe.getAttribute('src'));
                if (m) {
                    watch(iframe, m[1]);
                }
            });
            document.addEventListener('click', function(e) {
                const a = e.target.closest ? e.target.closest('a[href]') : null;
                const m = a ? LINK.exec(a.getAttribute('href')) : null;
                if (m) {
                    const v = video(m[1]);
                    v.linkclicks++;
                    v.dirty = true;
                    flush(false);
                }
            });
            setInterval(function() {
                flush(false);
            }, FLUSH_MS);
            window.addEventListener('pagehide', function() {
                flush(true);
            });
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden') {
                    flush(true);
                }
            });
        }
    };
});
