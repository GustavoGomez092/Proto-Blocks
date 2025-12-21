/**
 * TypeScript type definitions for Proto-Blocks
 */

// Block field configuration
export interface FieldConfig {
    type: string;
    tagName?: string;
    default?: unknown;
    className?: string;
    required?: boolean;
    maxLength?: number;
    fields?: Record<string, FieldConfig>; // For repeaters
}

// Block control configuration
export interface ControlConfig {
    type: string;
    label: string;
    default?: unknown;
    options?: Array<{ key: string; label: string }>;
    min?: number;
    max?: number;
    step?: number;
    conditions?: {
        visible?: Record<string, unknown>;
        enabled?: Record<string, unknown>;
    };
    affects?: string[];
}

// Block supports configuration
export interface BlockSupports {
    html?: boolean;
    align?: boolean | string[];
    alignWide?: boolean;
    anchor?: boolean;
    className?: boolean;
    customClassName?: boolean;
    color?: boolean | {
        text?: boolean;
        background?: boolean;
        gradient?: boolean;
        link?: boolean;
    };
    typography?: boolean | {
        fontSize?: boolean;
        lineHeight?: boolean;
    };
    spacing?: boolean | {
        margin?: boolean;
        padding?: boolean;
    };
    defaultAlign?: string;
}

// Block metadata from JSON
export interface BlockMetadata {
    name?: string;
    title?: string;
    description?: string;
    category?: string;
    icon?: string;
    keywords?: string[];
    supports?: BlockSupports;
    protoBlocks?: {
        version?: string;
        template?: string;
        templatePath?: string;
        fields?: Record<string, FieldConfig>;
        controls?: Record<string, ControlConfig>;
        interactivity?: {
            store?: string;
            viewScriptModule?: string;
        };
    };
}

// Block data passed from PHP
export interface BlockData {
    name: string;
    title: string;
    description: string;
    category: string;
    icon: string;
    keywords: string[];
    supports: BlockSupports;
    fields: Record<string, FieldConfig>;
    controls: Record<string, ControlConfig>;
    attributes: Record<string, AttributeConfig>;
    previewImage?: string | null;
    metadata: BlockMetadata;
}

// WordPress attribute configuration
export interface AttributeConfig {
    type: string;
    default?: unknown;
    __protoType?: string;
    __protoConfig?: FieldConfig;
    __protoControl?: boolean;
    __protoControlConfig?: ControlConfig;
}

// Block attributes (runtime values)
export type BlockAttributes = Record<string, unknown>;

// Repeater item
export interface RepeaterItem {
    id: string;
    [key: string]: unknown;
}

// Image value
export interface ImageValue {
    id?: number | null;
    url: string;
    alt?: string;
    caption?: string;
    size?: string;
}

// Link value
export interface LinkValue {
    url: string;
    text?: string;
    target?: string;
    rel?: string;
    title?: string;
}

// Field component props
export interface FieldProps<T = unknown> {
    name: string;
    value: T;
    onChange: (value: T) => void;
    config: FieldConfig;
    tagName?: string;
    className?: string;
    isSelected?: boolean;
}

// Control component props
export interface ControlProps<T = unknown> {
    name: string;
    value: T;
    onChange: (value: T) => void;
    config: ControlConfig;
}

// Block edit component props
export interface BlockEditProps {
    attributes: BlockAttributes;
    setAttributes: (attrs: Partial<BlockAttributes>) => void;
    isSelected: boolean;
    clientId: string;
    className?: string;
}

// Global data from PHP
export interface ProtoBlocksData {
    blocks: BlockData[];
    fieldTypes: Record<string, unknown>;
    controlTypes: Record<string, unknown>;
    ajaxUrl: string;
    previewNonce: string;
    restNonce: string;
    debug: boolean;
    version: string;
}

// Declare global window augmentation
declare global {
    interface Window {
        protoBlocksData: ProtoBlocksData;
        ajaxurl: string;
    }
}
