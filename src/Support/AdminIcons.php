<?php

namespace Aurora\Support;

use Aurora\Aurora;
use Illuminate\Support\Facades\File;

/**
 * The admin panel draws its icons from Phosphor. Our own code names them directly
 * ("phosphor-house", "phosphor-caret-down-bold"); Filament and its plugins ask for Heroicons
 * ("heroicon-m-chevron-down"), so the "heroicons" icon set is pointed at Phosphor drawings
 * filed under the Heroicon names. The website keeps the real Heroicons under the "hero-"
 * prefix (see SiteIcon), so content looks the same in the editor as on the live site.
 *
 * The drawings are copied into storage the first time they are needed, and again whenever
 * this map changes.
 */
class AdminIcons
{
    /**
     * Every Heroicon, by name, and the Phosphor icon drawn in its place.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'academic-cap' => 'graduation-cap',
        'adjustments-horizontal' => 'sliders-horizontal',
        'adjustments-vertical' => 'sliders',
        'archive-box-arrow-down' => 'archive',
        'archive-box-x-mark' => 'archive',
        'archive-box' => 'archive',
        'arrow-down-circle' => 'arrow-circle-down',
        'arrow-down-left' => 'arrow-down-left',
        'arrow-down-on-square-stack' => 'download-simple',
        'arrow-down-on-square' => 'download-simple',
        'arrow-down-right' => 'arrow-down-right',
        'arrow-down-tray' => 'download-simple',
        'arrow-down' => 'arrow-down',
        'arrow-left-circle' => 'arrow-circle-left',
        'arrow-left-end-on-rectangle' => 'sign-in',
        'arrow-left-on-rectangle' => 'sign-out',
        'arrow-left-start-on-rectangle' => 'sign-out',
        'arrow-left' => 'arrow-left',
        'arrow-long-down' => 'arrow-down',
        'arrow-long-left' => 'arrow-left',
        'arrow-long-right' => 'arrow-right',
        'arrow-long-up' => 'arrow-up',
        'arrow-path-rounded-square' => 'arrows-clockwise',
        'arrow-path' => 'arrows-clockwise',
        'arrow-right-circle' => 'arrow-circle-right',
        'arrow-right-end-on-rectangle' => 'sign-in',
        'arrow-right-on-rectangle' => 'sign-in',
        'arrow-right-start-on-rectangle' => 'sign-out',
        'arrow-right' => 'arrow-right',
        'arrow-small-down' => 'arrow-down',
        'arrow-small-left' => 'arrow-left',
        'arrow-small-right' => 'arrow-right',
        'arrow-small-up' => 'arrow-up',
        'arrow-top-right-on-square' => 'arrow-square-out',
        'arrow-trending-down' => 'trend-down',
        'arrow-trending-up' => 'trend-up',
        'arrow-turn-down-left' => 'arrow-elbow-down-left',
        'arrow-turn-down-right' => 'arrow-elbow-down-right',
        'arrow-turn-left-down' => 'arrow-elbow-left-down',
        'arrow-turn-left-up' => 'arrow-elbow-left-up',
        'arrow-turn-right-down' => 'arrow-elbow-right-down',
        'arrow-turn-right-up' => 'arrow-elbow-right-up',
        'arrow-turn-up-left' => 'arrow-elbow-up-left',
        'arrow-turn-up-right' => 'arrow-elbow-up-right',
        'arrow-up-circle' => 'arrow-circle-up',
        'arrow-up-left' => 'arrow-up-left',
        'arrow-up-on-square-stack' => 'upload-simple',
        'arrow-up-on-square' => 'upload-simple',
        'arrow-up-right' => 'arrow-up-right',
        'arrow-up-tray' => 'upload-simple',
        'arrow-up' => 'arrow-up',
        'arrow-uturn-down' => 'arrow-u-up-right',
        'arrow-uturn-left' => 'arrow-u-up-left',
        'arrow-uturn-right' => 'arrow-u-up-right',
        'arrow-uturn-up' => 'arrow-u-right-up',
        'arrows-pointing-in' => 'arrows-in',
        'arrows-pointing-out' => 'arrows-out',
        'arrows-right-left' => 'arrows-left-right',
        'arrows-up-down' => 'arrows-down-up',
        'at-symbol' => 'at',
        'backspace' => 'backspace',
        'backward' => 'rewind',
        'banknotes' => 'money',
        'bars-2' => 'equals',
        'bars-3-bottom-left' => 'text-align-left',
        'bars-3-bottom-right' => 'text-align-right',
        'bars-3-center-left' => 'text-align-left',
        'bars-3' => 'list',
        'bars-4' => 'text-align-justify',
        'bars-arrow-down' => 'sort-descending',
        'bars-arrow-up' => 'sort-ascending',
        'battery-0' => 'battery-empty',
        'battery-100' => 'battery-full',
        'battery-50' => 'battery-medium',
        'beaker' => 'flask',
        'bell-alert' => 'bell-ringing',
        'bell-slash' => 'bell-slash',
        'bell-snooze' => 'bell-z',
        'bell' => 'bell',
        'bold' => 'text-b',
        'bolt-slash' => 'lightning-slash',
        'bolt' => 'lightning',
        'book-open' => 'book-open',
        'bookmark-slash' => 'bookmark-simple',
        'bookmark-square' => 'bookmark-simple',
        'bookmark' => 'bookmark-simple',
        'briefcase' => 'briefcase',
        'bug-ant' => 'bug',
        'building-library' => 'bank',
        'building-office-2' => 'buildings',
        'building-office' => 'building-office',
        'building-storefront' => 'storefront',
        'cake' => 'cake',
        'calculator' => 'calculator',
        'calendar-date-range' => 'calendar-dots',
        'calendar-days' => 'calendar-dots',
        'calendar' => 'calendar-blank',
        'camera' => 'camera',
        'chart-bar-square' => 'chart-bar',
        'chart-bar' => 'chart-bar',
        'chart-pie' => 'chart-pie-slice',
        'chat-bubble-bottom-center-text' => 'chat-text',
        'chat-bubble-bottom-center' => 'chat',
        'chat-bubble-left-ellipsis' => 'chat-dots',
        'chat-bubble-left-right' => 'chats',
        'chat-bubble-left' => 'chat-centered',
        'chat-bubble-oval-left-ellipsis' => 'chat-circle-dots',
        'chat-bubble-oval-left' => 'chat-circle',
        'check-badge' => 'seal-check',
        'check-circle' => 'check-circle',
        'check' => 'check',
        'chevron-double-down' => 'caret-double-down',
        'chevron-double-left' => 'caret-double-left',
        'chevron-double-right' => 'caret-double-right',
        'chevron-double-up' => 'caret-double-up',
        'chevron-down' => 'caret-down',
        'chevron-left' => 'caret-left',
        'chevron-right' => 'caret-right',
        'chevron-up-down' => 'caret-up-down',
        'chevron-up' => 'caret-up',
        'circle-stack' => 'database',
        'clipboard-document-check' => 'clipboard-text',
        'clipboard-document-list' => 'clipboard-text',
        'clipboard-document' => 'clipboard',
        'clipboard' => 'clipboard',
        'clock' => 'clock',
        'cloud-arrow-down' => 'cloud-arrow-down',
        'cloud-arrow-up' => 'cloud-arrow-up',
        'cloud' => 'cloud',
        'code-bracket-square' => 'code-block',
        'code-bracket' => 'code',
        'cog-6-tooth' => 'gear',
        'cog-8-tooth' => 'gear-six',
        'cog' => 'gear-fine',
        'command-line' => 'terminal-window',
        'computer-desktop' => 'desktop',
        'cpu-chip' => 'cpu',
        'credit-card' => 'credit-card',
        'cube-transparent' => 'cube-transparent',
        'cube' => 'cube',
        'currency-bangladeshi' => 'currency-circle-dollar',
        'currency-dollar' => 'currency-dollar',
        'currency-euro' => 'currency-eur',
        'currency-pound' => 'currency-gbp',
        'currency-rupee' => 'currency-inr',
        'currency-yen' => 'currency-jpy',
        'cursor-arrow-rays' => 'cursor-click',
        'cursor-arrow-ripple' => 'cursor-click',
        'device-phone-mobile' => 'device-mobile',
        'device-tablet' => 'device-tablet',
        'divide' => 'divide',
        'document-arrow-down' => 'file-arrow-down',
        'document-arrow-up' => 'file-arrow-up',
        'document-chart-bar' => 'file-text',
        'document-check' => 'file-text',
        'document-currency-bangladeshi' => 'file-text',
        'document-currency-dollar' => 'file-text',
        'document-currency-euro' => 'file-text',
        'document-currency-pound' => 'file-text',
        'document-currency-rupee' => 'file-text',
        'document-currency-yen' => 'file-text',
        'document-duplicate' => 'copy',
        'document-magnifying-glass' => 'file-magnifying-glass',
        'document-minus' => 'file-minus',
        'document-plus' => 'file-plus',
        'document-text' => 'file-text',
        'document' => 'file',
        'ellipsis-horizontal-circle' => 'dots-three-circle',
        'ellipsis-horizontal' => 'dots-three',
        'ellipsis-vertical' => 'dots-three-vertical',
        'envelope-open' => 'envelope-open',
        'envelope' => 'envelope-simple',
        'equals' => 'equals',
        'exclamation-circle' => 'warning-circle',
        'exclamation-triangle' => 'warning',
        'eye-dropper' => 'eyedropper',
        'eye-slash' => 'eye-slash',
        'eye' => 'eye',
        'face-frown' => 'smiley-sad',
        'face-smile' => 'smiley',
        'film' => 'film-strip',
        'finger-print' => 'fingerprint',
        'fire' => 'fire',
        'flag' => 'flag',
        'folder-arrow-down' => 'folder-simple',
        'folder-minus' => 'folder-minus',
        'folder-open' => 'folder-open',
        'folder-plus' => 'folder-plus',
        'folder' => 'folder',
        'forward' => 'fast-forward',
        'funnel' => 'funnel',
        'gif' => 'gif',
        'gift-top' => 'gift',
        'gift' => 'gift',
        'globe-alt' => 'globe',
        'globe-americas' => 'globe-hemisphere-west',
        'globe-asia-australia' => 'globe-hemisphere-east',
        'globe-europe-africa' => 'globe-hemisphere-east',
        'h1' => 'text-h-one',
        'h2' => 'text-h-two',
        'h3' => 'text-h-three',
        'hand-raised' => 'hand-palm',
        'hand-thumb-down' => 'thumbs-down',
        'hand-thumb-up' => 'thumbs-up',
        'hashtag' => 'hash',
        'heart' => 'heart',
        'home-modern' => 'house-line',
        'home' => 'house',
        'identification' => 'identification-card',
        'inbox-arrow-down' => 'tray-arrow-down',
        'inbox-stack' => 'tray',
        'inbox' => 'tray',
        'information-circle' => 'info',
        'italic' => 'text-italic',
        'key' => 'key',
        'language' => 'translate',
        'lifebuoy' => 'lifebuoy',
        'light-bulb' => 'lightbulb',
        'link-slash' => 'link-break',
        'link' => 'link',
        'list-bullet' => 'list-bullets',
        'lock-closed' => 'lock',
        'lock-open' => 'lock-open',
        'magnifying-glass-circle' => 'magnifying-glass',
        'magnifying-glass-minus' => 'magnifying-glass-minus',
        'magnifying-glass-plus' => 'magnifying-glass-plus',
        'magnifying-glass' => 'magnifying-glass',
        'map-pin' => 'map-pin',
        'map' => 'map-trifold',
        'megaphone' => 'megaphone',
        'microphone' => 'microphone',
        'minus-circle' => 'minus-circle',
        'minus-small' => 'minus',
        'minus' => 'minus',
        'moon' => 'moon',
        'musical-note' => 'music-note',
        'newspaper' => 'newspaper',
        'no-symbol' => 'prohibit',
        'numbered-list' => 'list-numbers',
        'paint-brush' => 'paint-brush',
        'paper-airplane' => 'paper-plane-right',
        'paper-clip' => 'paperclip',
        'pause-circle' => 'pause-circle',
        'pause' => 'pause',
        'pencil-square' => 'note-pencil',
        'pencil' => 'pencil-simple',
        'percent-badge' => 'percent',
        'phone-arrow-down-left' => 'phone-incoming',
        'phone-arrow-up-right' => 'phone-outgoing',
        'phone-x-mark' => 'phone-x',
        'phone' => 'phone',
        'photo' => 'image',
        'play-circle' => 'play-circle',
        'play-pause' => 'play-pause',
        'play' => 'play',
        'plus-circle' => 'plus-circle',
        'plus-small' => 'plus',
        'plus' => 'plus',
        'power' => 'power',
        'presentation-chart-bar' => 'presentation-chart',
        'presentation-chart-line' => 'presentation-chart',
        'printer' => 'printer',
        'puzzle-piece' => 'puzzle-piece',
        'qr-code' => 'qr-code',
        'question-mark-circle' => 'question',
        'queue-list' => 'rows',
        'radio' => 'radio',
        'receipt-percent' => 'receipt',
        'receipt-refund' => 'receipt',
        'rectangle-group' => 'squares-four',
        'rectangle-stack' => 'stack',
        'rocket-launch' => 'rocket-launch',
        'rss' => 'rss',
        'scale' => 'scales',
        'scissors' => 'scissors',
        'server-stack' => 'hard-drives',
        'server' => 'hard-drive',
        'share' => 'share-network',
        'shield-check' => 'shield-check',
        'shield-exclamation' => 'shield-warning',
        'shopping-bag' => 'shopping-bag',
        'shopping-cart' => 'shopping-cart',
        'signal-slash' => 'cell-signal-slash',
        'signal' => 'broadcast',
        'slash' => 'line-segment',
        'sparkles' => 'sparkle',
        'speaker-wave' => 'speaker-high',
        'speaker-x-mark' => 'speaker-x',
        'square-2-stack' => 'copy-simple',
        'square-3-stack-3d' => 'stack',
        'squares-2x2' => 'squares-four',
        'squares-plus' => 'plus-square',
        'star' => 'star',
        'stop-circle' => 'stop-circle',
        'stop' => 'stop',
        'strikethrough' => 'text-strikethrough',
        'sun' => 'sun',
        'swatch' => 'palette',
        'table-cells' => 'table',
        'tag' => 'tag',
        'ticket' => 'ticket',
        'trash' => 'trash',
        'trophy' => 'trophy',
        'truck' => 'truck',
        'tv' => 'television',
        'underline' => 'text-underline',
        'user-circle' => 'user-circle',
        'user-group' => 'users-three',
        'user-minus' => 'user-minus',
        'user-plus' => 'user-plus',
        'user' => 'user',
        'users' => 'users',
        'variable' => 'brackets-curly',
        'video-camera-slash' => 'video-camera-slash',
        'video-camera' => 'video-camera',
        'view-columns' => 'columns',
        'viewfinder-circle' => 'crosshair',
        'wallet' => 'wallet',
        'wifi' => 'wifi-high',
        'window' => 'app-window',
        'wrench-screwdriver' => 'wrench',
        'wrench' => 'wrench',
        'x-circle' => 'x-circle',
        'x-mark' => 'x',
    ];

    /**
     * Heroicon styles and the Phosphor weight that matches each: outline is the regular
     * weight, solid the filled one, and the small mini and micro icons the bold one.
     *
     * @var array<string, string>
     */
    public const WEIGHTS = [
        'o' => '',
        's' => '-fill',
        'm' => '-bold',
        'c' => '-bold',
    ];

    public static function directory(): string
    {
        return storage_path('framework/admin-icons');
    }

    /**
     * Copy the drawings into place if they are missing or out of date. Returns whether
     * they are ready; if storage can't be written, the admin panel keeps the Heroicons.
     */
    public static function ensureBuilt(): bool
    {
        $marker = static::directory().'/.version';

        if (is_file($marker) && file_get_contents($marker) === static::version()) {
            return true;
        }

        try {
            static::build();
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    public static function build(): void
    {
        $source = Aurora::packagePath('codeat3/blade-phosphor-icons', 'resources/svg');
        $directory = static::directory();

        File::ensureDirectoryExists($directory);
        File::cleanDirectory($directory);

        foreach (static::MAP as $heroicon => $phosphor) {
            foreach (static::WEIGHTS as $style => $weight) {
                File::copy("{$source}/{$phosphor}{$weight}.svg", "{$directory}/{$style}-{$heroicon}.svg");
            }
        }

        File::put($directory.'/.version', static::version());
    }

    protected static function version(): string
    {
        return md5(serialize([static::MAP, static::WEIGHTS]));
    }
}
