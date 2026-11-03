/**
 * The field-group builder's reusable pieces (Fields screen; options pages
 * compose the same parts). Each is controlled: state lives in the caller as a
 * RAW group object changed only through `reduce(group, action)`, and the
 * file text follows with `toFileText(text, group, pointer)`.
 *
 * FieldTree — the nested, reorderable field list.
 *   Props: fields (top-level raw fields), selectedKey, onSelect(path, key),
 *   dispatch(action) (move / duplicate / remove / addLayout / removeLayout),
 *   onAddField(parent | null, layout?), readOnly?, renderPanel?(field, path, key)
 *   (the open row's settings, shown inline under it as an accordion).
 *   Pointer drag via dnd-kit; keyboard: focus a grip, Space, arrows
 *   (Right into the repeater/group above, Left out of the parent), Space.
 *
 * FieldSettings — the selected field's settings form (react-hook-form + zod).
 *   Props: field, siblings (the other fields at its level), savedName? (its
 *   name in the saved file: renaming shows the meta key warning), choices?
 *   ({postTypes, taxonomies} from GET admin/fields/<key>), onChange(patch)
 *   (debounced 150 ms; undefined removes a key), onLayout?(action),
 *   readOnly?, idPrefix? (control ids are `<idPrefix>-<setting>`).
 *   Key it by field key so it remounts per selection.
 *
 * AddFieldMenu — the type picker dialog.
 *   Props: open, onOpenChange(open), onPick(type), target? (footer text, a node).
 *   Lists TYPE_META (every type the server accepts) as tiles by category; the
 *   search also matches the ACF aliases.
 *
 * ConditionBuilder — "Show only when…" rules.
 *   Props: fields (the edited field's siblings), selfKey?, value (raw
 *   conditional_logic), onChange(rules | 0), readOnly?. Emits only
 *   `{field, operator, value}` / `{field, operator}` for the empty operators,
 *   with operators the condition engine evaluates.
 *
 * LocationBuilder — "Show this field group if…" rules.
 *   Props: value (LocationRule[][]), choices (LocationChoices: the GET
 *   payload's postTypes, taxonomies, roles, optionsPages, pageTemplates),
 *   onChange(rules), implied? (label when the file it lives in sets the
 *   location: no rows then), readOnly?. Offers only the nine params
 *   LocationMatcher evaluates; an unsupported one in the file is a locked row.
 *
 * GroupSettings — position, style, menu order, options autoload (the title is
 *   edited in the Builder header).
 *   Props: group (raw), dispatch(action) (emits `{type: 'group', patch}`), readOnly?.
 *
 * JsoncPane — the whole file as editable text (no code-editor dependency).
 *   Props: text, pointer, kind, index?, readOnly?, onChange(text). Parse
 *   errors show as "Line l, column c"; POST admin/fields/validate runs
 *   debounced 400 ms. `jsoncErrors(text, pointer)` is the same check.
 *
 * Builder — the whole Fields-screen builder for one group key (or a new group).
 */
export { FieldTree, rowId, type FieldTreeProps } from '@/admin/screens/fields/FieldTree';
export { FieldSettings, type FieldSettingsProps, type Choice } from '@/admin/screens/fields/FieldSettings';
export { AddFieldMenu, type AddFieldMenuProps } from '@/admin/screens/fields/AddFieldMenu';
export { ConditionBuilder, type ConditionBuilderProps } from '@/admin/screens/fields/ConditionBuilder';
export { LocationBuilder, readLocation, type LocationBuilderProps, type LocationChoices, type LocationRule } from '@/admin/screens/fields/LocationBuilder';
export { GroupSettings, type GroupSettingsProps } from '@/admin/screens/fields/GroupSettings';
export { JsoncPane, jsoncErrors, type JsoncPaneProps, type JsoncProblem } from '@/admin/screens/fields/JsoncPane';
export { Builder, type BuilderProps, type FieldGroupDetail } from '@/admin/screens/fields/Builder';
export {
	reduce,
	reduceWithNotices,
	toFileText,
	validateGroup,
	newFieldKey,
	slugifyName,
	type BuilderAction,
	type FieldPath,
	type RawField,
	type RawGroup,
} from '@/admin/screens/fields/builder-state';
export { COMMON_SETTINGS, TYPE_SETTINGS, TYPE_META, settingsFor, type SettingSpec } from '@/admin/screens/fields/field-settings-spec';
