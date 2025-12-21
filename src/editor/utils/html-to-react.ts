/**
 * HTML to React Processor for Proto-Blocks
 *
 * Converts server-rendered HTML into React components with editable fields
 */

import { createElement, Fragment, ReactNode } from '@wordpress/element';
import { RichText } from '@wordpress/block-editor';
import { FieldConfig, BlockAttributes } from '../types';
import { renderField } from '../fields/render';

interface ProcessOptions {
    attributes: BlockAttributes;
    setAttributes: (name: string, value: unknown) => void;
    fields: Record<string, FieldConfig>;
    isSelected?: boolean;
}

interface ParsedElement {
    tagName: string;
    attributes: Record<string, string>;
    children: (ParsedElement | string)[];
    protoField?: string;
    protoRepeater?: string;
    protoRepeaterItem?: string;
    protoInnerBlocks?: boolean;
}

/**
 * Process HTML string to React components
 */
export function processHtmlToReact(
    html: string,
    options: ProcessOptions
): ReactNode {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    // Get all child nodes of body
    const children = Array.from(doc.body.childNodes);

    if (children.length === 0) {
        return null;
    }

    if (children.length === 1) {
        return processNode(children[0], options);
    }

    return createElement(
        Fragment,
        null,
        ...children.map((node, index) => processNode(node, options, index))
    );
}

/**
 * Process a single DOM node
 */
function processNode(
    node: Node,
    options: ProcessOptions,
    key?: number
): ReactNode {
    // Handle text nodes
    if (node.nodeType === Node.TEXT_NODE) {
        return node.textContent;
    }

    // Handle element nodes
    if (node.nodeType === Node.ELEMENT_NODE) {
        const element = node as Element;
        return processElement(element, options, key);
    }

    // Ignore other node types (comments, etc.)
    return null;
}

/**
 * Process an element node
 */
function processElement(
    element: Element,
    options: ProcessOptions,
    key?: number
): ReactNode {
    const tagName = element.tagName.toLowerCase();
    const { attributes, setAttributes, fields, isSelected } = options;

    // Get proto-blocks specific attributes
    const protoField = element.getAttribute('data-proto-field');
    const protoRepeater = element.getAttribute('data-proto-repeater');
    const protoRepeaterItem = element.getAttribute('data-proto-repeater-item');
    const protoInnerBlocks = element.hasAttribute('data-proto-inner-blocks');

    // Handle editable fields
    if (protoField && fields[protoField]) {
        const fieldConfig = fields[protoField];
        const fieldValue = attributes[protoField];

        return renderField({
            name: protoField,
            value: fieldValue,
            onChange: (value: unknown) => setAttributes(protoField, value),
            config: fieldConfig,
            tagName: fieldConfig.tagName || tagName,
            className: getClassName(element),
            isSelected,
            key,
        });
    }

    // Handle repeater fields
    // When block is NOT selected, show the actual preview (so controls like iconPosition are visible)
    // When block IS selected, show the repeater editing UI
    if (protoRepeater && fields[protoRepeater]) {
        if (isSelected) {
            // Show repeater editing UI when selected
            const fieldConfig = fields[protoRepeater];
            const fieldValue = attributes[protoRepeater];

            return renderField({
                name: protoRepeater,
                value: fieldValue,
                onChange: (value: unknown) => setAttributes(protoRepeater, value),
                config: fieldConfig,
                tagName: tagName,
                className: getClassName(element),
                isSelected,
                key,
                element, // Pass original element for repeater template extraction
            });
        }
        // When not selected, fall through to render the preview HTML as-is
    }

    // Handle inner blocks placeholder
    if (protoInnerBlocks) {
        const innerBlocksField = Object.entries(fields).find(
            ([, config]) => config.type === 'inner-blocks'
        );

        if (innerBlocksField) {
            const [name, config] = innerBlocksField;
            return renderField({
                name,
                value: null,
                onChange: () => {},
                config,
                tagName: tagName,
                className: getClassName(element),
                isSelected,
                key,
            });
        }
    }

    // Convert element attributes to React props
    const props = convertAttributes(element, key);

    // Process children
    const children = Array.from(element.childNodes).map((child, index) =>
        processNode(child, options, index)
    );

    // Handle void elements (self-closing)
    if (isVoidElement(tagName)) {
        return createElement(tagName, props);
    }

    return createElement(tagName, props, ...children);
}

/**
 * Convert HTML attributes to React props
 */
function convertAttributes(
    element: Element,
    key?: number
): Record<string, unknown> {
    const props: Record<string, unknown> = {};

    // Add key if provided
    if (key !== undefined) {
        props.key = key;
    }

    // Convert attributes
    Array.from(element.attributes).forEach((attr) => {
        const name = attr.name;
        const value = attr.value;

        // Skip proto-blocks attributes
        if (name.startsWith('data-proto-')) {
            return;
        }

        // Handle special attribute conversions
        switch (name) {
            case 'class':
                props.className = value;
                break;
            case 'for':
                props.htmlFor = value;
                break;
            case 'tabindex':
                props.tabIndex = parseInt(value, 10);
                break;
            case 'readonly':
                props.readOnly = value === '' || value === 'true';
                break;
            case 'colspan':
                props.colSpan = parseInt(value, 10);
                break;
            case 'rowspan':
                props.rowSpan = parseInt(value, 10);
                break;
            case 'maxlength':
                props.maxLength = parseInt(value, 10);
                break;
            case 'minlength':
                props.minLength = parseInt(value, 10);
                break;
            case 'autocomplete':
                props.autoComplete = value;
                break;
            case 'autofocus':
                props.autoFocus = value === '' || value === 'true';
                break;
            default:
                // Handle data-* attributes (keep as is)
                if (name.startsWith('data-')) {
                    props[name] = value;
                }
                // Handle aria-* attributes (keep as is)
                else if (name.startsWith('aria-')) {
                    props[name] = value;
                }
                // Handle style attribute
                else if (name === 'style') {
                    props.style = parseInlineStyles(value);
                }
                // Handle boolean attributes
                else if (isBooleanAttribute(name)) {
                    props[name] = value === '' || value === 'true' || value === name;
                }
                // Default: use as-is
                else {
                    props[name] = value;
                }
        }
    });

    return props;
}

/**
 * Parse inline styles to React style object
 */
function parseInlineStyles(styleString: string): Record<string, string> {
    const styles: Record<string, string> = {};

    styleString.split(';').forEach((declaration) => {
        const [property, value] = declaration.split(':').map((s) => s.trim());
        if (property && value) {
            // Convert kebab-case to camelCase
            const camelProperty = property.replace(/-([a-z])/g, (_, letter) =>
                letter.toUpperCase()
            );
            styles[camelProperty] = value;
        }
    });

    return styles;
}

/**
 * Get className from element
 */
function getClassName(element: Element): string {
    return element.getAttribute('class') || '';
}

/**
 * Check if element is a void element (self-closing)
 */
function isVoidElement(tagName: string): boolean {
    const voidElements = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];
    return voidElements.includes(tagName);
}

/**
 * Check if attribute is a boolean attribute
 */
function isBooleanAttribute(name: string): boolean {
    const booleanAttributes = [
        'allowfullscreen',
        'async',
        'autofocus',
        'autoplay',
        'checked',
        'controls',
        'default',
        'defer',
        'disabled',
        'formnovalidate',
        'hidden',
        'ismap',
        'loop',
        'multiple',
        'muted',
        'novalidate',
        'open',
        'playsinline',
        'readonly',
        'required',
        'reversed',
        'selected',
    ];
    return booleanAttributes.includes(name);
}
