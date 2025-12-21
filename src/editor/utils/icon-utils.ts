/**
 * Icon utilities for Proto-Blocks
 *
 * Handles conversion of icon strings to WordPress block icons
 */

import { createElement } from '@wordpress/element';
import { Icon } from '@wordpress/components';
import * as icons from '@wordpress/icons';

type IconType = string | { src: string } | JSX.Element;

/**
 * Dashicon name to WordPress icon mapping
 */
const dashiconMapping: Record<string, keyof typeof icons> = {
    'admin-appearance': 'layout',
    'admin-collapse': 'chevronUp',
    'admin-comments': 'comment',
    'admin-customizer': 'settings',
    'admin-generic': 'cog',
    'admin-home': 'home',
    'admin-links': 'link',
    'admin-media': 'image',
    'admin-multisite': 'globe',
    'admin-network': 'cloud',
    'admin-page': 'page',
    'admin-plugins': 'plugins',
    'admin-post': 'post',
    'admin-settings': 'settings',
    'admin-site': 'wordpress',
    'admin-tools': 'tool',
    'admin-users': 'people',
    'align-center': 'alignCenter',
    'align-left': 'alignLeft',
    'align-none': 'alignNone',
    'align-right': 'alignRight',
    'archive': 'archive',
    'arrow-down': 'arrowDown',
    'arrow-left': 'arrowLeft',
    'arrow-right': 'arrowRight',
    'arrow-up': 'arrowUp',
    'art': 'brush',
    'awards': 'starFilled',
    'backup': 'backup',
    'block-default': 'blockDefault',
    'book': 'library',
    'book-alt': 'library',
    'buddicons-activity': 'activity',
    'button': 'button',
    'calendar': 'calendar',
    'calendar-alt': 'calendar',
    'camera': 'camera',
    'cart': 'cart',
    'category': 'category',
    'chart-area': 'chartLine',
    'chart-bar': 'chartBar',
    'chart-line': 'chartLine',
    'chart-pie': 'chartPie',
    'clipboard': 'copy',
    'clock': 'clock',
    'cloud': 'cloud',
    'columns': 'columns',
    'controls-play': 'play',
    'cover-image': 'cover',
    'dashboard': 'dashboard',
    'desktop': 'desktop',
    'dismiss': 'close',
    'download': 'download',
    'edit': 'edit',
    'editor-code': 'code',
    'editor-paragraph': 'paragraph',
    'editor-quote': 'quote',
    'editor-table': 'tableOfContents',
    'editor-ul': 'listView',
    'email': 'envelope',
    'email-alt': 'envelope',
    'embed-generic': 'embedGeneric',
    'embed-video': 'video',
    'excerpt-view': 'excerpt',
    'external': 'external',
    'facebook': 'external',
    'feedback': 'starEmpty',
    'filter': 'filter',
    'flag': 'flag',
    'format-aside': 'aside',
    'format-audio': 'audio',
    'format-chat': 'commentContent',
    'format-gallery': 'gallery',
    'format-image': 'image',
    'format-quote': 'quote',
    'format-status': 'postStatus',
    'format-video': 'video',
    'forms': 'formFileUpload',
    'fullscreen-alt': 'fullscreen',
    'fullscreen-exit-alt': 'closeSmall',
    'grid-view': 'grid',
    'groups': 'people',
    'heading': 'heading',
    'heart': 'heart',
    'hidden': 'unseen',
    'html': 'html',
    'id': 'key',
    'id-alt': 'key',
    'image-crop': 'crop',
    'image-filter': 'filter',
    'image-flip-horizontal': 'flipHorizontal',
    'image-flip-vertical': 'flipVertical',
    'image-rotate': 'rotateRight',
    'image-rotate-left': 'rotateLeft',
    'image-rotate-right': 'rotateRight',
    'images-alt': 'gallery',
    'images-alt2': 'gallery',
    'info': 'info',
    'insert': 'plus',
    'instagram': 'external',
    'layout': 'layout',
    'leftright': 'arrowsUpDown',
    'lightbulb': 'lightbulb',
    'list-view': 'listView',
    'location': 'mapMarker',
    'location-alt': 'mapMarker',
    'lock': 'lock',
    'media-archive': 'file',
    'media-audio': 'audio',
    'media-code': 'code',
    'media-default': 'file',
    'media-document': 'file',
    'media-interactive': 'pullLeft',
    'media-spreadsheet': 'tableOfContents',
    'media-text': 'alignLeft',
    'media-video': 'video',
    'megaphone': 'commentContent',
    'menu': 'menu',
    'menu-alt': 'menu',
    'migrate': 'shuffle',
    'minus': 'minus',
    'money': 'payment',
    'move': 'move',
    'nametag': 'tag',
    'networking': 'share',
    'no': 'cancelCircleFilled',
    'no-alt': 'closeCircle',
    'open-folder': 'folder',
    'palmtree': 'starEmpty',
    'paperclip': 'file',
    'performance': 'chartLine',
    'pets': 'starEmpty',
    'phone': 'mobile',
    'playlist-audio': 'audio',
    'playlist-video': 'video',
    'plus': 'plus',
    'plus-alt': 'plusCircle',
    'portfolio': 'portfolio',
    'post-status': 'postStatus',
    'pressthis': 'starEmpty',
    'products': 'store',
    'redo': 'redo',
    'rest-api': 'code',
    'rss': 'rss',
    'saved': 'check',
    'schedule': 'postDate',
    'screenoptions': 'settings',
    'search': 'search',
    'share': 'share',
    'share-alt': 'share',
    'share-alt2': 'share',
    'shield': 'shield',
    'shield-alt': 'shield',
    'shortcode': 'shortcode',
    'slides': 'media',
    'smartphone': 'mobile',
    'smiley': 'smiley',
    'sort': 'dragHandle',
    'sos': 'help',
    'star-empty': 'starEmpty',
    'star-filled': 'starFilled',
    'star-half': 'starHalf',
    'store': 'store',
    'tablet': 'tablet',
    'tag': 'tag',
    'tagcloud': 'tag',
    'testimonial': 'testimonial',
    'text': 'alignLeft',
    'text-page': 'page',
    'thumbs-down': 'thumbsDown',
    'thumbs-up': 'thumbsUp',
    'tickets': 'ticket',
    'tickets-alt': 'ticket',
    'translation': 'globe',
    'trash': 'trash',
    'twitter': 'external',
    'undo': 'undo',
    'universal-access': 'people',
    'universal-access-alt': 'people',
    'unlock': 'unlock',
    'update': 'update',
    'upload': 'upload',
    'vault': 'lock',
    'video-alt': 'video',
    'video-alt2': 'video',
    'video-alt3': 'video',
    'visibility': 'seen',
    'warning': 'warning',
    'welcome-add-page': 'plus',
    'welcome-comments': 'comment',
    'welcome-learn-more': 'info',
    'welcome-view-site': 'external',
    'welcome-widgets-menus': 'widgets',
    'welcome-write-blog': 'edit',
    'wordpress': 'wordpress',
    'wordpress-alt': 'wordpress',
    'yes': 'check',
    'yes-alt': 'check',
};

/**
 * Get block icon from various formats
 */
export function getBlockIcon(icon?: string): IconType {
    if (!icon) {
        return 'block-default';
    }

    // Already a dashicon name (without prefix)
    if (icon in dashiconMapping) {
        const wpIcon = dashiconMapping[icon] as keyof typeof icons;
        if (icons[wpIcon]) {
            return icons[wpIcon] as IconType;
        }
    }

    // Dashicon with prefix
    if (icon.startsWith('dashicons-')) {
        const dashiconName = icon.replace('dashicons-', '');
        if (dashiconName in dashiconMapping) {
            const wpIcon = dashiconMapping[dashiconName] as keyof typeof icons;
            if (icons[wpIcon]) {
                return icons[wpIcon] as IconType;
            }
        }
        // Fall back to dashicon
        return icon;
    }

    // WordPress icon name
    if (icon in icons) {
        return icons[icon as keyof typeof icons] as IconType;
    }

    // SVG string
    if (icon.startsWith('<svg')) {
        return createElement('span', {
            dangerouslySetInnerHTML: { __html: icon },
        });
    }

    // URL to image
    if (icon.startsWith('http') || icon.startsWith('/')) {
        return createElement('img', {
            src: icon,
            alt: '',
            style: { width: '24px', height: '24px' },
        });
    }

    // Default fallback
    return 'block-default';
}

/**
 * Render an icon component
 */
export function renderIcon(icon: IconType, size = 24): JSX.Element {
    if (typeof icon === 'string') {
        // Dashicon
        if (icon.startsWith('dashicons-') || !icon.includes('/')) {
            return createElement(Icon, {
                icon: icon,
                size,
            });
        }
        // URL
        return createElement('img', {
            src: icon,
            alt: '',
            style: { width: `${size}px`, height: `${size}px` },
        });
    }

    // Already a component/element
    if (typeof icon === 'object' && 'src' in icon) {
        return createElement(Icon, { icon: icon.src, size });
    }

    return icon as JSX.Element;
}
