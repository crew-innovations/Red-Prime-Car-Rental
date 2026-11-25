{{-- Scan with camera; $target matches data-rp-camera-target on the FileUpload wrapper. --}}
<div class="rp-camera-trigger">
    <button type="button" class="rp-camera-trigger-btn" data-rp-camera-open="{{ $target }}">
        Scan with camera
    </button>
    <p class="text-sm text-gray-500 dark:text-gray-400" style="margin:.35rem 0 0">Take a photo (or choose a file). On customer details, empty fields fill in from the photo automatically — check them before saving.</p>
</div>
