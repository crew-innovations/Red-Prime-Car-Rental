/**
 * 25 Nov 2026: "Scan with camera" for document uploads (meeting: camera instead of file-only).
 * Opens the device camera (rear when available), captures a JPEG, and feeds it into FilePond
 * (Filament FileUpload) or a plain <input type="file">. No outside subscription service.
 */
(function () {
    'use strict';

    var overlay = null;
    var stream = null;
    var video = null;
    var targetKey = null;
    var onFile = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function fileName() {
        var d = new Date();
        return 'scan_' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate())
            + '_' + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds()) + '.jpg';
    }

    function stop() {
        if (stream) {
            stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
            stream = null;
        }
        if (overlay) {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
        }
        targetKey = null;
        onFile = null;
    }

    function ensureOverlay() {
        if (overlay) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'rp-camera';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.innerHTML = ''
            + '<div class="rp-camera-panel" role="dialog" aria-label="Scan with camera">'
            + '  <div class="rp-camera-bar">'
            + '    <strong>Scan with camera</strong>'
            + '    <button type="button" class="rp-camera-x" data-rp-cam="close" aria-label="Close">&times;</button>'
            + '  </div>'
            + '  <div class="rp-camera-stage">'
            + '    <video playsinline autoplay muted></video>'
            + '    <canvas hidden></canvas>'
            + '    <p class="rp-camera-msg" hidden></p>'
            + '  </div>'
            + '  <div class="rp-camera-actions">'
            + '    <button type="button" class="rp-camera-btn" data-rp-cam="shutter">Take photo</button>'
            + '    <button type="button" class="rp-camera-btn rp-camera-btn-ghost" data-rp-cam="retake" hidden>Retake</button>'
            + '    <button type="button" class="rp-camera-btn rp-camera-btn-ok" data-rp-cam="use" hidden>Use photo</button>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(overlay);
        video = overlay.querySelector('video');
        overlay.addEventListener('click', function (e) {
            var act = e.target && e.target.getAttribute && e.target.getAttribute('data-rp-cam');
            if (!act && e.target === overlay) act = 'close';
            if (act === 'close') stop();
            if (act === 'shutter') shutter();
            if (act === 'retake') retake();
            if (act === 'use') usePhoto();
        });
        return overlay;
    }

    function showMsg(text) {
        var msg = overlay.querySelector('.rp-camera-msg');
        var canvas = overlay.querySelector('canvas');
        msg.hidden = !text;
        msg.textContent = text || '';
        video.hidden = !!text;
        canvas.hidden = true;
        overlay.querySelector('[data-rp-cam="shutter"]').hidden = !!text;
        overlay.querySelector('[data-rp-cam="retake"]').hidden = true;
        overlay.querySelector('[data-rp-cam="use"]').hidden = true;
    }

    function shutter() {
        if (!video || !video.videoWidth) return;
        var canvas = overlay.querySelector('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        video.hidden = true;
        canvas.hidden = false;
        overlay.querySelector('[data-rp-cam="shutter"]').hidden = true;
        overlay.querySelector('[data-rp-cam="retake"]').hidden = false;
        overlay.querySelector('[data-rp-cam="use"]').hidden = false;
        if (stream) stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
        stream = null;
    }

    function retake() {
        startStream();
    }

    function usePhoto() {
        var canvas = overlay.querySelector('canvas');
        canvas.toBlob(function (blob) {
            if (!blob) {
                showMsg('Could not save the photo. Try again, or choose a file.');
                return;
            }
            var file = new File([blob], fileName(), { type: 'image/jpeg', lastModified: Date.now() });
            var cb = onFile;
            var key = targetKey;
            stop();
            if (typeof cb === 'function') {
                cb(file);
            } else {
                inject(key, file);
            }
            document.dispatchEvent(new CustomEvent('rp-camera-captured', { detail: { file: file, target: key } }));
        }, 'image/jpeg', 0.92);
    }

    function inject(key, file) {
        if (!key) return;
        // Plain file input: data-rp-camera-input="…"
        var plain = document.querySelector('[data-rp-camera-input="' + cssEscape(key) + '"]');
        if (plain && plain.tagName === 'INPUT') {
            try {
                var dt = new DataTransfer();
                dt.items.add(file);
                plain.files = dt.files;
                plain.dispatchEvent(new Event('change', { bubbles: true }));
                plain.dispatchEvent(new Event('input', { bubbles: true }));
            } catch (e) {
                alert('Could not attach the photo. Please choose a file instead.');
            }
            return;
        }
        // Filament FilePond: wrapper with data-rp-camera-target
        var wrap = document.querySelector('[data-rp-camera-target="' + cssEscape(key) + '"]');
        if (!wrap) {
            alert('Upload field not found. Please choose a file instead.');
            return;
        }
        var pondRoot = wrap.querySelector('.filepond--root') || wrap;
        var pond = window.FilePond && window.FilePond.find ? window.FilePond.find(pondRoot) : null;
        if (pond && typeof pond.addFile === 'function') {
            pond.addFile(file).catch(function () {
                alert('Could not attach the photo. Please choose a file instead.');
            });
            return;
        }
        var input = wrap.querySelector('input[type="file"]');
        if (input) {
            try {
                var dt2 = new DataTransfer();
                dt2.items.add(file);
                input.files = dt2.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (e2) {
                alert('Could not attach the photo. Please choose a file instead.');
            }
            return;
        }
        alert('Could not attach the photo. Please choose a file instead.');
    }

    function cssEscape(s) {
        if (window.CSS && CSS.escape) return CSS.escape(s);
        return String(s).replace(/"/g, '\\"');
    }

    function startStream() {
        showMsg('');
        var canvas = overlay.querySelector('canvas');
        canvas.hidden = true;
        video.hidden = false;
        overlay.querySelector('[data-rp-cam="shutter"]').hidden = false;
        overlay.querySelector('[data-rp-cam="retake"]').hidden = true;
        overlay.querySelector('[data-rp-cam="use"]').hidden = true;

        var tryConstraints = [
            { audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } },
            { audio: false, video: { facingMode: 'user' } },
            { audio: false, video: true },
        ];

        function next(i) {
            if (i >= tryConstraints.length) {
                showMsg('No camera available, or permission was refused. Use “Choose file” instead.');
                return;
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                showMsg('This browser cannot open the camera. Use “Choose file” instead.');
                return;
            }
            navigator.mediaDevices.getUserMedia(tryConstraints[i]).then(function (s) {
                stream = s;
                video.srcObject = s;
                video.play().catch(function () {});
            }).catch(function () { next(i + 1); });
        }
        next(0);
    }

    function open(opts) {
        opts = opts || {};
        ensureOverlay();
        targetKey = opts.target || null;
        onFile = typeof opts.onFile === 'function' ? opts.onFile : null;
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        startStream();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-rp-camera-open]') : null;
        if (!btn) return;
        e.preventDefault();
        open({
            target: btn.getAttribute('data-rp-camera-open'),
            onFile: null,
        });
    });

    window.RPCamera = { open: open, stop: stop, inject: inject };
})();
