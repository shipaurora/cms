{{-- The sizes every image gets that can't be changed: listed above the formats that can. --}}
<div class="cms-built-in-formats">
    <p class="cms-built-in-formats-label">{{ __('Built in') }}</p>
    <ul>
        <li><span>{{ __('Original') }}</span><span>{{ __('As uploaded') }}</span></li>
        <li><span>{{ __('Thumbnail') }} <span class="cms-format-handle">(thumb)</span></span><span>{{ __('Cropped to :width × :height', ['width' => 480, 'height' => 360]) }} · WebP</span></li>
        <li><span>{{ __('Share image') }} <span class="cms-format-handle">(og)</span></span><span>{{ __('Cropped to :width × :height', ['width' => 1200, 'height' => 630]) }} · JPEG</span></li>
    </ul>
</div>
